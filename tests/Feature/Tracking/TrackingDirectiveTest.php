<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Linkado\Laravel\Contracts\DeterminesLinkadoEligibility;
use Linkado\Laravel\Enums\LinkadoFeature;
use Linkado\Laravel\Support\EligibilityContext;
use Linkado\Laravel\Support\LinkadoConfiguration;

beforeEach(function (): void {
    config()->set('linkado.mode', 'live');
    config()->set('linkado.features.tracking', true);
    config()->set('linkado.program_key', 'public-program');
    config()->set('linkado.tracking.script_url', 'https://cdn.example.test/linkado.js');
    config()->set('linkado.tracking.endpoint_url', 'https://api.example.test/track');
});

it('renders nothing while tracking is disabled by mode feature or eligibility', function (
    string $mode,
    bool $featureEnabled,
    bool $eligible,
): void {
    config()->set('linkado.mode', $mode);
    config()->set('linkado.features.tracking', $featureEnabled);
    app()->instance(DeterminesLinkadoEligibility::class, new readonly class($eligible) implements DeterminesLinkadoEligibility
    {
        public function __construct(private bool $eligible) {}

        public function allows(LinkadoFeature $feature, EligibilityContext $context): bool
        {
            return $this->eligible;
        }
    });

    expect(p10RenderTracking())->toBe('');
})->with([
    'off mode' => ['off', true, true],
    'feature disabled' => ['live', false, true],
    'eligibility denied' => ['live', true, false],
]);

it('renders escaped hosted tracking markup with no inline executable configuration', function (): void {
    config()->set('linkado.tracking.script_url', 'https://cdn.example.test/linkado.js?asset=one&next=two');
    config()->set('linkado.tracking.endpoint_url', 'https://api.example.test/track?channel=web&source=landing');
    config()->set('linkado.tracking.referral_parameter', 'ref" data-injected="yes');

    $html = p10RenderTracking();

    expect($html)->toBe(
        '<script src="https://cdn.example.test/linkado.js?asset=one&amp;next=two" data-endpoint="https://api.example.test/track?channel=web&amp;source=landing" data-program-key="public-program" data-referral-param="ref&quot; data-injected=&quot;yes" data-attribution-window-days="30" data-endpoint-url="https://api.example.test/track?channel=web&amp;source=landing" data-referral-parameter="ref&quot; data-injected=&quot;yes" defer></script>',
    )
        ->and(substr_count($html, '<script'))->toBe(1)
        ->and($html)->not->toContain('<script>')
        ->and($html)->not->toContain('data-injected="yes"');
});

it('rejects non-HTTPS tracking URLs outside local and testing environments', function (
    string $scriptUrl,
    string $endpointUrl,
): void {
    app()->instance('env', 'production');
    config()->set('linkado.tracking.script_url', $scriptUrl);
    config()->set('linkado.tracking.endpoint_url', $endpointUrl);

    expect(p10RenderTracking())->toBe('');
})->with([
    'insecure script' => ['http://cdn.example.test/linkado.js', 'https://api.example.test/track'],
    'insecure endpoint' => ['https://cdn.example.test/linkado.js', 'http://api.example.test/track'],
    'non-URL script' => ['javascript:alert(1)', 'https://api.example.test/track'],
    'non-URL endpoint' => ['https://cdn.example.test/linkado.js', '//api.example.test/track'],
]);

it('allows HTTP tracking URLs in the testing environment', function (): void {
    config()->set('linkado.tracking.script_url', 'http://localhost/linkado.js');
    config()->set('linkado.tracking.endpoint_url', 'http://localhost/track');

    expect(p10RenderTracking())
        ->toContain('src="http://localhost/linkado.js"')
        ->toContain('data-endpoint-url="http://localhost/track"');
});

it('passes the current request to tracking eligibility', function (): void {
    $eligibility = new class implements DeterminesLinkadoEligibility
    {
        public ?Request $request = null;

        public function allows(LinkadoFeature $feature, EligibilityContext $context): bool
        {
            $this->request = $context->request;

            return true;
        }
    };
    app()->instance(DeterminesLinkadoEligibility::class, $eligibility);

    p10RenderTracking();

    expect($eligibility->request)->toBe(request());
});

function p10RenderTracking(): string
{
    $template = '@linkadoTracking'.PHP_EOL.'{{-- '.Str::ulid().' --}}';

    return trim(Blade::render($template, deleteCachedView: true));
}

it('omits hosted tracking when its configuration cannot satisfy the runtime contract', function (string $key, mixed $value): void {
    config()->set('linkado.'.$key, $value);

    expect(p10RenderTracking())->toBe('');
})->with([
    'missing program' => ['program_key', null],
    'empty program' => ['program_key', ''],
    'blank program' => ['program_key', '   '],
    'non-string program' => ['program_key', ['bad']],
    'zero TTL' => ['tracking.ttl_seconds', 0],
    'negative TTL' => ['tracking.ttl_seconds', -86400],
    'less than a day' => ['tracking.ttl_seconds', 86399],
    'fractional days' => ['tracking.ttl_seconds', 86401],
    'float TTL' => ['tracking.ttl_seconds', 86400.5],
    'string TTL' => ['tracking.ttl_seconds', '86400'],
    'null TTL' => ['tracking.ttl_seconds', null],
    'array TTL' => ['tracking.ttl_seconds', []],
    'custom click cookie' => ['tracking.click_cookie', 'custom_click'],
    'custom referral cookie' => ['tracking.referral_cookie', 'custom_referral'],
    'missing click cookie' => ['tracking.click_cookie', null],
    'missing referral cookie' => ['tracking.referral_cookie', null],
    'empty parameter' => ['tracking.referral_parameter', ''],
    'blank parameter' => ['tracking.referral_parameter', '   '],
    'array parameter' => ['tracking.referral_parameter', []],
    'missing script' => ['tracking.script_url', null],
    'missing endpoint' => ['tracking.endpoint_url', null],
    'invalid mode' => ['mode', 'invalid'],
    'invalid feature flag' => ['features.tracking', 'true'],
]);

it('converts only exact whole days without changing the server TTL', function (int $seconds, string $days): void {
    config()->set('linkado.tracking.ttl_seconds', $seconds);

    expect(p10RenderTracking())->toContain('data-attribution-window-days="'.$days.'"')
        ->and(app(LinkadoConfiguration::class)->trackingTtlSeconds())->toBe($seconds);
})->with([[86400, '1'], [172800, '2'], [2592000, '30']]);

it('keeps a positive sub-day TTL usable for server storage while omitting the hosted script', function (): void {
    config()->set('linkado.tracking.ttl_seconds', 120);

    expect(app(LinkadoConfiguration::class)->trackingTtlSeconds())->toBe(120)
        ->and(p10RenderTracking())->toBe('');
});
