<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Linkado\Laravel\Models\OutboxAttempt;
use Linkado\Laravel\Models\OutboxEvent;
use Linkado\Laravel\Models\PendingAttribution;
use Linkado\Laravel\Tests\Support\DatabaseConfiguration;

/**
 * @return list<Migration>
 */
function linkadoMigrations(): array
{
    $files = glob(__DIR__.'/../../../database/migrations/*.php');

    if ($files === false) {
        return [];
    }

    sort($files);

    return array_map(
        static fn (string $file): Migration => require $file,
        $files,
    );
}

function linkadoSchema(): Builder
{
    return Schema::connection('linkado_test');
}

/**
 * @return array<string, array{columns: list<string>, unique: bool, primary: bool}>
 */
function linkadoIndexes(string $table): array
{
    $indexes = [];

    foreach (linkadoSchema()->getIndexes($table) as $index) {
        $columns = $index['columns'];
        sort($columns);

        $indexes[implode(',', $columns)] = [
            'columns' => $columns,
            'unique' => $index['unique'],
            'primary' => $index['primary'],
        ];
    }

    ksort($indexes);

    return $indexes;
}

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

    DB::purge('host_test');
    DB::purge('linkado_test');

    foreach (linkadoMigrations() as $migration) {
        $migration->up();
    }
});

afterEach(function (): void {
    if (linkadoSchema()->hasTable('linkado_pending_attributions')) {
        foreach (array_reverse(linkadoMigrations()) as $migration) {
            $migration->down();
        }
    }

    DB::purge('host_test');
    DB::purge('linkado_test');
});

it('creates the exact portable outbox event schema on the configured connection', function (): void {
    expect(linkadoSchema()->getColumnListing('linkado_outbox_events'))->toBe([
        'id',
        'event_id',
        'source_key',
        'event_type',
        'delivery_mode',
        'status',
        'payload',
        'payload_sha256',
        'attempt_count',
        'next_attempt_at',
        'claimed_at',
        'claim_token',
        'delivered_at',
        'terminal_at',
        'remote_event_id',
        'remote_status',
        'remote_warnings',
        'last_error_code',
        'last_error_class',
        'last_error_message',
        'created_at',
        'updated_at',
    ])->and(linkadoSchema()->getColumnType('linkado_outbox_events', 'id'))->toBeIn(['bigint', 'int8', 'integer'])
        ->and(linkadoSchema()->getColumnType('linkado_outbox_events', 'event_type'))->toBe('varchar')
        ->and(linkadoSchema()->getColumnType('linkado_outbox_events', 'delivery_mode'))->toBe('varchar')
        ->and(linkadoSchema()->getColumnType('linkado_outbox_events', 'status'))->toBe('varchar')
        ->and(linkadoSchema()->getColumnType('linkado_outbox_events', 'payload'))->toBe('text')
        ->and(linkadoIndexes('linkado_outbox_events'))->toBe([
            'delivery_mode' => ['columns' => ['delivery_mode'], 'unique' => false, 'primary' => false],
            'event_id' => ['columns' => ['event_id'], 'unique' => true, 'primary' => false],
            'event_type' => ['columns' => ['event_type'], 'unique' => false, 'primary' => false],
            'id' => ['columns' => ['id'], 'unique' => true, 'primary' => true],
            'source_key' => ['columns' => ['source_key'], 'unique' => true, 'primary' => false],
            'status' => ['columns' => ['status'], 'unique' => false, 'primary' => false],
        ])
        ->and(Schema::connection(config('database.default'))->hasTable('linkado_outbox_events'))->toBeFalse();
});

it('creates attempt constraints and cascades event deletion', function (): void {
    $attemptColumns = collect(linkadoSchema()->getColumns('linkado_outbox_attempts'))->keyBy('name');
    $eventIdType = linkadoSchema()->getColumnType('linkado_outbox_events', 'id');
    $attemptEventIdType = linkadoSchema()->getColumnType('linkado_outbox_attempts', 'outbox_event_id');

    expect(linkadoSchema()->getColumnListing('linkado_outbox_attempts'))->toBe([
        'id',
        'outbox_event_id',
        'number',
        'claim_token',
        'outcome',
        'http_status',
        'retry_after_seconds',
        'error_code',
        'error_class',
        'error_message',
        'remote_event_id',
        'remote_status',
        'remote_warnings',
        'started_at',
        'finished_at',
        'created_at',
        'updated_at',
    ])->and(linkadoSchema()->getColumnType('linkado_outbox_attempts', 'id'))->toBeIn(['bigint', 'int8', 'integer'])
        ->and($attemptEventIdType)->toBe($eventIdType)
        ->and($attemptColumns['outcome']['nullable'])->toBeTrue()
        ->and(linkadoIndexes('linkado_outbox_attempts'))->toBe([
            'id' => ['columns' => ['id'], 'unique' => true, 'primary' => true],
            'number,outbox_event_id' => ['columns' => ['number', 'outbox_event_id'], 'unique' => true, 'primary' => false],
            'outbox_event_id' => ['columns' => ['outbox_event_id'], 'unique' => false, 'primary' => false],
        ]);

    $foreignKeys = linkadoSchema()->getForeignKeys('linkado_outbox_attempts');

    expect($foreignKeys)->toHaveCount(1)
        ->and($foreignKeys[0]['columns'])->toBe(['outbox_event_id'])
        ->and($foreignKeys[0]['foreign_table'])->toBe('linkado_outbox_events')
        ->and($foreignKeys[0]['foreign_columns'])->toBe(['id'])
        ->and(strtolower((string) $foreignKeys[0]['on_delete']))->toBe('cascade');

    $outboxId = DB::connection('linkado_test')->table('linkado_outbox_events')->insertGetId([
        'event_id' => '01J00000000000000000000001',
        'source_key' => 'order:1',
        'event_type' => 'payment_succeeded',
        'delivery_mode' => 'live',
        'status' => 'pending',
        'payload' => '{}',
        'payload_sha256' => str_repeat('a', 64),
        'attempt_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $attemptId = DB::connection('linkado_test')->table('linkado_outbox_attempts')->insertGetId([
        'outbox_event_id' => $outboxId,
        'number' => 1,
        'claim_token' => '01J00000000000000000000003',
        'outcome' => 'delivered',
        'started_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($outboxId)->toBeInt()
        ->and($attemptId)->toBeInt()
        ->and(DB::connection('linkado_test')->table('linkado_outbox_events')->value('id'))->toBe($outboxId)
        ->and(DB::connection('linkado_test')->table('linkado_outbox_attempts')->value('outbox_event_id'))->toBe($outboxId)
        ->and(DB::connection('linkado_test')->table('linkado_outbox_events')->value('event_id'))->toBe('01J00000000000000000000001')
        ->and(DB::connection('linkado_test')->table('linkado_outbox_attempts')->value('claim_token'))->toBe('01J00000000000000000000003');

    DB::connection('linkado_test')->table('linkado_outbox_events')->delete();

    expect(DB::connection('linkado_test')->table('linkado_outbox_attempts')->count())->toBe(0);
});

it('creates the exact pending attribution schema', function (): void {
    expect(linkadoSchema()->getColumnListing('linkado_pending_attributions'))->toBe([
        'id',
        'visitor_hash',
        'click_id',
        'referral_slug',
        'captured_at',
        'expires_at',
        'consumed_at',
        'created_at',
        'updated_at',
        'identity_hash',
    ])->and(linkadoSchema()->getColumnType('linkado_pending_attributions', 'id'))->toBeIn(['bigint', 'int8', 'integer'])
        ->and(linkadoIndexes('linkado_pending_attributions'))->toBe([
            'expires_at' => ['columns' => ['expires_at'], 'unique' => false, 'primary' => false],
            'id' => ['columns' => ['id'], 'unique' => true, 'primary' => true],
            'visitor_hash' => ['columns' => ['visitor_hash'], 'unique' => true, 'primary' => false],
        ]);

    $columns = collect(linkadoSchema()->getColumns('linkado_pending_attributions'))->keyBy('name');
    expect($columns['identity_hash']['nullable'])->toBeTrue()
        ->and($columns['identity_hash']['type_name'])->toBe('varchar');

    if (DB::connection('linkado_test')->getDriverName() !== 'sqlite') {
        expect($columns['identity_hash']['type'])->toContain('(64)');
    }

    $attributionId = DB::connection('linkado_test')->table('linkado_pending_attributions')->insertGetId([
        'visitor_hash' => hash('sha256', '01J00000000000000000000004'),
        'click_id' => 'click-42',
        'referral_slug' => null,
        'captured_at' => now(),
        'expires_at' => now()->addHour(),
        'consumed_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($attributionId)->toBeInt()
        ->and(DB::connection('linkado_test')->table('linkado_pending_attributions')->value('id'))->toBe($attributionId);
});

it('lets every package factory persist database-generated integer keys', function (): void {
    $event = OutboxEvent::factory()->create();
    $attempt = OutboxAttempt::factory()->create(['outbox_event_id' => $event->getKey()]);
    $attribution = PendingAttribution::factory()->create();

    expect($event->getKey())->toBeInt()
        ->and(Str::isUlid($event->event_id))->toBeTrue()
        ->and($attempt->getKey())->toBeInt()
        ->and($attempt->outbox_event_id)->toBeInt()->toBe($event->getKey())
        ->and(Str::isUlid($attempt->claim_token))->toBeTrue()
        ->and($attribution->getKey())->toBeInt();
});

it('uses portable definitions without database enums json columns or partial indexes', function (): void {
    foreach (['linkado_outbox_events', 'linkado_outbox_attempts', 'linkado_pending_attributions'] as $table) {
        foreach (linkadoSchema()->getColumns($table) as $column) {
            expect(strtolower($column['type_name']))->not->toBeIn(['enum', 'json', 'jsonb']);
        }

        foreach (linkadoSchema()->getIndexes($table) as $index) {
            $definition = $index['definition'] ?? null;

            expect($definition === null || ! str_contains(strtolower((string) $definition), ' where '))->toBeTrue();
        }
    }
});

it('rolls every package table back cleanly', function (): void {
    foreach (array_reverse(linkadoMigrations()) as $migration) {
        $migration->down();
    }

    expect(linkadoSchema()->hasTable('linkado_outbox_attempts'))->toBeFalse()
        ->and(linkadoSchema()->hasTable('linkado_pending_attributions'))->toBeFalse()
        ->and(linkadoSchema()->hasTable('linkado_outbox_events'))->toBeFalse();
});
