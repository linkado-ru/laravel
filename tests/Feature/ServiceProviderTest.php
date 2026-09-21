<?php

declare(strict_types=1);

use Linkado\Laravel\Enums\DeliveryMode;
use Linkado\Laravel\Enums\LinkadoFeature;
use Linkado\Laravel\Exceptions\InvalidLinkadoConfiguration;
use Linkado\Laravel\Support\LinkadoConfiguration;
use Linkado\PhpSdk\LinkadoConnector;

it('merges the locked Linkado configuration defaults', function () {
    expect(config('linkado.mode'))->toBe('off')
        ->and(config('linkado.features'))->toBe([
            'sso' => false,
            'tracking' => false,
            'customer_events' => false,
            'billing_events' => false,
            'refund_events' => false,
        ])
        ->and(config('linkado.tracking.referral_parameter'))->toBe('ref')
        ->and(config('linkado.delivery.max_attempts'))->toBe(8);
});

it('reads configuration from Laravel config instead of the process environment', function () {
    config()->set('linkado.mode', 'shadow');
    putenv('LINKADO_MODE=live');

    try {
        expect(app(LinkadoConfiguration::class)->mode())->toBe(DeliveryMode::Shadow);
    } finally {
        putenv('LINKADO_MODE');
    }
});

it('rejects an unknown delivery mode', function () {
    config()->set('linkado.mode', 'eventually');

    expect(fn (): DeliveryMode => app(LinkadoConfiguration::class)->mode())
        ->toThrow(InvalidLinkadoConfiguration::class, 'linkado.mode');
});

it('resolves configuration as a singleton without credentials when disabled', function () {
    config()->set('linkado.mode', 'off');
    config()->set('linkado.token', null);
    config()->set('linkado.base_url', null);

    expect(app(LinkadoConfiguration::class))
        ->toBe(app(LinkadoConfiguration::class))
        ->and(app(LinkadoConfiguration::class)->mode())
        ->toBe(DeliveryMode::Off);
});

it('parses features, connection, queue, and program key from Laravel config', function () {
    config()->set('linkado.connection', 'linkado');
    config()->set('linkado.queue', 'linkado-delivery');
    config()->set('linkado.program_key', 'program-123');
    config()->set('linkado.features.tracking', true);

    $configuration = app(LinkadoConfiguration::class);

    expect($configuration->connection())->toBe('linkado')
        ->and($configuration->queue())->toBe('linkado-delivery')
        ->and($configuration->programKey())->toBe('program-123')
        ->and($configuration->featureEnabled(LinkadoFeature::Tracking))->toBeTrue()
        ->and($configuration->featureEnabled(LinkadoFeature::Sso))->toBeFalse();
});

it('constructs one connector from the configured credentials', function () {
    config()->set('linkado.token', 'test-token');
    config()->set('linkado.base_url', 'https://api.example.test/v1');

    $connector = app(LinkadoConnector::class);

    expect($connector)
        ->toBeInstanceOf(LinkadoConnector::class)
        ->toBe(app(LinkadoConnector::class))
        ->and($connector->resolveBaseUrl())
        ->toBe('https://api.example.test/v1/')
        ->and($connector->__debugInfo())
        ->toBe(['baseUrl' => 'https://api.example.test/v1/'])
        ->not->toHaveKey('token');
});

it('validates connector credentials lazily without exposing the token', function () {
    config()->set('linkado.token', 'token-that-must-not-leak');
    config()->set('linkado.base_url', null);

    expect(app(LinkadoConfiguration::class))->toBeInstanceOf(LinkadoConfiguration::class);

    try {
        app(LinkadoConnector::class);
    } catch (InvalidLinkadoConfiguration $exception) {
        expect($exception->getMessage())->not->toContain('token-that-must-not-leak');

        return;
    }

    throw new LogicException('Resolving the SDK connector without a base URL must fail.');
});

it('rejects unsafe SDK base URLs before constructing a connector', function (string $url): void {
    config()->set('linkado.token', 'private-token');
    config()->set('linkado.base_url', $url);

    expect(fn (): LinkadoConnector => app(LinkadoConnector::class))
        ->toThrow(InvalidLinkadoConfiguration::class, 'Invalid Linkado configuration for [linkado.base_url].');
})->with([
    'http://linkado.test/api/v1',
    'not-a-url',
    'https://user:private-password@linkado.test/api/v1',
    'https://linkado.test/api/v1?token=private-token',
    'https://linkado.test/api/v1#private-token',
]);
