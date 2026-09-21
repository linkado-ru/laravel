<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Linkado\Laravel\Actions\RecordLinkadoEvent;
use Linkado\Laravel\Enums\DeliveryMode;
use Linkado\Laravel\Enums\OutboxStatus;
use Linkado\Laravel\Events\OutboxPayloadConflictDetected;
use Linkado\Laravel\Exceptions\ActiveTransactionRequired;
use Linkado\Laravel\Facades\Linkado;
use Linkado\Laravel\Models\OutboxEvent;
use Linkado\Laravel\Support\Events\EventPayloadCodec;
use Linkado\Laravel\Tests\Support\DatabaseConfiguration;
use Linkado\PhpSdk\DataObjects\CustomerCreatedEventData;
use Linkado\PhpSdk\DataObjects\EventData;
use Linkado\PhpSdk\LinkadoConnector;

beforeEach(function (): void {
    config()->set('database.connections.host_test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('database.connections.linkado_test', DatabaseConfiguration::externalOrSqlite());
    config()->set('database.default', 'host_test');
    config()->set('linkado.connection', 'linkado_test');
    config()->set('linkado.mode', DeliveryMode::Live->value);
    config()->set('linkado.features.customer_events', true);

    DB::purge('host_test');
    DB::purge('linkado_test');

    p7OutboxMigration()->up();
});

afterEach(function (): void {
    $connection = p7Connection();

    while ($connection->transactionLevel() > 0) {
        $connection->rollBack();
    }

    if (Schema::connection('linkado_test')->hasTable('linkado_outbox_events')) {
        p7OutboxMigration()->down();
    }

    DB::purge('host_test');
    DB::purge('linkado_test');
});

it('inserts the first event on the configured connection without resolving the SDK connector', function (): void {
    $connection = p7Connection();
    $connection->beginTransaction();
    $factoryCalls = 0;
    $generatedEventId = null;

    $recorded = p7Recorder()->handle('customer:42', function (string $eventId) use (&$factoryCalls, &$generatedEventId): EventData {
        $factoryCalls++;
        $generatedEventId = $eventId;

        return p7CustomerCreated($eventId);
    });

    expect($recorded)->toBeInstanceOf(OutboxEvent::class)
        ->and($recorded?->getKey())->toBeInt()
        ->and($factoryCalls)->toBe(1)
        ->and($generatedEventId)->toBeString()
        ->and(Str::isUlid((string) $generatedEventId))->toBeTrue()
        ->and($recorded?->event_id)->toBe($generatedEventId)
        ->and($recorded?->source_key)->toBe('customer:42')
        ->and($recorded?->delivery_mode)->toBe(DeliveryMode::Live)
        ->and($recorded?->status)->toBe(OutboxStatus::Pending)
        ->and($recorded?->attempt_count)->toBe(0)
        ->and($recorded?->payload_sha256)->toBe(hash('sha256', (string) $recorded?->payload))
        ->and($connection->transactionLevel())->toBe(1)
        ->and(Schema::connection('host_test')->hasTable('linkado_outbox_events'))->toBeFalse()
        ->and(app()->resolved(LinkadoConnector::class))->toBeFalse();
});

it('exposes recording through the public facade', function (): void {
    p7Connection()->beginTransaction();

    $recorded = Linkado::record(
        'customer:facade',
        fn (string $eventId): EventData => p7CustomerCreated($eventId, 'customer-facade'),
    );

    expect($recorded)->toBeInstanceOf(OutboxEvent::class)
        ->and($recorded?->source_key)->toBe('customer:facade');
});

it('rejects recording outside the configured transaction', function (): void {
    expect(fn (): ?OutboxEvent => p7Recorder()->handle(
        'customer:no-transaction',
        fn (string $eventId): EventData => p7CustomerCreated($eventId),
    ))->toThrow(ActiveTransactionRequired::class);

    expect(OutboxEvent::query()->count())->toBe(0);
});

it('rejects an event whose ID does not match the factory argument', function (): void {
    $connection = p7Connection();
    $connection->beginTransaction();

    expect(fn (): ?OutboxEvent => p7Recorder()->handle(
        'customer:mismatch',
        fn (string $eventId): EventData => p7CustomerCreated(strtolower((string) Str::ulid())),
    ))->toThrow(InvalidArgumentException::class, 'The Linkado event factory must use the provided event ID.');

    expect(OutboxEvent::query()->count())->toBe(0)
        ->and($connection->transactionLevel())->toBe(1);
});

it('returns the original row for an identical duplicate source and payload', function (): void {
    p7Connection()->beginTransaction();
    $factoryEventIds = [];
    $factory = function (string $eventId) use (&$factoryEventIds): EventData {
        $factoryEventIds[] = $eventId;

        return p7CustomerCreated($eventId);
    };

    $first = p7Recorder()->handle('customer:duplicate', $factory);
    $second = p7Recorder()->handle('customer:duplicate', $factory);

    expect($factoryEventIds)->toHaveCount(2)
        ->and($factoryEventIds[1])->toBe($factoryEventIds[0])
        ->and($second?->is($first))->toBeTrue()
        ->and(OutboxEvent::query()->count())->toBe(1);
});

it('dispatches a conflict and preserves the original row for a conflicting duplicate', function (): void {
    Bus::fake();
    Event::fake([OutboxPayloadConflictDetected::class]);
    p7Connection()->beginTransaction();

    $first = p7Recorder()->handle(
        'customer:conflict',
        fn (string $eventId): EventData => p7CustomerCreated($eventId, 'customer-original'),
    );
    $originalPayload = $first?->payload;
    $duplicate = p7Recorder()->handle(
        'customer:conflict',
        fn (string $eventId): EventData => p7CustomerCreated($eventId, 'customer-conflicting'),
    );

    expect($duplicate?->is($first))->toBeTrue()
        ->and($duplicate?->payload)->toBe($originalPayload)
        ->and(OutboxEvent::query()->count())->toBe(1);

    Event::assertNotDispatched(OutboxPayloadConflictDetected::class);
    p7Connection()->commit();

    Event::assertDispatched(
        OutboxPayloadConflictDetected::class,
        fn (OutboxPayloadConflictDetected $event): bool => $event->sourceKey === 'customer:conflict'
            && $event->existingEventId === $first?->event_id
            && $event->attemptedEventId === $first?->event_id,
    );
});

it('removes the recorded row when the host transaction rolls back', function (): void {
    $connection = p7Connection();
    $connection->beginTransaction();

    p7Recorder()->handle(
        'customer:rollback',
        fn (string $eventId): EventData => p7CustomerCreated($eventId),
    );

    expect(OutboxEvent::query()->count())->toBe(1);

    $connection->rollBack();

    expect(OutboxEvent::query()->count())->toBe(0)
        ->and($connection->transactionLevel())->toBe(0);
});

it('stores Unicode and slashes as exact canonical payload bytes', function (): void {
    p7Connection()->beginTransaction();
    $eventId = null;

    $recorded = p7Recorder()->handle('customer:unicode', function (string $generatedEventId) use (&$eventId): EventData {
        $eventId = $generatedEventId;

        return new CustomerCreatedEventData(
            event_id: $generatedEventId,
            program_key: 'program/рус',
            occurred_at: '2026-09-21T10:11:12+03:00',
            external_customer_id: 'клиент/42',
            click_id: 'click/ёж',
        );
    });
    $expected = sprintf(
        '{"event_id":"%s","type":"customer_created","program_key":"program/рус","occurred_at":"2026-09-21T10:11:12+03:00","external_customer_id":"клиент/42","click_id":"click/ёж"}',
        $eventId,
    );

    expect($recorded?->payload)->toBe($expected)
        ->and($recorded?->payload_sha256)->toBe(hash('sha256', $expected));
});

it('converges on the winning row after a simulated unique-key race', function (): void {
    Event::fake([OutboxPayloadConflictDetected::class]);
    $connection = p7Connection();
    $connection->beginTransaction();
    $winningEventId = null;

    $recorded = p7Recorder()->handle('customer:race', function (string $candidateEventId) use ($connection, &$winningEventId): EventData {
        do {
            $winningEventId = strtolower((string) Str::ulid());
        } while ($winningEventId === $candidateEventId);

        $winningEvent = p7CustomerCreated($winningEventId, 'customer-race');
        $payload = app(EventPayloadCodec::class)->encode($winningEvent);

        $connection->table('linkado_outbox_events')->insert([
            'event_id' => $winningEventId,
            'source_key' => 'customer:race',
            'event_type' => $payload->type->value,
            'delivery_mode' => DeliveryMode::Live->value,
            'status' => OutboxStatus::Pending->value,
            'payload' => $payload->payload,
            'payload_sha256' => $payload->sha256,
            'attempt_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return p7CustomerCreated($candidateEventId, 'customer-race');
    });

    expect($recorded?->event_id)->toBe($winningEventId)
        ->and($recorded?->source_key)->toBe('customer:race')
        ->and(OutboxEvent::query()->count())->toBe(1)
        ->and($connection->transactionLevel())->toBe(1);

    Event::assertNotDispatched(OutboxPayloadConflictDetected::class);
});

function p7Recorder(): RecordLinkadoEvent
{
    return app(RecordLinkadoEvent::class);
}

function p7Connection(): Connection
{
    return DB::connection('linkado_test');
}

function p7OutboxMigration(): Migration
{
    return require __DIR__.'/../../../database/migrations/2026_01_01_000000_create_linkado_outbox_events_table.php';
}

function p7CustomerCreated(string $eventId, string $externalCustomerId = 'customer-42'): CustomerCreatedEventData
{
    return new CustomerCreatedEventData(
        event_id: $eventId,
        program_key: 'program-key',
        occurred_at: '2026-09-21T10:11:12Z',
        external_customer_id: $externalCustomerId,
    );
}

it('reloads a committed concurrent winner outside the original read snapshot', function (bool $conflicting): void {
    $connection = p7Connection();

    if ($connection->getDriverName() === 'sqlite') {
        $this->markTestSkipped('Requires a server database with independent transaction snapshots.');
    }

    Bus::fake();
    Event::fake([OutboxPayloadConflictDetected::class]);
    config()->set('database.connections.linkado_race_winner', config('database.connections.linkado_test'));
    $winner = DB::connection('linkado_race_winner');
    $winningEventId = strtolower((string) Str::ulid());
    $winningPayload = app(EventPayloadCodec::class)->encode(p7CustomerCreated($winningEventId));
    $connection->beginTransaction();

    try {
        $recorded = p7Recorder()->handle('customer:concurrent', function (string $eventId) use ($winner, $winningEventId, $winningPayload, $conflicting): EventData {
            $winner->table('linkado_outbox_events')->insert([
                'event_id' => $winningEventId,
                'source_key' => 'customer:concurrent',
                'event_type' => 'customer_created',
                'delivery_mode' => 'live',
                'status' => 'pending',
                'payload' => $winningPayload->payload,
                'payload_sha256' => $winningPayload->sha256,
                'attempt_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return p7CustomerCreated($eventId, $conflicting ? 'different-customer' : 'customer-42');
        });
        expect($recorded?->event_id)->toBe($winningEventId)
            ->and($recorded?->payload)->toBe($winningPayload->payload)
            ->and($recorded?->payload_sha256)->toBe($winningPayload->sha256)
            ->and($connection->transactionLevel())->toBe(1);
        Event::assertNotDispatched(OutboxPayloadConflictDetected::class);
        $connection->commit();
        Event::assertDispatchedTimes(OutboxPayloadConflictDetected::class, $conflicting ? 1 : 0);
    } finally {
        DB::purge('linkado_race_winner');
    }
})->with([false, true]);
