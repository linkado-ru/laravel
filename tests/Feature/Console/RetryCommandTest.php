<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Linkado\Laravel\Enums\AttemptOutcome;
use Linkado\Laravel\Enums\DeliveryMode;
use Linkado\Laravel\Enums\OutboxStatus;
use Linkado\Laravel\Jobs\DeliverOutboxEvent;
use Linkado\Laravel\Models\OutboxAttempt;
use Linkado\Laravel\Models\OutboxEvent;
use Linkado\Laravel\Support\Events\EventPayloadCodec;
use Linkado\PhpSdk\DataObjects\CustomerCreatedEventData;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-21 12:00:00 UTC');

    config()->set('database.connections.linkado_retry_command_test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('database.default', 'linkado_retry_command_test');
    config()->set('cache.default', 'array');
    config()->set('linkado.connection', 'linkado_retry_command_test');
    config()->set('linkado.mode', DeliveryMode::Live->value);
    config()->set('linkado.queue', 'linkado-manual');

    DB::purge('linkado_retry_command_test');

    p17RetryOutboxMigration()->up();
    p17RetryAttemptMigration()->up();
});

afterEach(function (): void {
    $connection = p17RetryConnection();

    while ($connection->transactionLevel() > 0) {
        $connection->rollBack();
    }

    if (Schema::connection('linkado_retry_command_test')->hasTable('linkado_outbox_attempts')) {
        p17RetryAttemptMigration()->down();
    }

    if (Schema::connection('linkado_retry_command_test')->hasTable('linkado_outbox_events')) {
        p17RetryOutboxMigration()->down();
    }

    DB::purge('linkado_retry_command_test');
    CarbonImmutable::setTestNow();
});

it('retries a failed event with a sanitized audit attempt and table output', function (): void {
    Bus::fake([DeliverOutboxEvent::class]);
    $event = p17RetryEvent();
    p17PreviousAttempt($event);
    $immutable = [$event->getKey(), $event->event_id, $event->payload, $event->payload_sha256];

    $this->artisan('linkado:retry', [
        'event' => $event->event_id,
        '--operator' => "  ops\nteam  ",
        '--reason' => '  Retry <b>after</b> outage.  ',
    ])
        ->expectsTable(['Event', 'Result'], [[$event->event_id, 'retried']])
        ->assertSuccessful();

    $stored = $event->fresh();
    $attempts = OutboxAttempt::query()->orderBy('number')->get();

    expect([$stored?->getKey(), $stored?->event_id, $stored?->payload, $stored?->payload_sha256])
        ->toBe($immutable)
        ->and($stored?->status)->toBe(OutboxStatus::Pending)
        ->and($stored?->attempt_count)->toBe(2)
        ->and($stored?->next_attempt_at?->equalTo(now()))->toBeTrue()
        ->and($stored?->claimed_at)->toBeNull()
        ->and($stored?->claim_token)->toBeNull()
        ->and($stored?->terminal_at)->toBeNull()
        ->and($attempts)->toHaveCount(2)
        ->and($attempts[1]->number)->toBe(2)
        ->and($attempts[1]->outcome)->toBe(AttemptOutcome::ManuallyRetried)
        ->and($attempts[1]->getAttribute('error_code'))->toBe('manual_retry')
        ->and(json_decode((string) $attempts[1]->getAttribute('error_message'), true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['operator' => 'ops team', 'reason' => 'Retry after outage.'])
        ->and($attempts[1]->started_at->equalTo(now()))->toBeTrue()
        ->and($attempts[1]->finished_at?->equalTo(now()))->toBeTrue()
        ->and($stored?->payload)->not->toContain('ops team')
        ->and($stored?->payload)->not->toContain('Retry after outage.');
    Bus::assertDispatched(
        DeliverOutboxEvent::class,
        fn (DeliverOutboxEvent $job): bool => $job->eventId === $event->event_id
            && $job->queue === 'linkado-manual',
    );
});

it('reports a stable JSON retry result', function (): void {
    Bus::fake([DeliverOutboxEvent::class]);
    $event = p17RetryEvent(['source_key' => 'retry:json']);

    $this->artisan('linkado:retry', [
        'event' => $event->event_id,
        '--operator' => 'operator-42',
        '--reason' => 'Service restored',
        '--json' => true,
    ])
        ->expectsOutput(json_encode([
            'event_id' => $event->event_id,
            'result' => 'retried',
        ], JSON_THROW_ON_ERROR))
        ->assertSuccessful();
});

it('requires non-empty operator and reason options', function (array $options, string $message): void {
    $event = p17RetryEvent();

    $this->artisan('linkado:retry', ['event' => $event->event_id, ...$options])
        ->expectsOutput($message)
        ->assertFailed();

    expect($event->fresh()?->status)->toBe(OutboxStatus::Failed)
        ->and(OutboxAttempt::query()->count())->toBe(0);
})->with([
    'missing operator' => [['--reason' => 'retry'], 'The --operator option must be a non-empty value.'],
    'blank operator' => [['--operator' => " \n ", '--reason' => 'retry'], 'The --operator option must be a non-empty value.'],
    'missing reason' => [['--operator' => 'ops'], 'The --reason option must be a non-empty value.'],
    'blank reason' => [['--operator' => 'ops', '--reason' => " \t "], 'The --reason option must be a non-empty value.'],
]);

it('translates validation errors for Russian operators', function (): void {
    $event = p17RetryEvent();
    app()->setLocale('ru');

    $this->artisan('linkado:retry', [
        'event' => $event->event_id,
        '--reason' => 'повторить',
    ])
        ->expectsOutput('Параметр --operator не должен быть пустым.')
        ->assertFailed();
});

it('refuses protected event states without changing the row', function (
    DeliveryMode $deliveryMode,
    OutboxStatus $status,
    ?string $errorCode,
    bool $corrupt,
    string $reason,
): void {
    Bus::fake([DeliverOutboxEvent::class]);
    $event = p17RetryEvent([
        'source_key' => 'retry:'.$reason,
        'delivery_mode' => $deliveryMode,
        'status' => $status,
        'last_error_code' => $errorCode,
        'terminal_at' => now(),
    ]);

    if ($corrupt) {
        $event->setRawAttributes([
            ...$event->getAttributes(),
            'payload_sha256' => str_repeat('0', 64),
        ]);
        $event->saveQuietly();
    }

    $before = $event->fresh()?->getAttributes();

    $this->artisan('linkado:retry', [
        'event' => $event->event_id,
        '--operator' => 'operator-42',
        '--reason' => 'manual recovery',
        '--json' => true,
    ])
        ->expectsOutput(json_encode([
            'event_id' => $event->event_id,
            'result' => 'refused',
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR))
        ->assertFailed();

    expect($event->fresh()?->getAttributes())->toBe($before)
        ->and(OutboxAttempt::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
})->with([
    'shadow' => [DeliveryMode::Shadow, OutboxStatus::Shadow, null, false, 'shadow'],
    'delivered' => [DeliveryMode::Live, OutboxStatus::Delivered, null, false, 'delivered'],
    'corrupt payload' => [DeliveryMode::Live, OutboxStatus::Failed, 'payload_corrupt', true, 'corrupt'],
    'HTTP conflict' => [DeliveryMode::Live, OutboxStatus::Failed, 'http_409', false, 'conflict'],
]);

it('refuses manual retry while package mode is off', function (): void {
    Bus::fake([DeliverOutboxEvent::class]);
    $event = p17RetryEvent();
    config()->set('linkado.mode', DeliveryMode::Off->value);

    $this->artisan('linkado:retry', [
        'event' => $event->event_id,
        '--operator' => 'operator-42',
        '--reason' => 'manual recovery',
        '--json' => true,
    ])
        ->expectsOutput(json_encode([
            'event_id' => $event->event_id,
            'result' => 'refused',
            'reason' => 'mode_off',
        ], JSON_THROW_ON_ERROR))
        ->assertFailed();

    expect($event->fresh()?->status)->toBe(OutboxStatus::Failed)
        ->and(OutboxAttempt::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
});

it('allows only one manual retry transition for a failed row', function (): void {
    Bus::fake([DeliverOutboxEvent::class]);
    $event = p17RetryEvent();

    $this->artisan('linkado:retry', [
        'event' => $event->event_id,
        '--operator' => 'first',
        '--reason' => 'first recovery',
    ])->assertSuccessful();

    $this->artisan('linkado:retry', [
        'event' => $event->event_id,
        '--operator' => 'second',
        '--reason' => 'racing recovery',
        '--json' => true,
    ])
        ->expectsOutput(json_encode([
            'event_id' => $event->event_id,
            'result' => 'refused',
            'reason' => 'active',
        ], JSON_THROW_ON_ERROR))
        ->assertFailed();

    expect($event->fresh()?->status)->toBe(OutboxStatus::Pending)
        ->and($event->fresh()?->attempt_count)->toBe(1)
        ->and(OutboxAttempt::query()->count())->toBe(1);
    Bus::assertDispatchedTimes(DeliverOutboxEvent::class, 1);
});

/** @param array<string, mixed> $attributes */
function p17RetryEvent(array $attributes = []): OutboxEvent
{
    $eventData = new CustomerCreatedEventData(
        event_id: strtolower((string) Str::ulid()),
        program_key: 'program-public-key',
        occurred_at: '2026-09-20T12:00:00Z',
        external_customer_id: 'customer-123',
    );
    $payload = (new EventPayloadCodec)->encode($eventData);

    return OutboxEvent::factory()->create([
        'event_id' => $eventData->event_id,
        'source_key' => 'retry:'.fake()->uuid(),
        'event_type' => $eventData->type(),
        'delivery_mode' => DeliveryMode::Live,
        'status' => OutboxStatus::Failed,
        'payload' => $payload->payload,
        'payload_sha256' => $payload->sha256,
        'attempt_count' => 0,
        'terminal_at' => now(),
        ...$attributes,
    ]);
}

function p17PreviousAttempt(OutboxEvent $event): OutboxAttempt
{
    $event->attempt_count = 1;
    $event->save();

    return OutboxAttempt::factory()->create([
        'outbox_event_id' => $event->getKey(),
        'number' => 1,
        'claim_token' => strtolower((string) Str::ulid()),
        'outcome' => AttemptOutcome::Failed,
        'error_code' => 'http_500',
        'started_at' => now()->subMinute(),
        'finished_at' => now()->subMinute(),
    ]);
}

function p17RetryConnection(): Connection
{
    return DB::connection('linkado_retry_command_test');
}

function p17RetryOutboxMigration(): Migration
{
    return require __DIR__.'/../../../database/migrations/2026_01_01_000000_create_linkado_outbox_events_table.php';
}

function p17RetryAttemptMigration(): Migration
{
    return require __DIR__.'/../../../database/migrations/2026_01_01_000001_create_linkado_outbox_attempts_table.php';
}

it('refuses an unknown stored event type as corrupt with sanitized output', function (): void {
    $event = p17RetryEvent();
    p17RetryConnection()->table('linkado_outbox_events')->where('id', $event->id)->update(['event_type' => 'unknown_type']);

    $this->artisan('linkado:retry', [
        'event' => $event->event_id,
        '--operator' => 'ops',
        '--reason' => 'recovery',
        '--json' => true,
    ])->expectsOutput(json_encode([
        'event_id' => $event->event_id,
        'result' => 'refused',
        'reason' => 'corrupt',
    ], JSON_THROW_ON_ERROR))->assertFailed();

    expect(OutboxAttempt::query()->count())->toBe(0);
});
