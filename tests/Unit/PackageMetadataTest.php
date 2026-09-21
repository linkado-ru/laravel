<?php

declare(strict_types=1);

it('targets the locked runtime matrix', function () {
    $composer = json_decode(
        (string) file_get_contents(__DIR__.'/../../composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['name'])->toBe('linkado-ru/laravel')
        ->and($composer['require']['php'])->toBe('^8.3')
        ->and($composer['require']['illuminate/support'])->toBe('^13.0')
        ->and($composer['require-dev']['orchestra/testbench'])->toBe('^11.2')
        ->and($composer['require-dev']['pestphp/pest'])->toBe('^4.0 || ^5.0');
});
