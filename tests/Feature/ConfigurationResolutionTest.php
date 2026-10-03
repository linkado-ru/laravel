<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Linkado\Laravel\Contracts\ResolvesLinkadoSsoUser;
use Linkado\Laravel\Enums\DeliveryMode;
use Linkado\Laravel\Enums\LinkadoFeature;
use Linkado\Laravel\Exceptions\InvalidLinkadoConfiguration;
use Linkado\Laravel\Facades\Linkado;
use Linkado\Laravel\Support\LinkadoConfiguration;

beforeEach(function (): void {
    config()->set('linkado', $this->packageDefaults());
    config()->set('linkado.token', 'synthetic-v2-token');
    config()->set('linkado.program_key', 'synthetic-program');
    app()->instance('env', 'production');
});

it('connects to production with only two credentials', function (): void {
    // Act
    $configuration = app(LinkadoConfiguration::class);

    // Assert
    expect($configuration->mode())->toBe(DeliveryMode::Live)
        ->and($configuration->requiredBaseUrl())->toBe('https://app.linkado.ru/api/v1')
        ->and($configuration->trackingScriptUrl())->toBe('https://app.linkado.ru/build/tracking.js')
        ->and($configuration->trackingEndpointUrl())->toBe('https://app.linkado.ru/api/v1/tracking/clicks')
        ->and($configuration->trackingReferralParameter())->toBe('via')
        ->and($configuration->trackingTtlSeconds())->toBe(5184000);
    foreach (LinkadoFeature::cases() as $feature) {
        expect(Linkado::enabled($feature))->toBe($feature !== LinkadoFeature::Sso);
    }
});

it('resolves origins and preserves legacy API prefixes', function (?string $origin, ?string $legacy, string $api, string $asset): void {
    // Arrange
    config()->set('linkado.url', $origin);
    config()->set('linkado.base_url', $legacy);

    // Act & Assert
    $configuration = app(LinkadoConfiguration::class);
    expect($configuration->requiredBaseUrl())->toBe($api)
        ->and($configuration->trackingScriptUrl())->toBe($asset)
        ->and($configuration->trackingEndpointUrl())->toBe($api.'/tracking/clicks');
})->with([
    'port and trailing slash' => ['https://linkado.test:8443/', null, 'https://linkado.test:8443/api/v1', 'https://linkado.test:8443/build/tracking.js'],
    'legacy prefix' => [null, 'https://legacy.test/custom/api', 'https://legacy.test/custom/api', 'https://legacy.test/build/tracking.js'],
    'new takes precedence' => ['https://chosen.test', 'https://legacy.test/api', 'https://chosen.test/api/v1', 'https://chosen.test/build/tracking.js'],
    'blank defaults' => ['', '', 'https://app.linkado.ru/api/v1', 'https://app.linkado.ru/build/tracking.js'],
]);

it('rejects invalid URL settings even when legacy configuration is ignored', function (string $key, mixed $value): void {
    // Arrange
    config()->set('linkado.url', 'https://chosen.test');
    config()->set('linkado.'.$key, $value);

    // Act & Assert
    expect(fn () => app(LinkadoConfiguration::class)->requiredBaseUrl())
        ->toThrow(InvalidLinkadoConfiguration::class, 'linkado.'.$key);
})->with([
    'path' => ['url', 'https://wrong.test/api/v1'],
    'query' => ['url', 'https://wrong.test/?key=x'],
    'fragment' => ['url', 'https://wrong.test/#x'],
    'userinfo' => ['url', 'https://user:secret@wrong.test'],
    'HTTP' => ['url', 'http://wrong.test'],
    'whitespace' => ['url', ' https://wrong.test'],
    'encoded control' => ['base_url', 'https://wrong.test/api/%0a'],
    'ignored legacy' => ['base_url', 'http://legacy.test/api'],
    'invalid advanced' => ['tracking.script_url', ['invalid']],
]);

it('keeps independent CDN and proxy URLs without changing the API environment', function (): void {
    config()->set('linkado.url', 'https://linkado.test');
    config()->set('linkado.tracking.script_url', 'https://cdn.test/tracking.js?v=2');
    config()->set('linkado.tracking.endpoint_url', 'https://proxy.test/clicks?merchant=1');
    $configuration = app(LinkadoConfiguration::class);
    expect($configuration->requiredBaseUrl())->toBe('https://linkado.test/api/v1')
        ->and($configuration->trackingScriptUrl())->toBe('https://cdn.test/tracking.js?v=2')
        ->and($configuration->trackingEndpointUrl())->toBe('https://proxy.test/clicks?merchant=1');
});

it('fails closed without each live credential and never renders a hosted asset', function (string $key): void {
    config()->set('linkado.'.$key, '');
    foreach (LinkadoFeature::cases() as $feature) {
        expect(Linkado::enabled($feature))->toBeFalse();
    }
    expect(Blade::render('@linkadoTracking{{-- '.bin2hex(random_bytes(8)).' --}}', deleteCachedView: true))->toBe('');
})->with(['token', 'program_key']);

it('allows local shadow features with a program key but no token or hosted HTTP', function (): void {
    config()->set('linkado.mode', 'shadow');
    config()->set('linkado.token', null);
    config()->set('linkado.features.sso', true);
    expect(Linkado::enabled(LinkadoFeature::CustomerEvents))->toBeTrue()
        ->and(Linkado::enabled(LinkadoFeature::Tracking))->toBeTrue()
        ->and(Linkado::enabled(LinkadoFeature::Sso))->toBeFalse()
        ->and(Blade::render('@linkadoTracking{{-- '.bin2hex(random_bytes(8)).' --}}', deleteCachedView: true))->toBe('');
});

it('normalizes blank optional connection and queue names', function (): void {
    config()->set('linkado.connection', '');
    config()->set('linkado.queue', '');
    expect(app(LinkadoConfiguration::class)->connection())->toBeNull()
        ->and(app(LinkadoConfiguration::class)->queue())->toBeNull();
});

it('isolates each test application from shared or production configuration caches', function (): void {
    // Act & Assert
    expect(app()->getCachedConfigPath())->toStartWith(sys_get_temp_dir().'/linkado-testing-config-'.getmypid().'-');
});

it('diagnoses missing setup safely before migrations without exposing credentials', function (): void {
    // Arrange
    config()->set('linkado.token', '');
    config()->set('linkado.program_key', '');
    config()->set('linkado.connection', 'unconfigured-database');

    // Act
    Artisan::call('linkado:diagnose', ['--json' => true]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    // Assert
    expect($report['configuration']['errors'])->toContain(
        ['code' => 'missing_setting', 'setting' => 'linkado.token'],
        ['code' => 'missing_setting', 'setting' => 'linkado.program_key'],
    )->and($report['database'])->toBe('unavailable')
        ->and($report['counts']['pending'])->toBeNull();
});

it('reports ignored legacy configuration by setting name only', function (): void {
    config()->set('linkado.url', 'https://chosen.test');
    config()->set('linkado.base_url', 'https://legacy.test/api');
    expect(app(LinkadoConfiguration::class)->configurationIssues()['warnings'])->toBe([
        ['code' => 'ignored_setting', 'setting' => 'linkado.base_url'],
    ]);
});

it('prints ignored legacy configuration in ordinary diagnostics without values', function (): void {
    // Arrange
    config()->set('linkado.url', 'https://chosen.test');
    config()->set('linkado.base_url', 'https://legacy.test/api');
    config()->set('linkado.connection', 'unconfigured-database');

    // Act
    Artisan::call('linkado:diagnose');

    // Assert
    expect(Artisan::output())->toContain('ignored_setting: linkado.base_url')
        ->not->toContain('https://legacy.test', 'synthetic-v2-token');
});

it('reports the required SSO resolver safely when SSO is explicitly enabled', function (): void {
    // Arrange
    config()->set('linkado.features.sso', true);

    // Act
    Artisan::call('linkado:health', ['--json' => true]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    // Assert
    expect($report['configuration']['errors'])->toContain([
        'code' => 'missing_sso_resolver',
        'setting' => ResolvesLinkadoSsoUser::class,
    ]);
});

it('fails health for invalid off-mode configuration without checking the database', function (): void {
    // Arrange
    config()->set('linkado.mode', 'off');
    config()->set('linkado.url', 'https://user:private-value@invalid.test');
    config()->set('linkado.connection', 'unconfigured-database');

    // Act
    $exitCode = Artisan::call('linkado:health', ['--json' => true]);
    $output = Artisan::output();
    $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    // Assert
    expect($exitCode)->toBe(2)
        ->and($report['status'])->toBe('failure')
        ->and($report['checks'][0])->toBe(['code' => 'configuration', 'status' => 'failure', 'count' => 1])
        ->and($report['checks'][1]['status'])->toBe('not_applicable')
        ->and($report['configuration']['errors'])->toContain(['code' => 'invalid_setting', 'setting' => 'linkado.url'])
        ->and($output)->not->toContain('private-value', 'synthetic-v2-token');
});

it('disables tracking with invalid basic settings while leaving customer availability independent', function (string $key, mixed $value): void {
    // Arrange
    config()->set('linkado.tracking.'.$key, $value);

    // Act & Assert
    expect(Linkado::enabled(LinkadoFeature::Tracking))->toBeFalse()
        ->and(Linkado::enabled(LinkadoFeature::CustomerEvents))->toBeTrue();
})->with([
    'zero TTL' => ['ttl_seconds', 0],
    'blank parameter' => ['referral_parameter', ''],
    'blank visitor cookie' => ['visitor_cookie', ''],
    'blank click cookie' => ['click_cookie', ''],
    'blank referral cookie' => ['referral_cookie', ''],
]);

it('keeps positive-second TTL and custom source cookies available for shadow capture', function (): void {
    // Arrange
    config()->set('linkado.mode', 'shadow');
    config()->set('linkado.tracking.ttl_seconds', 90);
    config()->set('linkado.tracking.click_cookie', 'custom_click');
    config()->set('linkado.tracking.referral_cookie', 'custom_referral');

    // Act & Assert
    expect(Linkado::enabled(LinkadoFeature::Tracking))->toBeTrue();
});
