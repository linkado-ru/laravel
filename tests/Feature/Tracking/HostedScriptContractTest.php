<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    config()->set('linkado.mode', 'live');
    config()->set('linkado.features.tracking', true);
    config()->set('linkado.program_key', 'public-program-a');
    config()->set('linkado.token', 'synthetic-secret-must-not-render');
    config()->set('linkado.tracking.script_url', 'https://cdn.example.test/tracking.js');
    config()->set('linkado.tracking.endpoint_url', 'https://api.example.test/track');
});

it('starts the actual hosted asset from rendered markup with the configured endpoint and payload', function (): void {
    $html = hostedTrackingHtml();
    $result = hostedTrackingBrowsers([['html' => $html]])[0];

    expect($result['calls'])->toHaveCount(1)
        ->and($result['calls'][0]['url'])->toBe('https://api.example.test/track')
        ->and($result['calls'][0]['body'])->toBe([
            'program_key' => 'public-program-a',
            'referral_slug' => 'first-partner',
        ])
        ->and($html)->not->toContain('synthetic-secret-must-not-render');
});

function hostedTrackingHtml(): string
{
    return trim(Blade::render('@linkadoTracking'.PHP_EOL.'{{-- '.Str::ulid().' --}}', deleteCachedView: true));
}

/**
 * @param  list<array<string, mixed>>  $inputs
 * @return list<array<string, mixed>>
 */
function hostedTrackingBrowsers(array $inputs): array
{
    $process = new Process(['node', __DIR__.'/../../Support/Tracking/run-hosted-script.mjs']);
    $process->setInput(json_encode($inputs, JSON_THROW_ON_ERROR));
    $process->setTimeout(10);
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('writes the provisional referral synchronously and applies server expiry only after a successful response', function (): void {
    $result = hostedTrackingBrowsers([['html' => hostedTrackingHtml()]])[0];

    expect($result['synchronousCookies'])->toBe(['lk_referral' => 'first-partner'])
        ->and($result['synchronousWrites'])->toBe([
            'lk_referral=first-partner; Path=/; Max-Age=2592000; Expires=Wed, 21 Oct 2026 00:00:00 GMT; SameSite=Lax; Secure',
        ])
        ->and($result['cookies'])->toBe([
            'lk_referral' => 'first-partner',
            'lk_click' => '01K5M4W0000000000000000001',
        ])
        ->and($result['writes'])->toHaveCount(3)
        ->and($result['writes'][1])->toBe('lk_click=01K5M4W0000000000000000001; Path=/; Max-Age=864000; Expires=Thu, 01 Oct 2026 00:00:00 GMT; SameSite=Lax; Secure')
        ->and($result['writes'][2])->toBe('lk_referral=first-partner; Path=/; Max-Age=864000; Expires=Thu, 01 Oct 2026 00:00:00 GMT; SameSite=Lax; Secure')
        ->and($result['calls'][0]['cookiesAtFetch'])->toBe(['lk_referral' => 'first-partner'])
        ->and($result['calls'][0]['method'])->toBe('POST')
        ->and($result['calls'][0]['headers'])->toBe(['Content-Type' => 'application/json'])
        ->and($result['calls'][0]['credentials'])->toBe('omit')
        ->and($result['calls'][0]['referrerPolicy'])->toBe('no-referrer')
        ->and($result['delays'])->toBe([2000])
        ->and($result['pendingTimers'])->toBe(0);

    foreach ($result['writes'] as $write) {
        expect($write)->not->toContain('Domain=');
    }
});

it('uses an existing referral cookie ahead of a new query without refreshing it before the response', function (): void {
    $result = hostedTrackingBrowsers([[
        'html' => hostedTrackingHtml(),
        'cookies' => ['lk_referral' => 'original-partner'],
        'search' => '?ref=new-partner',
    ]])[0];

    expect($result['calls'][0]['body']['referral_slug'])->toBe('original-partner')
        ->and($result['synchronousWrites'])->toBe([])
        ->and($result['cookies']['lk_referral'])->toBe('original-partner');
});

it('short circuits when a click already exists or no referral is available', function (array $browser): void {
    $result = hostedTrackingBrowsers([['html' => hostedTrackingHtml(), ...$browser]])[0];

    expect($result['calls'])->toBe([])
        ->and($result['writes'])->toBe([])
        ->and($result['delays'])->toBe([])
        ->and($result['cookies'])->toBe($browser['cookies'] ?? []);
})->with([
    'existing click' => [['cookies' => ['lk_click' => 'existing-click', 'lk_referral' => 'original-partner']]],
    'no referral' => [['search' => '']],
]);

it('preserves provisional referral on transient failures and malformed responses', function (array $response): void {
    $result = hostedTrackingBrowsers([['html' => hostedTrackingHtml(), ...$response]])[0];

    expect($result['calls'])->toHaveCount(1)
        ->and($result['cookies'])->toBe(['lk_referral' => 'first-partner'])
        ->and($result['writes'])->toBe($result['synchronousWrites'])
        ->and($result['writes'])->toHaveCount(1)
        ->and($result['pendingTimers'])->toBe(0)
        ->and($result['aborts'])->toBe(($response['failure'] ?? null) === 'timeout' ? 1 : 0);
})->with([
    'network error' => [['failure' => 'network']],
    'timeout' => [['failure' => 'timeout']],
    'invalid JSON' => [['failure' => 'json']],
    'rate limited' => [['status' => 429]],
    'server error' => [['status' => 500]],
    'unavailable' => [['status' => 503]],
    'null body' => [['payload' => null]],
    'missing data' => [['payload' => []]],
    '201 null is not definitive' => [['payload' => ['data' => null]]],
    'missing click' => [['payload' => ['data' => ['expires_at' => '2026-10-01T00:00:00Z']]]],
    'invalid expiry' => [['payload' => ['data' => ['click_id' => 'click', 'expires_at' => 'bad']]]],
    'expired reply' => [['payload' => ['data' => ['click_id' => 'click', 'expires_at' => '2026-09-21T00:00:00Z']]]],
    '200 success-shaped payload' => [['status' => 200]],
]);

it('deletes the referral only on a definitive null response', function (): void {
    $result = hostedTrackingBrowsers([[
        'html' => hostedTrackingHtml(),
        'status' => 200,
        'payload' => ['data' => null],
    ]])[0];

    expect($result['calls'])->toHaveCount(1)
        ->and($result['cookies'])->toBe([])
        ->and($result['writes'])->toHaveCount(2)
        ->and($result['writes'][1])->toBe('lk_referral=; Path=/; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; SameSite=Lax; Secure')
        ->and($result['pendingTimers'])->toBe(0);
});

it('round trips escaped public values from actual HTML without executable injection or secret disclosure', function (): void {
    config()->set('linkado.program_key', 'public"<&\'key');
    config()->set('linkado.tracking.endpoint_url', 'https://api.example.test/track?a=1&b=2');
    config()->set('linkado.tracking.referral_parameter', 'ref"<&\'name');
    $html = hostedTrackingHtml();
    $result = hostedTrackingBrowsers([[
        'html' => $html,
        'search' => '?'.http_build_query(['ref"<&\'name' => 'escaped-partner']),
    ]])[0];
    $document = new DOMDocument;
    $document->loadHTML($html);
    $script = $document->getElementsByTagName('script')->item(0);

    expect($result['calls'])->toHaveCount(1)
        ->and($result['calls'][0]['url'])->toBe('https://api.example.test/track?a=1&b=2')
        ->and($result['calls'][0]['body'])->toBe(['program_key' => 'public"<&\'key', 'referral_slug' => 'escaped-partner'])
        ->and($document->getElementsByTagName('script'))->toHaveCount(1)
        ->and($script?->textContent)->toBe('')
        ->and($script?->attributes)->toHaveCount(8)
        ->and($html)->not->toContain('synthetic-secret-must-not-render')
        ->and($html)->toContain('defer');
});

it('isolates config and cookies between two simultaneously executed browser contexts', function (): void {
    $first = hostedTrackingHtml();
    config()->set('linkado.program_key', 'public-program-b');
    config()->set('linkado.tracking.referral_parameter', 'partner');
    config()->set('linkado.tracking.endpoint_url', 'https://other.example.test/track');
    config()->set('linkado.tracking.ttl_seconds', 86400);
    [$a, $b] = hostedTrackingBrowsers([
        ['html' => $first, 'cookies' => ['lk_referral' => 'cookie-a'], 'failure' => 'network'],
        ['html' => hostedTrackingHtml(), 'search' => '?ref=ignored&partner=query-b', 'failure' => 'network'],
    ]);

    expect($a['calls'][0]['url'])->toBe('https://api.example.test/track')
        ->and($a['calls'][0]['body'])->toBe(['program_key' => 'public-program-a', 'referral_slug' => 'cookie-a'])
        ->and($a['cookies'])->toBe(['lk_referral' => 'cookie-a'])
        ->and($a['writes'])->toBe([])
        ->and($b['calls'][0]['url'])->toBe('https://other.example.test/track')
        ->and($b['calls'][0]['body'])->toBe(['program_key' => 'public-program-b', 'referral_slug' => 'query-b'])
        ->and($b['cookies'])->toBe(['lk_referral' => 'query-b'])
        ->and($b['writes'][0])->toBe('lk_referral=query-b; Path=/; Max-Age=86400; Expires=Tue, 22 Sep 2026 00:00:00 GMT; SameSite=Lax; Secure');
});

it('uses host only non-secure cookies on a local HTTP page', function (): void {
    config()->set('linkado.tracking.script_url', 'http://localhost/tracking.js');
    config()->set('linkado.tracking.endpoint_url', 'http://localhost/track');
    $result = hostedTrackingBrowsers([['html' => hostedTrackingHtml(), 'protocol' => 'http:']])[0];

    expect($result['calls'][0]['url'])->toBe('http://localhost/track')
        ->and($result['writes'])->toHaveCount(3);
    foreach ($result['writes'] as $write) {
        expect($write)->toContain('Path=/')->toContain('SameSite=Lax')
            ->not->toContain('Secure')->not->toContain('Domain=');
    }
});
