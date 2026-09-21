<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Linkado\Laravel\Actions\CapturePendingAttribution;
use Linkado\Laravel\Events\AttributionConsumed;
use Linkado\Laravel\Facades\Linkado;
use Linkado\Laravel\Tests\Support\DatabaseConfiguration;

beforeEach(function (): void {
    config()->set('database.connections.first_touch', DatabaseConfiguration::externalOrSqlite());
    config()->set('linkado.connection', 'first_touch');
    config()->set('linkado.tracking.ttl_seconds', 120);
    DB::purge('first_touch');
    (require __DIR__.'/../../../database/migrations/2026_01_01_000002_create_linkado_pending_attributions_table.php')->up();
    (require __DIR__.'/../../../database/migrations/2026_09_21_000003_add_identity_hash_to_linkado_pending_attributions_table.php')->up();
    CarbonImmutable::setTestNow('2026-09-21 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    (require __DIR__.'/../../../database/migrations/2026_01_01_000002_create_linkado_pending_attributions_table.php')->down();
    DB::purge('first_touch');
});

it('preserves the first active source and absolute window except for slug to click', function (
    ?string $firstClick, ?string $firstSlug, ?string $nextClick, ?string $nextSlug,
    ?string $expectedClick, ?string $expectedSlug,
): void {
    $visitor = (string) Str::ulid();
    $capture = app(CapturePendingAttribution::class);
    $capture->handle($visitor, $firstClick, $firstSlug);
    CarbonImmutable::setTestNow('2026-09-21 10:01:59');
    $capture->handle($visitor, $nextClick, $nextSlug);
    $row = DB::connection('first_touch')->table('linkado_pending_attributions')->sole();

    expect($row->click_id)->toBe($expectedClick)
        ->and($row->referral_slug)->toBe($expectedSlug)
        ->and($row->captured_at)->toBe('2026-09-21 10:00:00')
        ->and($row->expires_at)->toBe('2026-09-21 10:02:00');
})->with([
    'slug to slug' => [null, 'first', null, 'second', null, 'first'],
    'click to click' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV', null, '01ARZ3NDEKTSV4RRFFQ69G5FAW', null, '01ARZ3NDEKTSV4RRFFQ69G5FAV', null],
    'click to slug' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV', null, null, 'second', '01ARZ3NDEKTSV4RRFFQ69G5FAV', null],
    'slug to click' => [null, 'first', '01ARZ3NDEKTSV4RRFFQ69G5FAW', null, '01ARZ3NDEKTSV4RRFFQ69G5FAW', null],
]);

it('fully replaces expired click or consumed marker at and after the boundary', function (int $seconds, bool $consume): void {
    $visitor = (string) Str::ulid();
    $capture = app(CapturePendingAttribution::class);
    $capture->handle($visitor, '01ARZ3NDEKTSV4RRFFQ69G5FAV', null);

    if ($consume) {
        DB::connection('first_touch')->transaction(fn () => Linkado::attribution()->consume(firstTouchRequest($visitor, '01ARZ3NDEKTSV4RRFFQ69G5FAV')));
    }
    CarbonImmutable::setTestNow(now()->addSeconds($seconds));
    $capture->handle($visitor, null, 'new-partner');
    $row = DB::connection('first_touch')->table('linkado_pending_attributions')->sole();

    expect($row->click_id)->toBeNull()
        ->and($row->referral_slug)->toBe('new-partner')
        ->and($row->consumed_at)->toBeNull()
        ->and($row->captured_at)->toBe(now()->format('Y-m-d H:i:s'))
        ->and($row->expires_at)->toBe(now()->addSeconds(120)->format('Y-m-d H:i:s'));
})->with([120, 121])->with([false, true]);

it('keeps a scrubbed marker and emits only one event after the caller commits', function (): void {
    $visitor = (string) Str::ulid();
    $capture = app(CapturePendingAttribution::class);
    $capture->handle($visitor, '01ARZ3NDEKTSV4RRFFQ69G5FAV', null);
    $events = [];
    app('events')->listen(AttributionConsumed::class, function (AttributionConsumed $event) use (&$events): void {
        $events[] = $event;
    });
    $connection = DB::connection('first_touch');
    $connection->transaction(function () use ($visitor, $capture, &$events): void {
        expect(Linkado::attribution()->consume(firstTouchRequest($visitor, '01ARZ3NDEKTSV4RRFFQ69G5FAV'))?->clickId)->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAV');
        CarbonImmutable::setTestNow('2026-09-21 10:01:00');
        $capture->handle($visitor, '01ARZ3NDEKTSV4RRFFQ69G5FAW', null);
        $capture->handle($visitor, null, 'second-partner');
        expect(Linkado::attribution()->consume(firstTouchRequest($visitor, '01ARZ3NDEKTSV4RRFFQ69G5FAV')))->toBeNull()
            ->and($events)->toBeEmpty();
    });
    $row = $connection->table('linkado_pending_attributions')->sole();
    expect($events)->toHaveCount(1)
        ->and($row->click_id)->toBeNull()->and($row->referral_slug)->toBeNull()
        ->and($row->consumed_at)->toBe('2026-09-21 10:00:00')
        ->and($row->captured_at)->toBe('2026-09-21 10:00:00')
        ->and($row->expires_at)->toBe('2026-09-21 10:02:00');
});

it('rolls back capture and consume with the caller and suppresses rolled back events', function (): void {
    $visitor = (string) Str::ulid();
    $connection = DB::connection('first_touch');
    $capture = app(CapturePendingAttribution::class);
    $events = [];
    app('events')->listen(AttributionConsumed::class, function (AttributionConsumed $event) use (&$events): void {
        $events[] = $event;
    });
    $connection->beginTransaction();
    $capture->handle($visitor, null, 'first');
    $connection->rollBack();
    expect($connection->table('linkado_pending_attributions')->count())->toBe(0);

    $capture->handle($visitor, null, 'first');
    $connection->beginTransaction();
    $capture->handle($visitor, '01ARZ3NDEKTSV4RRFFQ69G5FAW', null);
    expect(Linkado::attribution()->consume(firstTouchRequest($visitor, '01ARZ3NDEKTSV4RRFFQ69G5FAW'))?->clickId)->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAW');
    $connection->rollBack();
    $row = $connection->table('linkado_pending_attributions')->sole();
    expect($row->click_id)->toBeNull()->and($row->referral_slug)->toBe('first')
        ->and($row->consumed_at)->toBeNull()->and($events)->toBeEmpty();
});

it('does not consume on or after expiry', function (int $seconds): void {
    $visitor = (string) Str::ulid();
    app(CapturePendingAttribution::class)->handle($visitor, '01ARZ3NDEKTSV4RRFFQ69G5FAV', null);
    CarbonImmutable::setTestNow(now()->addSeconds($seconds));
    expect(DB::connection('first_touch')->transaction(fn () => Linkado::attribution()->consume(firstTouchRequest($visitor, '01ARZ3NDEKTSV4RRFFQ69G5FAV'))))->toBeNull();
})->with([120, 121]);

it('bounds capture retries and never replays the caller transaction', function (bool $outer): void {
    $connection = DB::connection('first_touch');
    $attempts = 0;
    $connection->beforeExecuting(function (string $sql) use (&$attempts): void {
        if (str_starts_with($sql, 'select') && str_contains($sql, 'linkado_pending_attributions')) {
            $attempts++;

            throw new DeadlockException('deadlock detected: synthetic test failure');
        }
    });

    if ($outer) {
        $connection->beginTransaction();
    }

    try {
        expect(fn () => app(CapturePendingAttribution::class)->handle((string) Str::ulid(), '01ARZ3NDEKTSV4RRFFQ69G5FAV', null))
            ->toThrow(DeadlockException::class);
        expect($attempts)->toBe($outer ? 1 : 3)->and($connection->transactionLevel())->toBe($outer ? 1 : 0);
    } finally {
        if ($outer) {
            $connection->rollBack();
        }
    }
})->with([false, true]);

it('rolls back a partial capture and propagates non concurrency errors without retry', function (): void {
    $connection = DB::connection('first_touch');
    $updates = 0;
    $connection->beforeExecuting(function (string $sql) use (&$updates): void {
        if (str_starts_with($sql, 'update') && str_contains($sql, 'linkado_pending_attributions')) {
            $updates++;

            throw new RuntimeException('Synthetic storage failure');
        }
    });
    expect(fn () => app(CapturePendingAttribution::class)->handle((string) Str::ulid(), '01ARZ3NDEKTSV4RRFFQ69G5FAV', null))
        ->toThrow(RuntimeException::class, 'Synthetic storage failure');
    expect($updates)->toBe(1)->and($connection->table('linkado_pending_attributions')->count())->toBe(0)
        ->and($connection->transactionLevel())->toBe(0);
});

it('does not swallow an unrelated unique constraint failure and leaves the caller usable', function (): void {
    $connection = DB::connection('first_touch');
    app(CapturePendingAttribution::class)->handle((string) Str::ulid(), null, 'first');
    $connection->getSchemaBuilder()->table('linkado_pending_attributions', function (Blueprint $table): void {
        $table->unique('created_at', 'unrelated_created_at_unique');
    });
    $connection->beginTransaction();

    try {
        expect(fn () => app(CapturePendingAttribution::class)->handle((string) Str::ulid(), null, 'second'))
            ->toThrow(UniqueConstraintViolationException::class);
        expect($connection->transactionLevel())->toBe(1)
            ->and($connection->table('linkado_pending_attributions')->sole()->referral_slug)->toBe('first');
    } finally {
        $connection->rollBack();
    }
});

function firstTouchRequest(string $visitor, string $click): Request
{
    return Request::create('/register', 'POST', cookies: ['linkado_visitor' => $visitor, 'lk_click' => $click]);
}
