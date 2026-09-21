<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Linkado\Laravel\Contracts\DeterminesLinkadoEligibility;
use Linkado\Laravel\Enums\LinkadoFeature;
use Linkado\Laravel\Support\EligibilityContext;

beforeEach(function (): void {
    config()->set('linkado.mode', 'live');
    config()->set('linkado.features.tracking', true);
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
        '<script src="https://cdn.example.test/linkado.js?asset=one&amp;next=two" data-endpoint-url="https://api.example.test/track?channel=web&amp;source=landing" data-referral-parameter="ref&quot; data-injected=&quot;yes" defer></script>',
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
