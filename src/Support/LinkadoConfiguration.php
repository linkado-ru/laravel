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

    /**
     * @api Retains the nullable v1 getter signature; configured v2 URLs always resolve.
     *
     * @phpstan-ignore return.unusedType (Preserve the public nullable v1 signature while v2 resolves a URL by default.)
     */
    public function trackingScriptUrl(): ?string
    {
        [$origin] = $this->resolvedUrls();

        return $this->nullableString('tracking.script_url') ?? $origin.'/build/tracking.js';
    }

    /**
     * @api Retains the nullable v1 getter signature; configured v2 URLs always resolve.
     *
     * @phpstan-ignore return.unusedType (Preserve the public nullable v1 signature while v2 resolves a URL by default.)
     */
    public function trackingEndpointUrl(): ?string
    {
        [, $api] = $this->resolvedUrls();

        return $this->nullableString('tracking.endpoint_url') ?? $api.'/tracking/clicks';
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
        [, $api] = $this->resolvedUrls();

        return $api;
    }

    public function enabled(LinkadoFeature $feature): bool
    {
        try {
            $mode = $this->mode();

            if ($mode === DeliveryMode::Off || ! $this->featureEnabled($feature)
                || ($feature === LinkadoFeature::Sso && $mode !== DeliveryMode::Live)) {
                return false;
            }

            $this->requiredProgramKey();
            $this->resolvedUrls();

            if ($mode === DeliveryMode::Live) {
                $this->requiredToken();
            }

            if ($feature === LinkadoFeature::Tracking) {
                $this->trackingTtlSeconds();
                $this->trackingReferralParameter();
                $this->trackingVisitorCookie();
                $this->trackingClickCookie();
                $this->trackingReferralCookie();
            }

            return true;
        } catch (InvalidLinkadoConfiguration) {
            return false;
        }
    }

    public function liveDeliveryConfigured(): bool
    {
        try {
            $this->requiredToken();
            $this->requiredProgramKey();
            $this->resolvedUrls();

            return true;
        } catch (InvalidLinkadoConfiguration) {
            return false;
        }
    }

    /** @return array{errors: list<array{code: string, setting: string}>, warnings: list<array{code: string, setting: string}>} */
    public function configurationIssues(): array
    {
        $errors = [];
        $warnings = [];
        $checks = [
            'mode' => $this->mode(...),
            'connection' => $this->connection(...),
            'queue' => $this->queue(...),
        ];

        foreach (LinkadoFeature::cases() as $feature) {
            $checks['features.'.$feature->value] = fn () => $this->featureEnabled($feature);
        }

        foreach (['url', 'base_url', 'tracking.script_url', 'tracking.endpoint_url'] as $key) {
            $checks[$key] = function () use ($key): void {
                $url = $this->nullableString($key);

                if ($url !== null) {
                    $this->validateUrl($key, $url);
                }
            };
        }

        try {
            $mode = $this->mode();

            if ($mode !== DeliveryMode::Off) {
                $checks['program_key'] = fn () => $this->requiredProgramKey();

                if ($mode === DeliveryMode::Live) {
                    $checks['token'] = fn () => $this->requiredToken();
                }

                if ($this->featureEnabled(LinkadoFeature::Tracking)) {
                    $checks['tracking.referral_parameter'] = fn () => $this->trackingReferralParameter();
                    $checks['tracking.ttl_seconds'] = function () use ($mode): void {
                        $ttl = $this->trackingTtlSeconds();

                        if ($mode === DeliveryMode::Live && $ttl % 86400 !== 0) {
                            throw $this->invalid('tracking.ttl_seconds');
                        }
                    };
                    foreach (['click_cookie' => 'lk_click', 'referral_cookie' => 'lk_referral'] as $key => $expected) {
                        $checks['tracking.'.$key] = function () use ($key, $expected, $mode): void {
                            if ($this->trackingString($key) !== $expected && $mode === DeliveryMode::Live) {
                                throw $this->invalid('tracking.'.$key);
                            }
                        };
                    }
                    $checks['tracking.visitor_cookie'] = fn () => $this->trackingVisitorCookie();
                }
            }
        } catch (InvalidLinkadoConfiguration) {
            // The individual mode and feature checks below identify the invalid setting.
        }

        foreach ($checks as $key => $check) {
            try {
                $check();
            } catch (InvalidLinkadoConfiguration) {
                $value = $this->config->get('linkado.'.$key);
                $errors[] = [
                    'code' => $value === null || $value === '' ? 'missing_setting' : 'invalid_setting',
                    'setting' => 'linkado.'.$key,
                ];
            }
        }

        if ($this->config->get('linkado.url') !== null && $this->config->get('linkado.url') !== ''
            && $this->config->get('linkado.base_url') !== null && $this->config->get('linkado.base_url') !== '') {
            $warnings[] = ['code' => 'ignored_setting', 'setting' => 'linkado.base_url'];
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /** @return array{string, string} */
    private function resolvedUrls(): array
    {
        $origin = $this->nullableString('url');
        $legacy = $this->nullableString('base_url');

        foreach (['url', 'base_url', 'tracking.script_url', 'tracking.endpoint_url'] as $key) {
            $value = $this->nullableString($key);

            if ($value !== null) {
                $this->validateUrl($key, $value);
            }
        }

        if ($origin !== null) {
            $origin = rtrim($origin, '/');

            return [$origin, $origin.'/api/v1'];
        }

        if ($legacy !== null) {
            $parts = parse_url($legacy);

            if (! is_array($parts) || ! isset($parts['host'])) {
                throw $this->invalid('base_url');
            }

            $origin = 'https://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

            return [$origin, rtrim($legacy, '/')];
        }

        return ['https://app.linkado.ru', 'https://app.linkado.ru/api/v1'];
    }

    private function validateUrl(string $key, string $url): void
    {
        $parts = parse_url($url);
        $tracking = str_starts_with($key, 'tracking.');
        $schemes = $tracking && app()->environment(['local', 'testing']) ? ['https', 'http'] : ['https'];

        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || str_contains($url, '\\')
            || preg_match('/[\x00-\x20\x7f]|%(?:0[0-9a-f]|1[0-9a-f]|7f)/i', $url) === 1
            || ! is_array($parts)
            || ! in_array(strtolower($parts['scheme'] ?? ''), $schemes, true)
            || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || (! $tracking && (isset($parts['query']) || isset($parts['fragment'])))
            || ($key === 'url' && ! in_array($parts['path'] ?? '', ['', '/'], true))) {
            throw $this->invalid($key);
        }
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

        if (! is_string($value) || trim($value) === '') {
            throw $this->invalid($key);
        }

        return $value;
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->config->get('linkado.'.$key);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
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

        if (! is_string($value) || trim($value) === '') {
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
