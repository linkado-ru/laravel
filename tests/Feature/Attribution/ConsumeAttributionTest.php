<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Linkado\Laravel\Exceptions\ActiveTransactionRequired;
use Linkado\Laravel\Facades\Linkado;
use Linkado\Laravel\Support\Attribution\ConsumedAttribution;
use Linkado\Laravel\Tests\Support\DatabaseConfiguration;

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

    p12ConsumeMigration()->up();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();

    if (Schema::connection('linkado_test')->hasTable('linkado_pending_attributions')) {
        p12ConsumeMigration()->down();
    }

    DB::purge('host_test');
    DB::purge('linkado_test');
});

it('consumes valid click and referral attribution inside the configured transaction', function (
    ?string $clickId,
    ?string $referralSlug,
): void {
    $visitorId = strtolower((string) Str::ulid());
    p12InsertAttribution($visitorId, $clickId, $referralSlug);

    $result = DB::connection('linkado_test')->transaction(
        fn (): ?ConsumedAttribution => Linkado::attribution()->consume(p12Request($visitorId, $clickId, $referralSlug)),
    );

    expect($result)->toBeInstanceOf(ConsumedAttribution::class)
        ->and($result?->clickId)->toBe($clickId)
        ->and($result?->referralSlug)->toBe($referralSlug)
        ->and(p12AttributionCount())->toBe(1)
        ->and(DB::connection('linkado_test')->table('linkado_pending_attributions')->sole()->click_id)->toBeNull()
        ->and(DB::connection('linkado_test')->table('linkado_pending_attributions')->sole()->referral_slug)->toBeNull()
        ->and(DB::connection('linkado_test')->table('linkado_pending_attributions')->sole()->consumed_at)->not->toBeNull();
})->with([
    'click' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV', null],
    'referral slug' => [null, 'partner'],
]);

it('returns null for a missing visitor cookie without deleting another visitor attribution', function (): void {
    p12InsertAttribution(strtolower((string) Str::ulid()), '01ARZ3NDEKTSV4RRFFQ69G5FAV', null);

    $result = DB::connection('linkado_test')->transaction(
        fn (): ?ConsumedAttribution => Linkado::attribution()->consume(Request::create('/register')),
    );

    expect($result)->toBeNull()
        ->and(p12AttributionCount())->toBe(1);
});

it('returns null for a tampered visitor cookie without deleting another visitor attribution', function (): void {
    p12InsertAttribution(strtolower((string) Str::ulid()), '01ARZ3NDEKTSV4RRFFQ69G5FAV', null);

    $result = DB::connection('linkado_test')->transaction(
        fn (): ?ConsumedAttribution => Linkado::attribution()->consume(p12Request('tampered-cookie-value')),
    );

    expect($result)->toBeNull()
        ->and(p12AttributionCount())->toBe(1);
});

it('deletes expired attribution without returning it', function (): void {
    CarbonImmutable::setTestNow('2026-09-21 12:00:00');
    $visitorId = strtolower((string) Str::ulid());
    p12InsertAttribution($visitorId, '01ARZ3NDEKTSV4RRFFQ69G5FAV', null, now()->subSecond());

    $result = DB::connection('linkado_test')->transaction(
        fn (): ?ConsumedAttribution => Linkado::attribution()->consume(p12Request($visitorId, '01ARZ3NDEKTSV4RRFFQ69G5FAV')),
    );

    expect($result)->toBeNull()
        ->and(p12AttributionCount())->toBe(0);
});

it('restores consumed attribution when the caller rolls back', function (): void {
    $visitorId = strtolower((string) Str::ulid());
    p12InsertAttribution($visitorId, '01ARZ3NDEKTSV4RRFFQ69G5FAV', null);
    $connection = DB::connection('linkado_test');
    $connection->beginTransaction();

    try {
        $result = Linkado::attribution()->consume(p12Request($visitorId, '01ARZ3NDEKTSV4RRFFQ69G5FAV'));

        expect($result?->clickId)->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAV')
            ->and(p12AttributionCount())->toBe(1)
            ->and(DB::connection('linkado_test')->table('linkado_pending_attributions')->sole()->click_id)->toBeNull()
            ->and(DB::connection('linkado_test')->table('linkado_pending_attributions')->sole()->referral_slug)->toBeNull()
            ->and(DB::connection('linkado_test')->table('linkado_pending_attributions')->sole()->consumed_at)->not->toBeNull();
    } finally {
        $connection->rollBack();
    }

    expect(p12AttributionCount())->toBe(1);
});

it('cannot consume the same attribution twice', function (): void {
    $visitorId = strtolower((string) Str::ulid());
    p12InsertAttribution($visitorId, null, 'partner');

    $results = DB::connection('linkado_test')->transaction(fn (): array => [
        Linkado::attribution()->consume(p12Request($visitorId, null, 'partner')),
        Linkado::attribution()->consume(p12Request($visitorId, null, 'partner')),
    ]);

    expect($results[0])->toBeInstanceOf(ConsumedAttribution::class)
        ->and($results[1])->toBeNull()
        ->and(p12AttributionCount())->toBe(1)
        ->and(DB::connection('linkado_test')->table('linkado_pending_attributions')->sole()->click_id)->toBeNull()
        ->and(DB::connection('linkado_test')->table('linkado_pending_attributions')->sole()->referral_slug)->toBeNull()
        ->and(DB::connection('linkado_test')->table('linkado_pending_attributions')->sole()->consumed_at)->not->toBeNull();
});

it('rejects consumption when only the wrong database connection has a transaction', function (): void {
    $host = DB::connection('host_test');
    $host->beginTransaction();

    try {
        expect(fn (): ?ConsumedAttribution => Linkado::attribution()->consume(
            p12Request(strtolower((string) Str::ulid())),
        ))->toThrow(ActiveTransactionRequired::class);
    } finally {
        $host->rollBack();
    }
});

function p12ConsumeMigration(): Migration
{
    return require __DIR__.'/../../../database/migrations/2026_01_01_000002_create_linkado_pending_attributions_table.php';
}

function p12Request(string $visitorId, ?string $clickId = null, ?string $referralSlug = null): Request
{
    $request = Request::create('/register');
    $request->cookies->set('linkado_visitor', $visitorId);
    $request->cookies->set('lk_click', $clickId);
    $request->cookies->set('lk_referral', $referralSlug);

    return $request;
}

function p12InsertAttribution(
    string $visitorId,
    ?string $clickId,
    ?string $referralSlug,
    ?DateTimeInterface $expiresAt = null,
): void {
    DB::connection('linkado_test')->table('linkado_pending_attributions')->insert([
        'visitor_hash' => hash('sha256', $visitorId),
        'click_id' => $clickId,
        'referral_slug' => $referralSlug,
        'captured_at' => now(),
        'expires_at' => $expiresAt ?? now()->addHour(),
        'consumed_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function p12AttributionCount(): int
{
    return DB::connection('linkado_test')->table('linkado_pending_attributions')->count();
}
