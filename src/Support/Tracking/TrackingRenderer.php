<?php

declare(strict_types=1);

namespace Linkado\Laravel\Support\Tracking;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\Request;
use Linkado\Laravel\Contracts\DeterminesLinkadoEligibility;
use Linkado\Laravel\Enums\DeliveryMode;
use Linkado\Laravel\Enums\LinkadoFeature;
use Linkado\Laravel\Support\EligibilityContext;
use Linkado\Laravel\Support\LinkadoConfiguration;

final readonly class TrackingRenderer
{
    public function __construct(
        private LinkadoConfiguration $configuration,
        private DeterminesLinkadoEligibility $eligibility,
        private Factory $views,
        private Application $application,
        private Request $request,
    ) {}

    public function render(): string
    {
        if ($this->configuration->mode() === DeliveryMode::Off
            || ! $this->configuration->featureEnabled(LinkadoFeature::Tracking)
            || ! $this->eligibility->allows(
                LinkadoFeature::Tracking,
                new EligibilityContext(request: $this->request),
            )) {
            return '';
        }

        $scriptUrl = $this->configuration->trackingScriptUrl();
        $endpointUrl = $this->configuration->trackingEndpointUrl();

        if (! $this->validUrl($scriptUrl) || ! $this->validUrl($endpointUrl)) {
            return '';
        }

        return $this->views->make('linkado::tracking', [
            'scriptUrl' => $scriptUrl,
            'endpointUrl' => $endpointUrl,
            'referralParameter' => $this->configuration->trackingReferralParameter(),
        ])->render();
    }

    private function validUrl(?string $url): bool
    {
        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! is_string($scheme)) {
            return false;
        }

        return strtolower($scheme) === 'https'
            || ($this->application->environment(['local', 'testing']) && strtolower($scheme) === 'http');
    }
}
