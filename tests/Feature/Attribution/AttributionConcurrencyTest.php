<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Linkado\Laravel\Actions\CapturePendingAttribution;
use Linkado\Laravel\Tests\Support\Attribution\ConcurrentWorker;
use Linkado\Laravel\Tests\Support\DatabaseConfiguration;

uses()->group('attribution-concurrency');

beforeEach(function (): void {
    $configuration = DatabaseConfiguration::externalOrSqlite();

    if (! in_array($configuration['driver'], ['mysql', 'pgsql'], true)) {
        throw new RuntimeException('Attribution concurrency requires a disposable MySQL or PostgreSQL database.');
    }
    config()->set('database.connections.attribution_race', $configuration);
    config()->set('linkado.connection', 'attribution_race');
    config()->set('linkado.tracking.ttl_seconds', 120);
    DB::purge('attribution_race');
    (require __DIR__.'/../../../database/migrations/2026_01_01_000002_create_linkado_pending_attributions_table.php')->up();
    (require __DIR__.'/../../../database/migrations/2026_09_21_000003_add_identity_hash_to_linkado_pending_attributions_table.php')->up();
    DB::connection('attribution_race')->getSchemaBuilder()->create('anonymous_identities', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->boolean('claimed')->default(false);
    });
    DB::connection('attribution_race')->table('anonymous_identities')->insert(['key' => 'anonymous-a']);
    $this->raceSchemaCreated = true;
    CarbonImmutable::setTestNow('2026-09-21 10:00:00');
    $this->workers = [];
});

afterEach(function (): void {
    foreach ($this->workers ?? [] as $worker) {
        $worker->close();
    }
    CarbonImmutable::setTestNow();

    if ($this->raceSchemaCreated ?? false) {
        DB::connection('attribution_race')->getSchemaBuilder()->dropIfExists('anonymous_identities');
        (require __DIR__.'/../../../database/migrations/2026_01_01_000002_create_linkado_pending_attributions_table.php')->down();
        DB::purge('attribution_race');
    }
});

it('serializes two captures of a missing row at the engine default isolation', function (): void {
    $visitor = (string) Str::ulid();
    $a = raceWorker($this, ['visitor' => $visitor, 'operation' => 'capture', 'slug' => 'first', 'before' => 'insert']);
    $a->await('before');
    $b = raceWorker($this, ['visitor' => $visitor, 'operation' => 'capture', 'slug' => 'second', 'before' => 'insert']);
    $b->await('before');
    $a->release();
    $b->release();
    $a->await('done');
    $b->await('done');
    $row = DB::connection('attribution_race')->table('linkado_pending_attributions')->sole();
    expect($row->referral_slug)->toBeIn(['first', 'second'])
        ->and($row->click_id)->toBeNull()
        ->and($row->captured_at)->toBe('2026-09-21 10:00:00')
        ->and($row->expires_at)->toBe('2026-09-21 10:02:00');
});

it('recovers a real insert conflict through a savepoint and reads the committed winner', function (string $prefix): void {
    if ($prefix !== '') {
        DB::connection('attribution_race')->getSchemaBuilder()->dropIfExists('anonymous_identities');
        $migration = require __DIR__.'/../../../database/migrations/2026_01_01_000002_create_linkado_pending_attributions_table.php';
        $migration->down();
        config()->set('database.connections.attribution_race.prefix', $prefix);
        DB::purge('attribution_race');
        $migration->up();
        (require __DIR__.'/../../../database/migrations/2026_09_21_000003_add_identity_hash_to_linkado_pending_attributions_table.php')->up();
    }
    $visitor = (string) Str::ulid();
    $b = raceWorker($this, ['visitor' => $visitor, 'operation' => 'capture', 'slug' => 'loser', 'before' => 'insert', 'isolation' => 'read-committed', 'now' => '2026-09-21 10:01:00', 'outer' => true, 'prefix' => $prefix]);
    $b->await('before');
    $a = raceWorker($this, ['visitor' => $visitor, 'operation' => 'capture', 'slug' => 'winner', 'hold' => true, 'isolation' => 'read-committed', 'prefix' => $prefix]);
    $a->await('holding');
    $b->release();
    raceAwaitBlocked($b->session, $a->session);
    $a->release();
    $a->await('done');
    $b->await('done');
    $row = DB::connection('attribution_race')->table('linkado_pending_attributions')->sole();
    expect($row->referral_slug)->toBe('winner')
        ->and($row->captured_at)->toBe('2026-09-21 10:00:00')
        ->and($row->expires_at)->toBe('2026-09-21 10:02:00');
})->with(fn (): array => array_merge(['', 'p3_', 'P3_', 'p3-'], getenv('LINKADO_TEST_DB_DRIVER') === 'pgsql' ? ['tenant_shared_app_'] : []));

it('serializes upgrades and consumers while preserving one window and one snapshot', function (
    string $firstOperation, ?string $firstClick, string $secondOperation, ?string $secondClick,
    ?string $expectedClick, ?string $expectedSlug, int $snapshots,
): void {
    $visitor = (string) Str::ulid();
    app(CapturePendingAttribution::class)->handle($visitor, null, 'original');
    $a = raceWorker($this, ['visitor' => $visitor, 'operation' => $firstOperation, 'click' => $firstClick, 'slug' => $firstOperation === 'consume' ? 'original' : null, 'hold' => true, 'now' => '2026-09-21 10:00:30']);
    $a->await('holding');
    $b = raceWorker($this, ['visitor' => $visitor, 'operation' => $secondOperation, 'click' => $secondClick, 'slug' => $secondOperation === 'consume' && $secondClick === null ? 'original' : null, 'before' => 'select', 'now' => '2026-09-21 10:01:00']);
    $b->await('before');
    $b->release();
    raceAwaitBlocked($b->session, $a->session);
    $a->release();
    $first = $a->await('done');
    $second = $b->await('done');
    $row = DB::connection('attribution_race')->table('linkado_pending_attributions')->sole();
    expect($row->click_id)->toBe($expectedClick)->and($row->referral_slug)->toBe($expectedSlug)
        ->and($row->captured_at)->toBe('2026-09-21 10:00:00')
        ->and($row->expires_at)->toBe('2026-09-21 10:02:00')
        ->and(count(array_filter([$first['snapshot'], $second['snapshot']])))->toBe($snapshots)
        ->and($first['events'] + $second['events'])->toBe($snapshots);

    if ($snapshots === 1) {
        expect($row->consumed_at)->not->toBeNull();
        expect($first['snapshot'] ?? $second['snapshot'])->toBe($firstOperation === 'capture'
            ? ['click' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'slug' => null] : ['click' => null, 'slug' => 'original']);
    }
})->with([
    'two upgrades' => ['capture', '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'capture', '01ARZ3NDEKTSV4RRFFQ69G5FAW', '01ARZ3NDEKTSV4RRFFQ69G5FAV', null, 0],
    'upgrade then consume' => ['capture', '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'consume', '01ARZ3NDEKTSV4RRFFQ69G5FAV', null, null, 1],
    'consume then capture' => ['consume', null, 'capture', '01ARZ3NDEKTSV4RRFFQ69G5FAW', null, null, 1],
    'two consumers' => ['consume', null, 'consume', null, null, null, 1],
]);

it('uses the time after waiting for the row lock to decide expiry', function (string $operation): void {
    $visitor = (string) Str::ulid();
    app(CapturePendingAttribution::class)->handle($visitor, null, 'original');
    $a = raceWorker($this, ['visitor' => $visitor, 'operation' => 'capture', 'click' => '01ARZ3NDEKTSV4RRFFQ69G5FAW', 'hold' => true]);
    $a->await('holding');
    $b = raceWorker($this, [
        'visitor' => $visitor, 'operation' => $operation, 'slug' => 'new-window', 'before' => 'select',
        'now' => '2026-09-21 10:01:59', 'after_lock_now' => '2026-09-21 10:02:00',
    ]);
    $b->await('before');
    $b->release();
    raceAwaitBlocked($b->session, $a->session);
    $a->release();
    $a->await('done');
    $result = $b->await('done');
    expect($result['snapshot'])->toBeNull()->and($result['events'])->toBe(0);

    if ($operation === 'capture') {
        $row = DB::connection('attribution_race')->table('linkado_pending_attributions')->sole();
        expect($row->click_id)->toBeNull()->and($row->referral_slug)->toBe('new-window')
            ->and($row->captured_at)->toBe('2026-09-21 10:02:00')
            ->and($row->expires_at)->toBe('2026-09-21 10:04:00');
    } else {
        expect(DB::connection('attribution_race')->table('linkado_pending_attributions')->count())->toBe(0);
    }
})->with(['capture', 'consume']);

it('makes a rolled back consumer snapshot available to the waiting consumer', function (): void {
    $visitor = (string) Str::ulid();
    app(CapturePendingAttribution::class)->handle($visitor, null, 'original');
    $a = raceWorker($this, ['visitor' => $visitor, 'operation' => 'consume', 'slug' => 'original', 'hold' => true, 'rollback' => true]);
    $a->await('holding');
    $b = raceWorker($this, ['visitor' => $visitor, 'operation' => 'consume', 'slug' => 'original', 'before' => 'select']);
    $b->await('before');
    $b->release();
    raceAwaitBlocked($b->session, $a->session);
    $a->release();
    $first = $a->await('done');
    $second = $b->await('done');
    expect($first['events'])->toBe(0)->and($second['events'])->toBe(1)
        ->and($second['snapshot'])->toBe(['click' => null, 'slug' => 'original']);
});

it('serializes matching and mismatched consumers without returning the wrong source', function (bool $validFirst, bool $rollback, string $source): void {
    $visitor = (string) Str::ulid();
    $otherVisitor = (string) Str::ulid();
    $valid = $source === 'click' ? '01ARZ3NDEKTSV4RRFFQ69G5FAV' : 'original';
    $wrong = $source === 'click' ? '01ARZ3NDEKTSV4RRFFQ69G5FAW' : 'other';
    app(CapturePendingAttribution::class)->handle($visitor, $source === 'click' ? $valid : null, $source === 'slug' ? $valid : null);
    app(CapturePendingAttribution::class)->handle($otherVisitor, $source === 'click' ? $wrong : null, $source === 'slug' ? $wrong : null);
    $otherBefore = DB::connection('attribution_race')->table('linkado_pending_attributions')->where('visitor_hash', hash('sha256', $otherVisitor))->sole();
    $a = raceWorker($this, [
        'visitor' => $visitor, 'operation' => 'consume', $source => $validFirst ? $valid : $wrong,
        'hold' => true, 'rollback' => $rollback,
    ]);
    $a->await('holding');
    $b = raceWorker($this, [
        'visitor' => $visitor, 'operation' => 'consume', $source => $validFirst ? $wrong : $valid,
        'before' => 'select',
    ]);
    $b->await('before');
    $b->release();
    raceAwaitBlocked($b->session, $a->session);
    $a->release();
    $first = $a->await('done');
    $second = $b->await('done');
    $expected = ['click' => $source === 'click' ? $valid : null, 'slug' => $source === 'slug' ? $valid : null];
    expect($first['snapshot'])->toBe($validFirst ? $expected : null)
        ->and($second['snapshot'])->toBe(! $validFirst && $rollback ? $expected : null)
        ->and($first['events'])->toBe($validFirst && ! $rollback ? 1 : 0)
        ->and($second['events'])->toBe(! $validFirst && $rollback ? 1 : 0);
    $row = DB::connection('attribution_race')->table('linkado_pending_attributions')->where('visitor_hash', hash('sha256', $visitor))->sole();
    expect($row->click_id)->toBeNull()->and($row->referral_slug)->toBeNull()
        ->and($row->consumed_at)->not->toBeNull()
        ->and($row->captured_at)->toBe('2026-09-21 10:00:00')
        ->and($row->expires_at)->toBe('2026-09-21 10:02:00')
        ->and(DB::connection('attribution_race')->table('linkado_pending_attributions')->where('visitor_hash', hash('sha256', $otherVisitor))->sole())->toEqual($otherBefore);
})->with([false, true])->with([false, true])->with(['click', 'slug']);

/** @param array<string, mixed> $options */
function raceWorker(object $test, array $options): ConcurrentWorker
{
    $worker = new ConcurrentWorker($options);
    $test->workers[] = $worker;

    return $worker;
}

function raceAwaitBlocked(int $waiting, int $blocking): void
{
    $connection = DB::connection('attribution_race');
    $mariaDb = str_contains((string) $connection->selectOne('SELECT VERSION() AS version')->version, 'MariaDB');
    $deadline = microtime(true) + 10;
    do {
        $row = $connection->getDriverName() === 'pgsql'
            ? $connection->selectOne('SELECT ? = ANY(pg_blocking_pids(?)) AS blocked', [$blocking, $waiting])
            : ($mariaDb
                ? $connection->selectOne('SELECT COUNT(*) AS blocked FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id = w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id = w.blocking_trx_id WHERE r.trx_mysql_thread_id = ? AND b.trx_mysql_thread_id = ?', [$waiting, $blocking])
                : $connection->selectOne('SELECT COUNT(*) AS blocked FROM performance_schema.data_lock_waits w JOIN performance_schema.threads r ON r.THREAD_ID = w.REQUESTING_THREAD_ID JOIN performance_schema.threads b ON b.THREAD_ID = w.BLOCKING_THREAD_ID WHERE r.PROCESSLIST_ID = ? AND b.PROCESSLIST_ID = ?', [$waiting, $blocking]));

        if ((bool) $row->blocked) {
            expect((bool) $row->blocked)->toBeTrue();

            return;
        }
    } while (microtime(true) < $deadline);

    throw new RuntimeException('No database lock wait observed between the independent workers.');
}

it('serializes identity capture first and registration second with a single snapshot', function (): void {
    $visitor = (string) Str::ulid();
    $options = ['identity_binding' => true, 'identity' => 'anonymous-a', 'visitor' => $visitor, 'slug' => 'first'];
    $a = raceWorker($this, [...$options, 'operation' => 'capture', 'hold' => true]);
    $a->await('holding');
    $b = raceWorker($this, [...$options, 'operation' => 'register']);
    raceAwaitBlocked($b->session, $a->session);
    $a->release();
    $a->await('done');
    $result = $b->await('done');
    expect($result['snapshot'])->toBe(['click' => null, 'slug' => 'first'])
        ->and($result['events'])->toBe(1);
    $row = DB::connection('attribution_race')->table('linkado_pending_attributions')->sole();
    expect($row->identity_hash)->toBe(hash('sha256', 'linkado:attribution-identity:anonymous-a'))
        ->and($row->consumed_at)->not->toBeNull()
        ->and($row->referral_slug)->toBeNull();
    $late = raceWorker($this, [...$options, 'visitor' => (string) Str::ulid(), 'operation' => 'capture', 'now' => '2026-09-22 10:00:00']);
    $late->await('done');
    expect(DB::connection('attribution_race')->table('linkado_pending_attributions')->sole())->toEqual($row);
});

it('holds the identity lock through claim without pending visitor or source', function (bool $hasVisitor, bool $hasSource, bool $rollback): void {
    $visitor = (string) Str::ulid();
    $options = ['identity_binding' => true, 'identity' => 'anonymous-a'];
    $a = raceWorker($this, [...$options, 'operation' => 'register', 'visitor' => $hasVisitor ? $visitor : null, 'slug' => $hasSource ? 'first' : null, 'hold_guard' => true, 'hold_claim' => true, 'rollback' => $rollback]);
    $a->await('guarded');
    $b = raceWorker($this, [...$options, 'operation' => 'capture', 'visitor' => $visitor, 'slug' => 'first']);
    raceAwaitBlocked($b->session, $a->session);
    $a->release();
    $a->await('claimed');
    raceAwaitBlocked($b->session, $a->session);
    $a->release();
    $registered = $a->await('done');
    $b->await('done');
    expect($registered['snapshot'])->toBeNull()->and($registered['events'])->toBe(0)
        ->and(DB::connection('attribution_race')->table('anonymous_identities')->value('claimed'))->toBeIn($rollback ? [false, 0] : [true, 1])
        ->and(DB::connection('attribution_race')->table('linkado_pending_attributions')->count())->toBe($rollback ? 1 : 0);
})->with([false, true])->with([false, true])->with([false, true]);

it('rechecks a stale anonymous identity after another process commits registration', function (): void {
    $options = ['identity_binding' => true, 'identity' => 'anonymous-a', 'visitor' => (string) Str::ulid(), 'slug' => 'first'];
    $late = raceWorker($this, [...$options, 'operation' => 'capture', 'stale_read' => true]);
    $late->await('stale');
    $registration = raceWorker($this, [...$options, 'operation' => 'register']);
    $registration->await('done');
    $late->release();
    $late->await('done');
    expect(DB::connection('attribution_race')->table('linkado_pending_attributions')->count())->toBe(0);
});

it('serializes registration first with an existing bound row and a rotated visitor', function (bool $rollback): void {
    $options = ['identity_binding' => true, 'identity' => 'anonymous-a', 'visitor' => (string) Str::ulid(), 'slug' => 'first'];
    $seed = raceWorker($this, [...$options, 'operation' => 'capture']);
    $seed->await('done');
    $before = DB::connection('attribution_race')->table('linkado_pending_attributions')->sole();
    $a = raceWorker($this, [...$options, 'operation' => 'register', 'hold_guard' => true, 'rollback' => $rollback]);
    $a->await('guarded');
    $b = raceWorker($this, [...$options, 'operation' => 'capture', 'visitor' => (string) Str::ulid()]);
    raceAwaitBlocked($b->session, $a->session);
    $a->release();
    $result = $a->await('done');
    $b->await('done');
    expect($result['snapshot'])->toBe(['click' => null, 'slug' => 'first'])
        ->and($result['events'])->toBe($rollback ? 0 : 1)
        ->and(DB::connection('attribution_race')->table('linkado_pending_attributions')->count())->toBe($rollback ? 2 : 1);
    $original = DB::connection('attribution_race')->table('linkado_pending_attributions')->where('id', $before->id)->sole();

    if ($rollback) {
        expect($original)->toEqual($before);
    } else {
        expect($original->consumed_at)->not->toBeNull()->and($original->referral_slug)->toBeNull();
    }
})->with([false, true]);
