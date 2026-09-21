<?php

declare(strict_types=1);

namespace Linkado\Laravel\Support;

use Illuminate\Contracts\Config\Repository;
use Linkado\Laravel\Enums\DeliveryMode;
use Linkado\Laravel\Enums\LinkadoFeature;
use Linkado\Laravel\Exceptions\InvalidLinkadoConfiguration;

final readonly class LinkadoConfiguration
{
    public function __construct(private Repository $config) {}

    public function mode(): DeliveryMode
    {
        $mode = $this->string('mode');

        return DeliveryMode::tryFrom($mode)
            ?? throw $this->invalid('mode');
    }

    public function connection(): ?string
    {
        return $this->nullableString('connection');
    }

    public function queue(): ?string
    {
        return $this->nullableString('queue');
    }

    public function programKey(): ?string
    {
        return $this->nullableString('program_key');
    }

    public function featureEnabled(LinkadoFeature $feature): bool
    {
        $value = $this->config->get('linkado.features.'.$feature->value);

        if (! is_bool($value)) {
            throw $this->invalid('features.'.$feature->value);
        }

        return $value;
    }

    public function trackingScriptUrl(): ?string
    {
        return $this->optionalTrackingString('script_url');
    }

    public function trackingEndpointUrl(): ?string
    {
        return $this->optionalTrackingString('endpoint_url');
    }

    public function trackingReferralParameter(): string
    {
        return $this->trackingString('referral_parameter');
    }

    public function trackingVisitorCookie(): string
    {
        return $this->trackingString('visitor_cookie');
    }

    public function trackingClickCookie(): string
    {
        return $this->trackingString('click_cookie');
    }

    public function trackingReferralCookie(): string
    {
        return $this->trackingString('referral_cookie');
    }

    public function trackingTtlSeconds(): int
    {
        $value = $this->config->get('linkado.tracking.ttl_seconds');

        if (! is_int($value) || $value < 1) {
            throw $this->invalid('tracking.ttl_seconds');
        }

        return $value;
    }

    public function requiredToken(): string
    {
        return $this->string('token');
    }

    public function requiredProgramKey(): string
    {
        return $this->string('program_key');
    }

    public function requiredBaseUrl(): string
    {
        $url = $this->string('base_url');
        $parts = parse_url($url);

        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || ! is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw $this->invalid('base_url');
        }

        return $url;
    }

    public function ssoRouteName(): string
    {
        return $this->ssoString('route');
    }

    /** @return list<string> */
    public function ssoMiddleware(): array
    {
        $middleware = $this->config->get('linkado.sso.middleware');

        if (! is_array($middleware)
            || $middleware === []
            || array_is_list($middleware) === false) {
            throw $this->invalid('sso.middleware');
        }

        foreach ($middleware as $item) {
            if (! is_string($item) || $item === '') {
                throw $this->invalid('sso.middleware');
            }
        }

        return $middleware;
    }

    public function ssoErrorRedirect(): string
    {
        $redirect = $this->ssoString('error_redirect');

        return str_starts_with($redirect, '/')
            && ! str_starts_with($redirect, '//')
            && ! str_contains($redirect, '\\')
            && preg_match('/[\x00-\x20\x7f]/', $redirect) === 0
                ? $redirect
                : '/';
    }

    public function deliveryClaimTimeoutSeconds(): int
    {
        return $this->deliveryPositiveInteger('claim_timeout_seconds');
    }

    public function deliveryMaxAttempts(): int
    {
        return $this->deliveryPositiveInteger('max_attempts');
    }

    public function deliveryBaseDelaySeconds(): int
    {
        return $this->deliveryPositiveInteger('base_delay_seconds');
    }

    public function deliveryMaxDelaySeconds(): int
    {
        return $this->deliveryPositiveInteger('max_delay_seconds');
    }

    public function deliveryRetryWindowSeconds(): int
    {
        return $this->deliveryPositiveInteger('retry_window_seconds');
    }

    private function string(string $key): string
    {
        $value = $this->config->get('linkado.'.$key);

        if (! is_string($value) || $value === '') {
            throw $this->invalid($key);
        }

        return $value;
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->config->get('linkado.'.$key);

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || $value === '') {
            throw $this->invalid($key);
        }

        return $value;
    }

    private function optionalTrackingString(string $key): ?string
    {
        $value = $this->config->get('linkado.tracking.'.$key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function trackingString(string $key): string
    {
        $value = $this->optionalTrackingString($key);

        if ($value === null) {
            throw $this->invalid('tracking.'.$key);
        }

        return $value;
    }

    private function ssoString(string $key): string
    {
        $value = $this->config->get('linkado.sso.'.$key);

        if (! is_string($value) || $value === '') {
            throw $this->invalid('sso.'.$key);
        }

        return $value;
    }

    private function deliveryPositiveInteger(string $key): int
    {
        $value = $this->config->get('linkado.delivery.'.$key);

        if (! is_int($value) || $value < 1) {
            throw $this->invalid('delivery.'.$key);
        }

        return $value;
    }

    private function invalid(string $key): InvalidLinkadoConfiguration
    {
        return new InvalidLinkadoConfiguration("Invalid Linkado configuration for [linkado.{$key}].");
    }
}
