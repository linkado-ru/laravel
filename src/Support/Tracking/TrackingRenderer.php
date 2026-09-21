<?php

declare(strict_types=1);

namespace Linkado\Laravel\Support\Tracking;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Linkado\Laravel\Exceptions\InvalidLinkadoConfiguration;
use Linkado\Laravel\Support\LinkadoConfiguration;

final readonly class TrackingRenderer
{
    public function __construct(
        private LinkadoConfiguration $configuration,
        private TrackingGate $tracking,
        private Factory $views,
        private Application $application,
    ) {}

    public function render(): string
    {
        try {
            return $this->renderConfiguredScript();
        } catch (InvalidLinkadoConfiguration) {
            return '';
        }
    }

    private function renderConfiguredScript(): string
    {
        if (! $this->tracking->allows(request())) {
            return '';
        }

        $scriptUrl = $this->configuration->trackingScriptUrl();
        $endpointUrl = $this->configuration->trackingEndpointUrl();
        $programKey = $this->configuration->requiredProgramKey();
        $ttlSeconds = $this->configuration->trackingTtlSeconds();
        $referralParameter = $this->configuration->trackingReferralParameter();

        if (! $this->validUrl($scriptUrl) || ! $this->validUrl($endpointUrl)
            || trim($programKey) === ''
            || trim($referralParameter) === ''
            || $ttlSeconds % 86400 !== 0
            || $this->configuration->trackingClickCookie() !== 'lk_click'
            || $this->configuration->trackingReferralCookie() !== 'lk_referral') {
            return '';
        }

        return $this->views->make('linkado::tracking', [
            'scriptUrl' => $scriptUrl,
            'endpointUrl' => $endpointUrl,
            'programKey' => $programKey,
            'attributionWindowDays' => intdiv($ttlSeconds, 86400),
            'referralParameter' => $referralParameter,
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
