<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Linkado\Laravel\Http\Middleware\CapturePendingAttribution;

beforeEach(function (): void {
    config()->set('database.connections.host_test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('database.connections.linkado_test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('database.default', 'host_test');
    config()->set('linkado.connection', 'linkado_test');
    config()->set('linkado.mode', 'live');
    config()->set('linkado.features.tracking', true);
    config()->set('linkado.tracking.ttl_seconds', 120);

    DB::purge('host_test');
    DB::purge('linkado_test');

    p11AttributionMigration()->up();

    Route::middleware(['web', 'linkado.attribution'])
        ->get('/_linkado-tests/attribution', fn () => response('ok'));
    Route::middleware('linkado.attribution')
        ->get('/_linkado-tests/attribution-without-web', fn () => response('ok'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();

    if (Schema::connection('linkado_test')->hasTable('linkado_pending_attributions')) {
        p11AttributionMigration()->down();
    }

    DB::purge('host_test');
    DB::purge('linkado_test');
});

it('registers the explicit attribution middleware alias', function (): void {
    expect(app(Router::class)->getMiddleware()['linkado.attribution'] ?? null)
        ->toBe(CapturePendingAttribution::class);
});

it('captures a click against only the hashed visitor on the configured connection', function (): void {
    CarbonImmutable::setTestNow('2026-09-21 10:00:00');
    $visitorId = strtolower((string) Str::ulid());

    $this->withCookie('linkado_visitor', $visitorId)
        ->withUnencryptedCookie('lk_click', 'click-42')
        ->withHeader('User-Agent', 'must-not-be-stored')
        ->get('/_linkado-tests/attribution')
        ->assertOk();

    $row = p11AttributionRow();

    expect($row)->not->toBeNull()
        ->and($row?->id)->toBeInt()
        ->and(Str::isUlid($visitorId))->toBeTrue()
        ->and($row?->visitor_hash)->toBe(hash('sha256', $visitorId))
        ->and($row?->visitor_hash)->not->toContain($visitorId)
        ->and($row?->click_id)->toBe('click-42')
        ->and($row?->referral_slug)->toBeNull()
        ->and(CarbonImmutable::parse((string) $row?->captured_at)->equalTo(now()))->toBeTrue()
        ->and(CarbonImmutable::parse((string) $row?->expires_at)->equalTo(now()->addSeconds(120)))->toBeTrue()
        ->and(json_encode($row, JSON_THROW_ON_ERROR))->not->toContain($visitorId, 'must-not-be-stored')
        ->and(Schema::connection('host_test')->hasTable('linkado_pending_attributions'))->toBeFalse();
});

it('captures referral slugs from cookies and the configured query parameter', function (
    ?string $cookieReferral,
    string $query,
    string $expected,
): void {
    config()->set('linkado.tracking.referral_parameter', 'partner');
    $visitorId = strtolower((string) Str::ulid());
    $request = $this->withCookie('linkado_visitor', $visitorId);

    if ($cookieReferral !== null) {
        $request->withUnencryptedCookie('lk_referral', $cookieReferral);
    }

    $request->get('/_linkado-tests/attribution'.$query)->assertOk();

    expect(p11AttributionRow()?->click_id)->toBeNull()
        ->and(p11AttributionRow()?->referral_slug)->toBe($expected);
})->with([
    'referral cookie' => ['cookie-partner', '', 'cookie-partner'],
    'custom query parameter' => [null, '?partner=query-partner', 'query-partner'],
]);

it('prefers a click over referral slugs from either source', function (): void {
    $visitorId = strtolower((string) Str::ulid());

    $this->withCookie('linkado_visitor', $visitorId)
        ->withUnencryptedCookie('lk_click', 'click-wins')
        ->withUnencryptedCookie('lk_referral', 'cookie-loses')
        ->get('/_linkado-tests/attribution?ref=query-loses')
        ->assertOk();

    expect(p11AttributionRow()?->click_id)->toBe('click-wins')
        ->and(p11AttributionRow()?->referral_slug)->toBeNull();
});

it('upgrades an existing referral slug to a click', function (): void {
    $visitorId = strtolower((string) Str::ulid());

    $this->withCookie('linkado_visitor', $visitorId)
        ->withUnencryptedCookie('lk_referral', 'first-partner')
        ->get('/_linkado-tests/attribution')
        ->assertOk();

    $this->withCookie('linkado_visitor', $visitorId)
        ->withUnencryptedCookie('lk_click', 'later-click')
        ->get('/_linkado-tests/attribution')
        ->assertOk();

    expect(p11AttributionRow()?->click_id)->toBe('later-click')
        ->and(p11AttributionRow()?->referral_slug)->toBeNull()
        ->and(p11AttributionCount())->toBe(1);
});

it('never downgrades an existing click to a referral slug', function (): void {
    $visitorId = strtolower((string) Str::ulid());

    $this->withCookie('linkado_visitor', $visitorId)
        ->withUnencryptedCookie('lk_click', 'original-click')
        ->get('/_linkado-tests/attribution')
        ->assertOk();

    $this->withCookie('linkado_visitor', $visitorId)
        ->withUnencryptedCookie('lk_referral', 'later-partner')
        ->get('/_linkado-tests/attribution')
        ->assertOk();

    expect(p11AttributionRow()?->click_id)->toBe('original-click')
        ->and(p11AttributionRow()?->referral_slug)->toBeNull()
        ->and(p11AttributionCount())->toBe(1);
});

it('refreshes capture and expiry timestamps from the configured ttl', function (): void {
    $visitorId = strtolower((string) Str::ulid());
    CarbonImmutable::setTestNow('2026-09-21 10:00:00');

    $this->withCookie('linkado_visitor', $visitorId)
        ->withUnencryptedCookie('lk_click', 'click-42')
        ->get('/_linkado-tests/attribution')
        ->assertOk();

    CarbonImmutable::setTestNow('2026-09-21 11:00:00');

    $this->withCookie('linkado_visitor', $visitorId)
        ->withUnencryptedCookie('lk_referral', 'must-not-downgrade')
        ->get('/_linkado-tests/attribution')
        ->assertOk();

    $row = p11AttributionRow();

    expect(CarbonImmutable::parse((string) $row?->captured_at)->equalTo(now()))->toBeTrue()
        ->and(CarbonImmutable::parse((string) $row?->expires_at)->equalTo(now()->addSeconds(120)))->toBeTrue();
});

it('does not capture while tracking is disabled', function (): void {
    config()->set('linkado.features.tracking', false);

    $this->withCookie('linkado_visitor', strtolower((string) Str::ulid()))
        ->withUnencryptedCookie('lk_click', 'ignored-click')
        ->get('/_linkado-tests/attribution')
        ->assertOk();

    expect(p11AttributionCount())->toBe(0);
});

it('does not capture without a decrypted visitor', function (): void {
    $this->withUnencryptedCookie('lk_click', 'ignored-click')
        ->get('/_linkado-tests/attribution-without-web')
        ->assertOk();

    expect(p11AttributionCount())->toBe(0);
});

it('normalizes empty values and ignores requests without attribution', function (): void {
    $this->withCookie('linkado_visitor', strtolower((string) Str::ulid()))
        ->withUnencryptedCookie('lk_click', '   ')
        ->get('/_linkado-tests/attribution?ref=')
        ->assertOk();

    expect(p11AttributionCount())->toBe(0);
});

it('caps maliciously oversized external identifiers at 255 characters', function (
    string $cookie,
    string $value,
    string $column,
): void {
    $visitorId = strtolower((string) Str::ulid());

    $this->withCookie('linkado_visitor', $visitorId)
        ->withUnencryptedCookie($cookie, $value)
        ->get('/_linkado-tests/attribution')
        ->assertOk();

    $stored = p11AttributionRow()?->{$column};

    expect($stored)->toBe(mb_substr(trim($value), 0, 255))
        ->and(mb_strlen((string) $stored))->toBe(255);
})->with([
    'click id' => ['lk_click', str_repeat('c', 300), 'click_id'],
    'Unicode referral slug' => ['lk_referral', str_repeat('ж', 300), 'referral_slug'],
]);

function p11AttributionMigration(): Migration
{
    return require __DIR__.'/../../../database/migrations/2026_01_01_000002_create_linkado_pending_attributions_table.php';
}

function p11AttributionRow(): ?stdClass
{
    return DB::connection('linkado_test')->table('linkado_pending_attributions')->first();
}

function p11AttributionCount(): int
{
    return DB::connection('linkado_test')->table('linkado_pending_attributions')->count();
}
