<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Linkado\Laravel\Http\Controllers\LaunchSsoController;
use Linkado\Laravel\Support\LinkadoConfiguration;

$configuration = app(LinkadoConfiguration::class);

Route::post('linkado/sso', LaunchSsoController::class)
    ->middleware($configuration->ssoMiddleware())
    ->name($configuration->ssoRouteName());
