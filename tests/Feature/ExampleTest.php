<?php

declare(strict_types=1);

use Linkado\Laravel\Linkado;

it('resolves the singleton', function () {
    expect(app(Linkado::class))->toBeInstanceOf(Linkado::class);
});

it('returns the same instance from the container', function () {
    expect(app(Linkado::class))->toBe(app(Linkado::class));
});

it('merges the package config', function () {
    expect(config('linkado.mode'))->toBe('off');
});

it('loads the package translations', function () {
    expect(trans('linkado::messages.sso_failed'))->toBe('Unable to open Linkado right now. Please try again.');
});

it('loads the package views', function () {
    expect(view()->exists('linkado::tracking'))->toBeTrue();
});
