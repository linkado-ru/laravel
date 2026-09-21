<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('database.connections.linkado_test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('database.default', 'linkado_test');
    config()->set('linkado.connection', 'linkado_test');

    DB::purge('linkado_test');

    foreach (p12PruneMigrations() as $migration) {
        $migration->up();
    }
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();

    foreach (array_reverse(p12PruneMigrations()) as $migration) {
        $migration->down();
    }

    DB::purge('linkado_test');
});

it('prunes only expired attribution and leaves delivery retention untouched', function (): void {
    CarbonImmutable::setTestNow('2026-09-21 12:00:00');
    $connection = DB::connection('linkado_test');
    $expiredVisitor = strtolower((string) Str::ulid());
    $activeVisitor = strtolower((string) Str::ulid());
    p12InsertPruneAttribution($expiredVisitor, now()->subSecond());
    p12InsertPruneAttribution($activeVisitor, now()->addSecond());
    [$outboxId, $attemptId] = p12InsertDeliveryRows();

    $this->artisan('linkado:prune')
        ->expectsOutput('Pruned 1 expired Linkado attribution record.')
        ->assertSuccessful();

    expect($connection->table('linkado_pending_attributions')->pluck('visitor_hash')->all())
        ->toBe([hash('sha256', $activeVisitor)])
        ->and($connection->table('linkado_outbox_events')->where('id', $outboxId)->exists())->toBeTrue()
        ->and($connection->table('linkado_outbox_attempts')->where('id', $attemptId)->exists())->toBeTrue();
});

it('retains scrubbed markers until their original expiry including the exact boundary', function (): void {
    CarbonImmutable::setTestNow('2026-09-21 12:00:00');
    $connection = DB::connection('linkado_test');
    $expired = (string) Str::ulid();
    $boundary = (string) Str::ulid();
    $active = (string) Str::ulid();
    p12InsertPruneAttribution($expired, now()->subSecond());
    p12InsertPruneAttribution($boundary, now());
    p12InsertPruneAttribution($active, now()->addSecond());
    $connection->table('linkado_pending_attributions')->update([
        'click_id' => null,
        'referral_slug' => null,
        'consumed_at' => now()->subMinute(),
    ]);

    $this->artisan('linkado:prune', ['--json' => true])->expectsOutput('{"pruned":2}')->assertSuccessful();
    expect($connection->table('linkado_pending_attributions')->sole()->visitor_hash)->toBe(hash('sha256', $active));
});

it('reports a stable JSON zero result', function (): void {
    $this->artisan('linkado:prune', ['--json' => true])
        ->expectsOutput('{"pruned":0}')
        ->assertSuccessful();
});

/** @return list<Migration> */
function p12PruneMigrations(): array
{
    return [
        require __DIR__.'/../../../database/migrations/2026_01_01_000000_create_linkado_outbox_events_table.php',
        require __DIR__.'/../../../database/migrations/2026_01_01_000001_create_linkado_outbox_attempts_table.php',
        require __DIR__.'/../../../database/migrations/2026_01_01_000002_create_linkado_pending_attributions_table.php',
    ];
}

function p12InsertPruneAttribution(string $visitorId, DateTimeInterface $expiresAt): void
{
    DB::connection('linkado_test')->table('linkado_pending_attributions')->insert([
        'visitor_hash' => hash('sha256', $visitorId),
        'click_id' => 'click-42',
        'referral_slug' => null,
        'captured_at' => now(),
        'expires_at' => $expiresAt,
        'consumed_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @return array{int, int} */
function p12InsertDeliveryRows(): array
{
    $connection = DB::connection('linkado_test');
    $eventId = strtolower((string) Str::ulid());
    $claimToken = strtolower((string) Str::ulid());
    $payload = '{"event_id":"'.$eventId.'"}';

    $outboxId = $connection->table('linkado_outbox_events')->insertGetId([
        'event_id' => $eventId,
        'source_key' => 'prune-test',
        'event_type' => 'customer.created',
        'delivery_mode' => 'live',
        'status' => 'delivered',
        'payload' => $payload,
        'payload_sha256' => hash('sha256', $payload),
        'attempt_count' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $attemptId = $connection->table('linkado_outbox_attempts')->insertGetId([
        'outbox_event_id' => $outboxId,
        'number' => 1,
        'claim_token' => $claimToken,
        'outcome' => 'delivered',
        'started_at' => now(),
        'finished_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$outboxId, $attemptId];
}
