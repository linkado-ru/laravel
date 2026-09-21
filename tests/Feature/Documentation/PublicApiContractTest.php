<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Linkado\Laravel\Contracts\DeterminesLinkadoEligibility;
use Linkado\Laravel\Contracts\ResolvesLinkadoSsoUser;
use Linkado\Laravel\Http\Middleware\CapturePendingAttribution;
use Linkado\Laravel\Linkado;
use Linkado\Laravel\LinkadoServiceProvider;

const LINKADO_COMMANDS = [
    'linkado:install',
    'linkado:health',
    'linkado:diagnose',
    'linkado:recover',
    'linkado:retry',
    'linkado:prune',
];

const LINKADO_PUBLISH_TAGS = [
    'linkado',
    'linkado-config',
    'linkado-migrations',
    'linkado-lang',
];

const LINKADO_CONFIG_KEYS = [
    'mode',
    'connection',
    'queue',
    'base_url',
    'token',
    'program_key',
    'features.sso',
    'features.tracking',
    'features.customer_events',
    'features.billing_events',
    'features.refund_events',
    'tracking.script_url',
    'tracking.endpoint_url',
    'tracking.referral_parameter',
    'tracking.visitor_cookie',
    'tracking.click_cookie',
    'tracking.referral_cookie',
    'tracking.ttl_seconds',
    'sso.route',
    'sso.middleware',
    'sso.error_redirect',
    'delivery.max_attempts',
    'delivery.claim_timeout_seconds',
    'delivery.base_delay_seconds',
    'delivery.max_delay_seconds',
    'delivery.retry_window_seconds',
];

it('exposes the frozen Linkado public API', function () {
    expect(get_class_methods(Linkado::class))
        ->toContain('record', 'attribution')
        ->and(array_keys(Artisan::all()))
        ->toContain(...LINKADO_COMMANDS);

    $route = Route::getRoutes()->getByName('linkado.sso.launch');

    expect($route)->not->toBeNull()
        ->and($route?->methods())->toContain('POST')
        ->and($route?->gatherMiddleware())->toBe(['web', 'auth', 'throttle:6,1'])
        ->and(app('router')->getMiddleware()['linkado.attribution'] ?? null)
        ->toBe(CapturePendingAttribution::class);

    foreach (LINKADO_PUBLISH_TAGS as $tag) {
        expect(ServiceProvider::pathsToPublish(LinkadoServiceProvider::class, $tag))
            ->not->toBeEmpty("The {$tag} publish tag is not registered.");
    }

    expect(get_class_methods(DeterminesLinkadoEligibility::class))->toBe(['allows'])
        ->and(get_class_methods(ResolvesLinkadoSsoUser::class))->toBe(['resolve']);

    foreach (LINKADO_CONFIG_KEYS as $key) {
        expect(Arr::has(config('linkado'), $key))
            ->toBeTrue("The linkado.{$key} configuration key is missing.");
    }
});

it('documents every frozen public integration promise', function () {
    $readme = file_get_contents(__DIR__.'/../../../README.md');

    expect($readme)->not->toBeFalse();

    $promises = [
        'Linkado::record(',
        'Linkado::attribution()->consume(',
        "route('linkado.sso.launch')",
        'linkado.attribution',
        'DeterminesLinkadoEligibility',
        'ResolvesLinkadoSsoUser',
        ...LINKADO_COMMANDS,
        ...LINKADO_PUBLISH_TAGS,
        ...array_map(static fn (string $key): string => 'linkado.'.$key, LINKADO_CONFIG_KEYS),
    ];

    foreach ($promises as $promise) {
        expect($readme)->toContain($promise);
    }
});
