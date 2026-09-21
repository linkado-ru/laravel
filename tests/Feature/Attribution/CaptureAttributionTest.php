<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Linkado\Laravel\Http\Middleware\CapturePendingAttribution;
use Symfony\Component\HttpFoundation\Response;

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
        ->withUnencryptedCookie('lk_click', '01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->withHeader('User-Agent', 'must-not-be-stored')
        ->get('/_linkado-tests/attribution')
        ->assertOk();

    $row = p11AttributionRow();

    expect($row)->not->toBeNull()
        ->and($row?->id)->toBeInt()
        ->and(Str::isUlid($visitorId))->toBeTrue()
        ->and($row?->visitor_hash)->toBe(hash('sha256', $visitorId))
        ->and($row?->visitor_hash)->not->toContain($visitorId)
        ->and($row?->click_id)->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAV')
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
        ->withUnencryptedCookie('lk_click', '01ARZ3NDEKTSV4RRFFQ69G5FAW')
        ->withUnencryptedCookie('lk_referral', 'cookie-loses')
        ->get('/_linkado-tests/attribution?ref=query-loses')
        ->assertOk();

    expect(p11AttributionRow()?->click_id)->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAW')
        ->and(p11AttributionRow()?->referral_slug)->toBeNull();
});

it('upgrades an existing referral slug to a click', function (): void {
    $visitorId = strtolower((string) Str::ulid());

    $this->withCookie('linkado_visitor', $visitorId)
        ->withUnencryptedCookie('lk_referral', 'first-partner')
        ->get('/_linkado-tests/attribution')
        ->assertOk();

    $this->withCookie('linkado_visitor', $visitorId)
        ->withUnencryptedCookie('lk_click', '01ARZ3NDEKTSV4RRFFQ69G5FAW')
        ->get('/_linkado-tests/attribution')
        ->assertOk();

    expect(p11AttributionRow()?->click_id)->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAW')
        ->and(p11AttributionRow()?->referral_slug)->toBeNull()
        ->and(p11AttributionCount())->toBe(1);
});

it('never downgrades an existing click to a referral slug', function (): void {
    $visitorId = strtolower((string) Str::ulid());

    $this->withCookie('linkado_visitor', $visitorId)
        ->withUnencryptedCookie('lk_click', '01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->get('/_linkado-tests/attribution')
        ->assertOk();

    $this->withCookie('linkado_visitor', $visitorId)
        ->withUnencryptedCookie('lk_referral', 'later-partner')
        ->get('/_linkado-tests/attribution')
        ->assertOk();

    expect(p11AttributionRow()?->click_id)->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->and(p11AttributionRow()?->referral_slug)->toBeNull()
        ->and(p11AttributionCount())->toBe(1);
});

it('starts a new window after expiry without retaining the old click', function (): void {
    $visitorId = strtolower((string) Str::ulid());
    CarbonImmutable::setTestNow('2026-09-21 10:00:00');

    $this->withCookie('linkado_visitor', $visitorId)
        ->withUnencryptedCookie('lk_click', '01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->get('/_linkado-tests/attribution')
        ->assertOk();

    CarbonImmutable::setTestNow('2026-09-21 11:00:00');

    $this->withCookie('linkado_visitor', $visitorId)
        ->withUnencryptedCookie('lk_click', '')
        ->withUnencryptedCookie('lk_referral', 'new-partner')
        ->get('/_linkado-tests/attribution')
        ->assertOk();

    $row = p11AttributionRow();

    expect($row?->click_id)->toBeNull()
        ->and($row?->referral_slug)->toBe('new-partner')
        ->and(CarbonImmutable::parse((string) $row?->captured_at)->equalTo(now()))->toBeTrue()
        ->and(CarbonImmutable::parse((string) $row?->expires_at)->equalTo(now()->addSeconds(120)))->toBeTrue();
});

it('does not capture while tracking is disabled', function (): void {
    config()->set('linkado.features.tracking', false);

    $this->withCookie('linkado_visitor', strtolower((string) Str::ulid()))
        ->withUnencryptedCookie('lk_click', '01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->get('/_linkado-tests/attribution')
        ->assertOk();

    expect(p11AttributionCount())->toBe(0);
});

it('does not capture without a decrypted visitor', function (): void {
    $this->withUnencryptedCookie('lk_click', '01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->get('/_linkado-tests/attribution-without-web')
        ->assertOk();

    expect(p11AttributionCount())->toBe(0);
});

it('rejects whitespace identifiers without capturing attribution', function (): void {
    $this->withCookie('linkado_visitor', strtolower((string) Str::ulid()))
        ->withUnencryptedCookie('lk_click', '   ')
        ->get('/_linkado-tests/attribution?ref=')
        ->assertOk();

    expect(p11AttributionCount())->toBe(0);
});

it('rejects oversized identifiers instead of truncating them', function (string $cookie, string $value): void {
    $this->withCookie('linkado_visitor', strtolower((string) Str::ulid()))
        ->withUnencryptedCookie($cookie, $value)
        ->get('/_linkado-tests/attribution')
        ->assertOk();

    expect(p11AttributionCount())->toBe(0);
})->with([
    'click id' => ['lk_click', str_repeat('c', 300)],
    'Unicode referral slug' => ['lk_referral', str_repeat('ж', 300)],
]);

it('selects referral cookies before query without falling back from malformed cookies', function (mixed $cookie, mixed $query, ?string $expected): void {
    $request = Request::create('/landing', cookies: [
        'linkado_visitor' => (string) Str::ulid(), 'lk_referral' => $cookie,
    ]);
    $request->query->set('ref', $query);
    $calls = 0;
    app(CapturePendingAttribution::class)->handle($request, function () use (&$calls): Response {
        $calls++;

        return response('ok');
    });
    expect($calls)->toBe(1)->and(p11AttributionCount())->toBe($expected === null ? 0 : 1)
        ->and(p11AttributionRow()?->referral_slug)->toBe($expected);
})->with([
    'cookie precedes query' => ['first', 'second', 'first'],
    'null cookie absent' => [null, 'second', 'second'],
    'empty cookie absent' => ['', 'second', 'second'],
    'both absent' => [null, '', null],
    'malformed cookie blocks fallback' => ['INVALID', 'second', null],
    'whitespace cookie blocks fallback' => [' ', 'second', null],
    'array cookie blocks fallback' => [['first'], 'second', null],
    'padded query rejected' => [null, ' second ', null],
    'array query rejected' => [null, ['second'], null],
    'nonselected query ignored' => ['first', ['second'], 'first'],
]);

it('rejects padded query identifiers through the full HTTP middleware stack', function (string $query): void {
    $this->withCookie('linkado_visitor', strtolower((string) Str::ulid()))
        ->get('/_linkado-tests/attribution?ref='.$query)->assertOk();
    expect(p11AttributionCount())->toBe(0);
})->with(['%20partner%20', '%09partner%0A', 'partner%00']);

it('rejects a partial raw query parse while leaving selected cookies and host error handling intact', function (bool $cookieWins): void {
    $request = Request::create('/landing', cookies: [
        'linkado_visitor' => (string) Str::ulid(), 'lk_referral' => $cookieWins ? 'cookie-partner' : null,
    ]);
    $request->query->set('ref', 'trimmed-partner');
    $request->server->set('QUERY_STRING', 'ref=raw-partner&'.implode('&', array_fill(0, (int) ini_get('max_input_vars') + 1, 'extra=value')));
    $warnings = [];
    set_error_handler(function (int $level, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    }, E_USER_WARNING | E_WARNING);

    try {
        app(CapturePendingAttribution::class)->handle($request, function (): Response {
            trigger_error('host-handler-still-active', E_USER_WARNING);

            return response('ok');
        });
    } finally {
        restore_error_handler();
    }
    expect(p11AttributionCount())->toBe($cookieWins ? 1 : 0)
        ->and(p11AttributionRow()?->referral_slug)->toBe($cookieWins ? 'cookie-partner' : null)
        ->and($warnings)->toBe(['host-handler-still-active']);
})->with([false, true]);

it('rejects raw referral arrays even when PHP silently drops excessive nesting', function (int $depth, bool $cookieWins, bool $unrelated, string $separator): void {
    config()->set('linkado.tracking.referral_parameter', 'custom_ref');
    $request = Request::create('/landing', cookies: [
        'linkado_visitor' => (string) Str::ulid(),
        'lk_click' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'lk_referral' => $cookieWins ? 'cookie-partner' : null,
    ]);
    $rawKey = ($unrelated ? 'other' : 'custom.ref').str_repeat('[a]', $depth);
    $request->server->set('QUERY_STRING', 'other=1'.$separator.rawurlencode($rawKey).'=partner');
    app(CapturePendingAttribution::class)->handle($request, fn (): Response => response('ok'));
    expect(p11AttributionCount())->toBe($cookieWins || $unrelated ? 1 : 0);
})->with([1, (int) ini_get('max_input_nesting_level') + 1])->with([false, true])->with([false, true])
    ->with(fn (): array => str_split((string) ini_get('arg_separator.input')));

it('honors the exact configured query separators without treating value text as a source', function (): void {
    $request = Request::create('/landing', cookies: [
        'linkado_visitor' => (string) Str::ulid(), 'lk_click' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
    ]);
    $request->server->set('QUERY_STRING', 'other=x&ref[a]=value');
    app(CapturePendingAttribution::class)->handle($request, fn (): Response => response('ok'));
    expect(p11AttributionCount())->toBe(str_contains((string) ini_get('arg_separator.input'), '&') ? 0 : 1);
});

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
