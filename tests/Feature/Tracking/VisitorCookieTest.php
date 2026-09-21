<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Linkado\Laravel\Http\Middleware\EnsureLinkadoVisitor;
use Symfony\Component\HttpFoundation\Cookie;

beforeEach(function (): void {
    config()->set('linkado.mode', 'live');
    config()->set('linkado.features.tracking', true);

    Route::middleware(['web'])->get('/_linkado-tests/visitor', fn (Request $request) => response()->json([
        'visitor' => $request->cookie('linkado_visitor'),
        'click' => $request->cookie('lk_click'),
        'referral' => $request->cookie('lk_referral'),
    ]));

    Route::middleware(['web'])->get('/_linkado-tests/browser-cookies', function () {
        $response = response('ok');
        $response->headers->setCookie(Cookie::create('lk_click', 'click-42'));
        $response->headers->setCookie(Cookie::create('lk_referral', 'partner'));
        $response->headers->setCookie(Cookie::create('application_cookie', 'private'));

        return $response;
    });
});

it('registers visitor management on the web middleware group', function (): void {
    expect(app(Router::class)->getMiddlewareGroups()['web'] ?? [])
        ->toContain(EnsureLinkadoVisitor::class);
});

it('does not issue a visitor cookie while tracking is inactive', function (
    string $mode,
    bool $featureEnabled,
): void {
    config()->set('linkado.mode', $mode);
    config()->set('linkado.features.tracking', $featureEnabled);

    $this->get('/_linkado-tests/visitor')
        ->assertOk()
        ->assertCookieMissing('linkado_visitor');
})->with([
    'off mode' => ['off', true],
    'feature disabled' => ['live', false],
]);

it('creates an encrypted HttpOnly SameSite Lax ULID visitor cookie', function (): void {
    $response = $this->get('/_linkado-tests/visitor')->assertOk();

    $visitor = $response->getCookie('linkado_visitor');
    $encrypted = $response->getCookie('linkado_visitor', false);

    expect($visitor)->not->toBeNull()
        ->and($visitor?->getValue())->toBeString()
        ->and(Str::isUlid((string) $visitor?->getValue()))->toBeTrue()
        ->and($response->json('visitor'))->toBe($visitor?->getValue())
        ->and($encrypted?->getValue())->not->toBe($visitor?->getValue())
        ->and($visitor?->isHttpOnly())->toBeTrue()
        ->and($visitor?->getSameSite())->toBe(Cookie::SAMESITE_LAX)
        ->and($visitor?->isSecure())->toBeFalse();
});

it('marks the visitor cookie secure on HTTPS requests', function (): void {
    $response = $this->get('https://localhost/_linkado-tests/visitor')->assertOk();

    expect($response->getCookie('linkado_visitor')?->isSecure())->toBeTrue();
});

it('reuses a valid decrypted visitor ULID without rotating it', function (): void {
    $first = $this->get('/_linkado-tests/visitor')->assertOk();
    $visitorId = (string) $first->getCookie('linkado_visitor')?->getValue();

    expect(Str::isUlid($visitorId))->toBeTrue();

    $this->withCookie('linkado_visitor', $visitorId)
        ->get('/_linkado-tests/visitor')
        ->assertOk()
        ->assertJsonPath('visitor', $visitorId)
        ->assertCookieMissing('linkado_visitor');
});

it('rotates tampered and invalid visitor cookies', function (
    bool $encryptedByTestClient,
    string $invalidValue,
): void {
    $test = $encryptedByTestClient
        ? $this->withCookie('linkado_visitor', $invalidValue)
        : $this->withUnencryptedCookie('linkado_visitor', $invalidValue);

    $response = $test->get('/_linkado-tests/visitor')->assertOk();
    $visitorId = (string) $response->getCookie('linkado_visitor')?->getValue();

    expect(Str::isUlid($visitorId))->toBeTrue()
        ->and($visitorId)->not->toBe($invalidValue)
        ->and($response->json('visitor'))->toBe($visitorId);
})->with([
    'tampered ciphertext' => [false, 'tampered-cookie-value'],
    'encrypted non-ULID' => [true, 'not-a-ulid'],
]);

it('leaves only hosted-script attribution cookies browser-readable', function (): void {
    $response = $this->get('/_linkado-tests/browser-cookies')->assertOk();

    $response->assertPlainCookie('lk_click', 'click-42')
        ->assertPlainCookie('lk_referral', 'partner')
        ->assertCookie('application_cookie', 'private');

    $visitor = $response->getCookie('linkado_visitor');
    $encryptedVisitor = $response->getCookie('linkado_visitor', false);

    expect($visitor?->getValue())->toBeString()
        ->and($encryptedVisitor?->getValue())->not->toBe($visitor?->getValue());
});
