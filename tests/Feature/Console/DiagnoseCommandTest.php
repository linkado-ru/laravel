<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Linkado\Laravel\Enums\DeliveryMode;
use Linkado\Laravel\Enums\OutboxStatus;
use Linkado\Laravel\Models\OutboxEvent;
use Linkado\Laravel\Support\Events\EventPayloadCodec;
use Linkado\PhpSdk\DataObjects\CustomerCreatedEventData;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-21 12:00:00 UTC');

    config()->set('database.connections.linkado_diagnose_test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('database.default', 'linkado_diagnose_test');
    config()->set('linkado.connection', 'linkado_diagnose_test');
    config()->set('linkado.mode', DeliveryMode::Live->value);
    config()->set('linkado.delivery.claim_timeout_seconds', 600);
    config()->set('linkado.delivery.max_attempts', 8);

    DB::purge('linkado_diagnose_test');
    p19EventMigration()->up();
});

afterEach(function (): void {
    if (Schema::connection('linkado_diagnose_test')->hasTable('linkado_outbox_events')) {
        p19EventMigration()->down();
    }

    DB::purge('linkado_diagnose_test');
    CarbonImmutable::setTestNow();
});

it('reports a zero state in table and JSON output', function (): void {
    $this->artisan('linkado:diagnose')
        ->expectsTable(['Category', 'Count', 'Command'], [
            ['pending', '0', ''],
            ['stale', '0', ''],
            ['conflict', '0', ''],
            ['exhausted', '0', ''],
            ['corrupt', '0', ''],
        ])
        ->assertSuccessful();

    $this->artisan('linkado:diagnose', ['--json' => true])
        ->expectsOutput(p19Report())
        ->assertSuccessful();
});

it('classifies pending stale conflict exhausted and corrupt rows without exposing their contents', function (): void {
    $pending = p19Event(['source_key' => 'diagnose:pending']);
    p19Event([
        'source_key' => 'diagnose:stale',
        'status' => OutboxStatus::Delivering,
        'claimed_at' => now()->subSeconds(601),
    ]);
    p19Event([
        'source_key' => 'diagnose:conflict',
        'status' => OutboxStatus::Failed,
        'last_error_code' => 'http_409',
        'terminal_at' => now(),
    ]);
    $exhausted = p19Event([
        'source_key' => 'diagnose:exhausted',
        'status' => OutboxStatus::Failed,
        'attempt_count' => 8,
        'last_error_code' => 'http_500',
        'terminal_at' => now(),
    ]);
    p19Event([
        'source_key' => 'diagnose:corrupt',
        'status' => OutboxStatus::Failed,
        'payload' => '{"email":"person@example.test","token":"do-not-leak"}',
        'payload_sha256' => str_repeat('0', 64),
        'terminal_at' => now(),
    ]);

    $output = p19Report([
        'pending' => 1,
        'stale' => 1,
        'conflict' => 1,
        'exhausted' => 1,
        'corrupt' => 1,
    ], [
        'php artisan linkado:recover',
        'php artisan linkado:retry '.$exhausted->event_id.' --operator=operator --reason=manual-retry',
    ]);

    $this->artisan('linkado:diagnose', ['--json' => true])
        ->expectsOutput($output)
        ->assertSuccessful();

    expect($output)
        ->not->toContain($pending->payload)
        ->not->toContain('person@example.test')
        ->not->toContain('do-not-leak');
});

it('suggests only executable manual-retry commands in table output', function (): void {
    $exhausted = p19Event([
        'source_key' => 'diagnose:exhausted-table',
        'status' => OutboxStatus::Failed,
        'attempt_count' => 8,
        'last_error_code' => 'http_500',
        'terminal_at' => now(),
    ]);
    p19Event([
        'source_key' => 'diagnose:corrupt-exhausted',
        'status' => OutboxStatus::Failed,
        'attempt_count' => 8,
        'last_error_code' => 'payload_corrupt',
        'payload_sha256' => str_repeat('0', 64),
        'terminal_at' => now(),
    ]);

    $this->artisan('linkado:diagnose')
        ->expectsTable(['Category', 'Count', 'Command'], [
            ['pending', '0', ''],
            ['stale', '0', ''],
            ['conflict', '0', ''],
            ['exhausted', '2', 'php artisan linkado:retry '.$exhausted->event_id.' --operator=operator --reason=manual-retry'],
            ['corrupt', '1', ''],
        ])
        ->assertSuccessful();
});

/** @param array{pending?: int, stale?: int, conflict?: int, exhausted?: int, corrupt?: int} $counts */
/** @param list<string> $commands */
function p19Report(array $counts = [], array $commands = []): string
{
    return json_encode([
        'counts' => [
            'pending' => $counts['pending'] ?? 0,
            'stale' => $counts['stale'] ?? 0,
            'conflict' => $counts['conflict'] ?? 0,
            'exhausted' => $counts['exhausted'] ?? 0,
            'corrupt' => $counts['corrupt'] ?? 0,
        ],
        'commands' => $commands,
    ], JSON_THROW_ON_ERROR);
}

/** @param array<string, mixed> $attributes */
function p19Event(array $attributes = []): OutboxEvent
{
    $eventId = strtolower((string) Str::ulid());
    $payload = (new EventPayloadCodec)->encode(new CustomerCreatedEventData(
        event_id: $eventId,
        program_key: 'program-key',
        occurred_at: '2026-09-21T12:00:00Z',
        external_customer_id: 'customer-42',
    ));

    return OutboxEvent::factory()->create([
        'event_id' => $eventId,
        'event_type' => $payload->type,
        'payload' => $payload->payload,
        'payload_sha256' => $payload->sha256,
        'source_key' => 'diagnose:'.fake()->uuid(),
        'status' => OutboxStatus::Pending,
        ...$attributes,
    ]);
}

function p19EventMigration(): Migration
{
    return require __DIR__.'/../../../database/migrations/2026_01_01_000000_create_linkado_outbox_events_table.php';
}
