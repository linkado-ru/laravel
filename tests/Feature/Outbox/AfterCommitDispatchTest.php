<?php

declare(strict_types=1);

use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Linkado\Laravel\Actions\RecordLinkadoEvent;
use Linkado\Laravel\Enums\DeliveryMode;
use Linkado\Laravel\Enums\OutboxStatus;
use Linkado\Laravel\Events\OutboxDispatchFailed;
use Linkado\Laravel\Events\OutboxEventRecorded;
use Linkado\Laravel\Jobs\DeliverOutboxEvent;
use Linkado\Laravel\Models\OutboxEvent;
use Linkado\PhpSdk\DataObjects\CustomerCreatedEventData;
use Mockery\MockInterface;

beforeEach(function (): void {
    config()->set('database.connections.linkado_dispatch_test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('database.default', 'linkado_dispatch_test');
    config()->set('cache.default', 'array');
    config()->set('linkado.connection', 'linkado_dispatch_test');
    config()->set('linkado.mode', DeliveryMode::Live->value);
    config()->set('linkado.queue');
    config()->set('linkado.features.customer_events', true);

    DB::purge('linkado_dispatch_test');

    p9OutboxMigration()->up();
});

afterEach(function (): void {
    $connection = p9Connection();

    while ($connection->transactionLevel() > 0) {
        $connection->rollBack();
    }

    if (Schema::connection('linkado_dispatch_test')->hasTable('linkado_outbox_events')) {
        p9OutboxMigration()->down();
    }

    DB::purge('linkado_dispatch_test');
});

it('dispatches one delivery job only after the outer transaction commits', function (): void {
    Bus::fake([DeliverOutboxEvent::class]);
    Event::fake([OutboxEventRecorded::class]);
    $connection = p9Connection();
    $connection->beginTransaction();

    $recorded = p9Recorder()->handle(
        'customer:after-commit',
        fn (string $eventId): CustomerCreatedEventData => p9CustomerCreated($eventId),
    );

    expect($recorded)->toBeInstanceOf(OutboxEvent::class)
        ->and($connection->transactionLevel())->toBe(1);
    Bus::assertNothingDispatched();
    Event::assertNotDispatched(OutboxEventRecorded::class);

    $connection->commit();

    Bus::assertDispatchedTimes(DeliverOutboxEvent::class, 1);
    Bus::assertDispatched(
        DeliverOutboxEvent::class,
        fn (DeliverOutboxEvent $job): bool => $job->eventId === $recorded?->event_id,
    );
    Event::assertDispatched(OutboxEventRecorded::class, function (OutboxEventRecorded $event) use ($recorded): bool {
        expect(array_keys(get_object_vars($event)))->toBe(['eventId', 'sourceKey', 'status'])
            ->and($event->eventId)->toBe($recorded?->event_id)
            ->and($event->sourceKey)->toBe('customer:after-commit')
            ->and($event->status)->toBe(OutboxStatus::Pending);

        return true;
    });
});

it('dispatches neither a job nor a recorded event when the outer transaction rolls back', function (): void {
    Bus::fake([DeliverOutboxEvent::class]);
    Event::fake([OutboxEventRecorded::class]);
    $connection = p9Connection();
    $connection->beginTransaction();

    p9Recorder()->handle(
        'customer:rollback',
        fn (string $eventId): CustomerCreatedEventData => p9CustomerCreated($eventId),
    );

    $connection->rollBack();

    Bus::assertNothingDispatched();
    Event::assertNotDispatched(OutboxEventRecorded::class);
    expect(OutboxEvent::query()->count())->toBe(0);
});

it('does not dispatch delivery for off or shadow mode', function (DeliveryMode $mode, int $expectedRows): void {
    Bus::fake([DeliverOutboxEvent::class]);
    config()->set('linkado.mode', $mode->value);
    $connection = p9Connection();
    $connection->beginTransaction();

    p9Recorder()->handle(
        'customer:'.$mode->value,
        fn (string $eventId): CustomerCreatedEventData => p9CustomerCreated($eventId),
    );

    $connection->commit();

    Bus::assertNothingDispatched();
    expect(OutboxEvent::query()->count())->toBe($expectedRows);
})->with([
    'off' => [DeliveryMode::Off, 0],
    'shadow' => [DeliveryMode::Shadow, 1],
]);

it('uses the consuming applications default queue when no queue is configured', function (): void {
    Bus::fake([DeliverOutboxEvent::class]);
    $connection = p9Connection();
    $connection->beginTransaction();

    $recorded = p9Recorder()->handle(
        'customer:default-queue',
        fn (string $eventId): CustomerCreatedEventData => p9CustomerCreated($eventId),
    );

    $connection->commit();

    Bus::assertDispatched(
        DeliverOutboxEvent::class,
        fn (DeliverOutboxEvent $job): bool => $job->eventId === $recorded?->event_id
            && $job->queue === null,
    );
});

it('uses the configured queue', function (): void {
    Bus::fake([DeliverOutboxEvent::class]);
    config()->set('linkado.queue', 'linkado-delivery');
    $connection = p9Connection();
    $connection->beginTransaction();

    $recorded = p9Recorder()->handle(
        'customer:configured-queue',
        fn (string $eventId): CustomerCreatedEventData => p9CustomerCreated($eventId),
    );

    $connection->commit();

    Bus::assertDispatched(
        DeliverOutboxEvent::class,
        fn (DeliverOutboxEvent $job): bool => $job->eventId === $recorded?->event_id
            && $job->queue === 'linkado-delivery',
    );
});

it('keeps the committed row pending and emits a sanitized event when queue dispatch fails', function (): void {
    Event::fake([OutboxEventRecorded::class, OutboxDispatchFailed::class]);
    $dispatcher = Mockery::mock(BusDispatcher::class, function (MockInterface $mock): void {
        $mock->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(DeliverOutboxEvent::class))
            ->andThrow(new RuntimeException('secret queue endpoint must not leak'));
    });
    app()->instance(BusDispatcher::class, $dispatcher);
    $connection = p9Connection();
    $connection->beginTransaction();

    $recorded = p9Recorder()->handle(
        'customer:sensitive-source-key',
        fn (string $eventId): CustomerCreatedEventData => p9CustomerCreated($eventId, 'sensitive-customer-id'),
    );

    expect(fn () => $connection->commit())->not->toThrow(Throwable::class);

    $stored = OutboxEvent::query()->sole();

    expect($stored->status)->toBe(OutboxStatus::Pending)
        ->and($stored->event_id)->toBe($recorded?->event_id)
        ->and($stored->attempt_count)->toBe(0);
    Event::assertDispatched(OutboxDispatchFailed::class, function (OutboxDispatchFailed $event) use ($stored): bool {
        $properties = get_object_vars($event);
        $encoded = json_encode($properties, JSON_THROW_ON_ERROR);

        expect(array_keys($properties))->toBe(['eventId', 'exceptionClass'])
            ->and($event->eventId)->toBe($stored->event_id)
            ->and($event->exceptionClass)->toBe(RuntimeException::class)
            ->and($encoded)->not->toContain('secret queue endpoint')
            ->and($encoded)->not->toContain('sensitive-source-key')
            ->and($encoded)->not->toContain('sensitive-customer-id');

        return true;
    });

    $retryJob = new DeliverOutboxEvent((string) $stored->event_id);
    $lock = new UniqueLock(app(CacheRepository::class));

    expect($lock->acquire($retryJob))->toBeTrue();
    $lock->release($retryJob);
});

it('is unique by event ID only until processing begins', function (): void {
    $eventId = '01k5n6h4yrpjs4wpk6f7n4kw0a';
    $job = new DeliverOutboxEvent($eventId);

    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($job->eventId)->toBe($eventId)
        ->and($job->uniqueId())->toBe($eventId);
});

function p9Recorder(): RecordLinkadoEvent
{
    return app(RecordLinkadoEvent::class);
}

function p9Connection(): Connection
{
    return DB::connection('linkado_dispatch_test');
}

function p9OutboxMigration(): Migration
{
    return require __DIR__.'/../../../database/migrations/2026_01_01_000000_create_linkado_outbox_events_table.php';
}

function p9CustomerCreated(string $eventId, string $externalCustomerId = 'customer-42'): CustomerCreatedEventData
{
    return new CustomerCreatedEventData(
        event_id: $eventId,
        program_key: 'program-key',
        occurred_at: '2026-09-21T10:11:12Z',
        external_customer_id: $externalCustomerId,
    );
}
