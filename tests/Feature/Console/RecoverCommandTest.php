<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Linkado\Laravel\Actions\RecoverOutboxEvents;
use Linkado\Laravel\Enums\DeliveryMode;
use Linkado\Laravel\Enums\OutboxStatus;
use Linkado\Laravel\Jobs\DeliverOutboxEvent;
use Linkado\Laravel\Models\OutboxAttempt;
use Linkado\Laravel\Models\OutboxEvent;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-21 12:00:00 UTC');

    config()->set('database.connections.linkado_recovery_test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('database.default', 'linkado_recovery_test');
    config()->set('cache.default', 'array');
    config()->set('linkado.connection', 'linkado_recovery_test');
    config()->set('linkado.mode', DeliveryMode::Live->value);
    config()->set('linkado.queue', 'linkado-recovery');
    config()->set('linkado.delivery.claim_timeout_seconds', 600);

    DB::purge('linkado_recovery_test');

    p17RecoverOutboxMigration()->up();
    p17RecoverAttemptMigration()->up();
});

afterEach(function (): void {
    $connection = p17RecoverConnection();

    while ($connection->transactionLevel() > 0) {
        $connection->rollBack();
    }

    if (Schema::connection('linkado_recovery_test')->hasTable('linkado_outbox_attempts')) {
        p17RecoverAttemptMigration()->down();
    }

    if (Schema::connection('linkado_recovery_test')->hasTable('linkado_outbox_events')) {
        p17RecoverOutboxMigration()->down();
    }

    DB::purge('linkado_recovery_test');
    CarbonImmutable::setTestNow();
});

it('reports a stable zero state in table and JSON output', function (): void {
    Bus::fake([DeliverOutboxEvent::class]);

    $this->artisan('linkado:recover')
        ->expectsTable(['Metric', 'Value'], [['Recovered', '0']])
        ->assertSuccessful();

    $this->artisan('linkado:recover', ['--json' => true])
        ->expectsOutput('{"recovered":0}')
        ->assertSuccessful();

    Bus::assertNothingDispatched();
});

it('dispatches due pending rows in creation order without changing immutable data', function (): void {
    Bus::fake([DeliverOutboxEvent::class]);
    $later = p17RecoverEvent([
        'source_key' => 'recover:later',
        'next_attempt_at' => now(),
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);
    $earlier = p17RecoverEvent([
        'source_key' => 'recover:earlier',
        'next_attempt_at' => null,
        'created_at' => now()->subMinutes(2),
        'updated_at' => now()->subMinutes(2),
    ]);
    p17RecoverEvent([
        'source_key' => 'recover:not-due',
        'next_attempt_at' => now()->addSecond(),
    ]);
    $immutable = [
        $earlier->event_id => [$earlier->getKey(), $earlier->payload, $earlier->payload_sha256],
        $later->event_id => [$later->getKey(), $later->payload, $later->payload_sha256],
    ];

    $this->artisan('linkado:recover', ['--json' => true])
        ->expectsOutput('{"recovered":2}')
        ->assertSuccessful();

    expect(Bus::dispatched(DeliverOutboxEvent::class)
        ->map(fn (DeliverOutboxEvent $job): string => $job->eventId)
        ->values()
        ->all())->toBe([$earlier->event_id, $later->event_id]);

    foreach ([$earlier, $later] as $event) {
        $stored = $event->fresh();

        expect($event->getKey())->toBeInt()
            ->and([$stored?->getKey(), $stored?->payload, $stored?->payload_sha256])
            ->toBe($immutable[$event->event_id])
            ->and($stored?->status)->toBe(OutboxStatus::Pending)
            ->and($stored?->attempt_count)->toBe(0);
    }
});

it('dispatches a stale claim but leaves a healthy delivery alone', function (): void {
    Bus::fake([DeliverOutboxEvent::class]);
    $staleToken = strtolower((string) Str::ulid());
    $stale = p17RecoverEvent([
        'source_key' => 'recover:stale',
        'status' => OutboxStatus::Delivering,
        'attempt_count' => 1,
        'claimed_at' => now()->subSeconds(601),
        'claim_token' => $staleToken,
    ]);
    $attempt = p17RecoverAttempt($stale, $staleToken);
    $healthy = p17RecoverEvent([
        'source_key' => 'recover:healthy',
        'status' => OutboxStatus::Delivering,
        'attempt_count' => 1,
        'claimed_at' => now()->subSeconds(599),
        'claim_token' => strtolower((string) Str::ulid()),
    ]);

    $this->artisan('linkado:recover', ['--json' => true])
        ->expectsOutput('{"recovered":1}')
        ->assertSuccessful();

    Bus::assertDispatchedTimes(DeliverOutboxEvent::class, 1);
    Bus::assertDispatched(
        DeliverOutboxEvent::class,
        fn (DeliverOutboxEvent $job): bool => $job->eventId === $stale->event_id
            && $job->queue === 'linkado-recovery',
    );
    expect($stale->fresh()?->claim_token)->toBe($staleToken)
        ->and($stale->fresh()?->status)->toBe(OutboxStatus::Delivering)
        ->and($attempt->fresh()?->outcome)->toBeNull()
        ->and($attempt->fresh()?->finished_at)->toBeNull()
        ->and($healthy->fresh()?->status)->toBe(OutboxStatus::Delivering);
});

it('converges competing recovery runs on one unique queued job', function (): void {
    Bus::fake([DeliverOutboxEvent::class]);
    $event = p17RecoverEvent(['source_key' => 'recover:race']);

    $first = app(RecoverOutboxEvents::class)->handle();
    $second = app(RecoverOutboxEvents::class)->handle();

    expect($first)->toBe(['recovered' => 1])
        ->and($second)->toBe(['recovered' => 0]);
    Bus::assertDispatchedTimes(DeliverOutboxEvent::class, 1);
    Bus::assertDispatched(
        DeliverOutboxEvent::class,
        fn (DeliverOutboxEvent $job): bool => $job->eventId === $event->event_id,
    );
});

it('does not recover rows while package mode is off', function (): void {
    Bus::fake([DeliverOutboxEvent::class]);
    p17RecoverEvent(['source_key' => 'recover:off']);
    config()->set('linkado.mode', DeliveryMode::Off->value);

    $this->artisan('linkado:recover', ['--json' => true])
        ->expectsOutput('{"recovered":0}')
        ->assertSuccessful();

    Bus::assertNothingDispatched();
});

/** @param array<string, mixed> $attributes */
function p17RecoverEvent(array $attributes = []): OutboxEvent
{
    return OutboxEvent::factory()->create($attributes);
}

function p17RecoverAttempt(OutboxEvent $event, string $claimToken): OutboxAttempt
{
    return OutboxAttempt::factory()->create([
        'outbox_event_id' => $event->getKey(),
        'number' => 1,
        'claim_token' => $claimToken,
        'outcome' => null,
        'started_at' => now()->subSeconds(601),
        'finished_at' => null,
    ]);
}

function p17RecoverConnection(): Connection
{
    return DB::connection('linkado_recovery_test');
}

function p17RecoverOutboxMigration(): Migration
{
    return require __DIR__.'/../../../database/migrations/2026_01_01_000000_create_linkado_outbox_events_table.php';
}

function p17RecoverAttemptMigration(): Migration
{
    return require __DIR__.'/../../../database/migrations/2026_01_01_000001_create_linkado_outbox_attempts_table.php';
}

it('recovers a lost queued job after its uniqueness lease expires', function (): void {
    Bus::fake([DeliverOutboxEvent::class]);
    p17RecoverEvent(['source_key' => 'recover:lost-queue']);

    expect(app(RecoverOutboxEvents::class)->handle())->toBe(['recovered' => 1]);
    Bus::fake([DeliverOutboxEvent::class]);
    CarbonImmutable::setTestNow(now()->addSeconds(601));

    expect(app(RecoverOutboxEvents::class)->handle())->toBe(['recovered' => 1]);
    Bus::assertDispatchedTimes(DeliverOutboxEvent::class, 1);
});
