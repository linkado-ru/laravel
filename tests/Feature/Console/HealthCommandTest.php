<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Linkado\Laravel\Enums\DeliveryMode;
use Linkado\Laravel\Enums\OutboxStatus;
use Linkado\Laravel\Models\OutboxEvent;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-21 12:00:00 UTC');

    config()->set('database.connections.linkado_health_test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('database.default', 'linkado_health_test');
    config()->set('linkado.connection', 'linkado_health_test');
    config()->set('linkado.mode', DeliveryMode::Live->value);
    config()->set('linkado.token', 'health-token-that-must-not-leak');
    config()->set('linkado.base_url', 'https://api.example.test/v1');
    config()->set('linkado.delivery.claim_timeout_seconds', 600);

    DB::purge('linkado_health_test');
});

afterEach(function (): void {
    if (Schema::connection('linkado_health_test')->hasTable('linkado_outbox_attempts')) {
        p18AttemptMigration()->down();
    }

    if (Schema::connection('linkado_health_test')->hasTable('linkado_pending_attributions')) {
        p18AttributionMigration()->down();
    }

    if (Schema::connection('linkado_health_test')->hasTable('linkado_outbox_events')) {
        p18EventMigration()->down();
    }

    DB::purge('linkado_health_test');
    CarbonImmutable::setTestNow();
});

it('reports an off integration without accessing its database', function (): void {
    config()->set('linkado.mode', DeliveryMode::Off->value);
    config()->set('linkado.connection', 'linkado_health_missing');

    $this->artisan('linkado:health', ['--json' => true])
        ->expectsOutput(p18Report([
            p18Check('configuration', 'healthy'),
            p18Check('database', 'not_applicable'),
            p18Check('migrations', 'not_applicable'),
            p18Check('pending_lag', 'not_applicable'),
            p18Check('stale_claims', 'not_applicable'),
            p18Check('permanent_failures', 'not_applicable'),
        ], 'healthy'))
        ->assertSuccessful();
});

it('reports healthy shadow and live integrations in table and JSON output', function (DeliveryMode $mode): void {
    config()->set('linkado.mode', $mode->value);
    p18Migrate();

    $this->artisan('linkado:health')
        ->expectsTable(['Code', 'Status', 'Count'], [
            ['configuration', 'healthy', '0'],
            ['database', 'healthy', '0'],
            ['migrations', 'healthy', '0'],
            ['pending_lag', 'healthy', '0'],
            ['stale_claims', 'healthy', '0'],
            ['permanent_failures', 'healthy', '0'],
        ])
        ->assertSuccessful();

    $this->artisan('linkado:health', ['--json' => true])
        ->expectsOutput(p18Report(p18HealthyChecks(), 'healthy'))
        ->assertSuccessful();
})->with('health modes');

dataset('health modes', [DeliveryMode::Shadow, DeliveryMode::Live]);

it('fails health when live credentials are missing or the base URL is not HTTPS', function (string $key, mixed $value): void {
    p18Migrate();
    config()->set('linkado.'.$key, $value);

    $this->artisan('linkado:health', ['--json' => true])
        ->expectsOutput(p18Report([
            p18Check('configuration', 'failure', 1),
            ...array_slice(p18HealthyChecks(), 1),
        ], 'failure'))
        ->assertExitCode(2);
})->with([
    'missing token' => ['token', null],
    'non-HTTPS base URL' => ['base_url', 'http://api.example.test/v1'],
]);

it('fails health when the Linkado migration tables are missing', function (): void {
    $this->artisan('linkado:health', ['--json' => true])
        ->expectsOutput(p18Report([
            p18Check('configuration', 'healthy'),
            p18Check('database', 'healthy'),
            p18Check('migrations', 'failure', 3),
            p18Check('pending_lag', 'not_applicable'),
            p18Check('stale_claims', 'not_applicable'),
            p18Check('permanent_failures', 'not_applicable'),
        ], 'failure'))
        ->assertExitCode(2);
});

it('warns when pending events are older than fifteen minutes', function (): void {
    p18Migrate();
    p18Event(['created_at' => now()->subMinutes(16), 'updated_at' => now()->subMinutes(16)]);

    $this->artisan('linkado:health', ['--json' => true])
        ->expectsOutput(p18Report([
            p18Check('configuration', 'healthy'),
            p18Check('database', 'healthy'),
            p18Check('migrations', 'healthy'),
            p18Check('pending_lag', 'warning', 1),
            p18Check('stale_claims', 'healthy'),
            p18Check('permanent_failures', 'healthy'),
        ], 'warning'))
        ->assertExitCode(1);
});

it('fails when pending events are older than sixty minutes', function (): void {
    p18Migrate();
    p18Event(['created_at' => now()->subMinutes(61), 'updated_at' => now()->subMinutes(61)]);

    $this->artisan('linkado:health', ['--json' => true])
        ->expectsOutput(p18Report([
            p18Check('configuration', 'healthy'),
            p18Check('database', 'healthy'),
            p18Check('migrations', 'healthy'),
            p18Check('pending_lag', 'failure', 1),
            p18Check('stale_claims', 'healthy'),
            p18Check('permanent_failures', 'healthy'),
        ], 'failure'))
        ->assertExitCode(2);
});

it('warns for stale delivery claims and fails for permanent delivery failures', function (): void {
    p18Migrate();
    p18Event([
        'status' => OutboxStatus::Delivering,
        'claimed_at' => now()->subSeconds(601),
    ]);
    p18Event([
        'source_key' => 'health:failed',
        'status' => OutboxStatus::Failed,
        'terminal_at' => now(),
    ]);

    $this->artisan('linkado:health', ['--json' => true])
        ->expectsOutput(p18Report([
            p18Check('configuration', 'healthy'),
            p18Check('database', 'healthy'),
            p18Check('migrations', 'healthy'),
            p18Check('pending_lag', 'healthy'),
            p18Check('stale_claims', 'warning', 1),
            p18Check('permanent_failures', 'failure', 1),
        ], 'failure'))
        ->assertExitCode(2);
});

it('redacts credentials, URLs, payloads, and failure messages from JSON health output', function (): void {
    p18Migrate();
    p18Event([
        'status' => OutboxStatus::Failed,
        'terminal_at' => now(),
        'payload' => '{"email":"person@example.test"}',
        'last_error_message' => 'Request to https://remote.example.test included health-token-that-must-not-leak.',
    ]);

    $output = p18Report([
        p18Check('configuration', 'healthy'),
        p18Check('database', 'healthy'),
        p18Check('migrations', 'healthy'),
        p18Check('pending_lag', 'healthy'),
        p18Check('stale_claims', 'healthy'),
        p18Check('permanent_failures', 'failure', 1),
    ], 'failure');

    $this->artisan('linkado:health', ['--json' => true])
        ->expectsOutput($output)
        ->assertExitCode(2);

    expect($output)
        ->not->toContain('health-token-that-must-not-leak')
        ->not->toContain('remote.example.test')
        ->not->toContain('person@example.test');
});

/** @param list<array{code: string, status: string, count: int}> $checks */
function p18Report(array $checks, string $status): string
{
    $counts = ['healthy' => 0, 'warning' => 0, 'failure' => 0, 'not_applicable' => 0];

    foreach ($checks as $check) {
        $counts[$check['status']]++;
    }

    return json_encode([
        'status' => $status,
        'checks' => $checks,
        'counts' => $counts,
    ], JSON_THROW_ON_ERROR);
}

/** @return array{code: string, status: string, count: int} */
function p18Check(string $code, string $status, int $count = 0): array
{
    return compact('code', 'status', 'count');
}

/** @return list<array{code: string, status: string, count: int}> */
function p18HealthyChecks(): array
{
    return [
        p18Check('configuration', 'healthy'),
        p18Check('database', 'healthy'),
        p18Check('migrations', 'healthy'),
        p18Check('pending_lag', 'healthy'),
        p18Check('stale_claims', 'healthy'),
        p18Check('permanent_failures', 'healthy'),
    ];
}

/** @param array<string, mixed> $attributes */
function p18Event(array $attributes = []): OutboxEvent
{
    return OutboxEvent::factory()->create([
        'source_key' => 'health:'.fake()->uuid(),
        'status' => OutboxStatus::Pending,
        ...$attributes,
    ]);
}

function p18Migrate(): void
{
    p18EventMigration()->up();
    p18AttemptMigration()->up();
    p18AttributionMigration()->up();
}

function p18EventMigration(): Migration
{
    return require __DIR__.'/../../../database/migrations/2026_01_01_000000_create_linkado_outbox_events_table.php';
}

function p18AttemptMigration(): Migration
{
    return require __DIR__.'/../../../database/migrations/2026_01_01_000001_create_linkado_outbox_attempts_table.php';
}

function p18AttributionMigration(): Migration
{
    return require __DIR__.'/../../../database/migrations/2026_01_01_000002_create_linkado_pending_attributions_table.php';
}
