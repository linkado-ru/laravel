<?php

declare(strict_types=1);

return [
    'mode' => env('LINKADO_MODE', 'off'),
    'connection' => env('LINKADO_DB_CONNECTION'),
    'queue' => env('LINKADO_QUEUE'),
    'base_url' => env('LINKADO_BASE_URL', 'https://app.linkado.ru/api/v1'),
    'token' => env('LINKADO_TOKEN'),
    'program_key' => env('LINKADO_PROGRAM_KEY'),
    'features' => [
        'sso' => env('LINKADO_SSO_ENABLED', false),
        'tracking' => env('LINKADO_TRACKING_ENABLED', false),
        'customer_events' => env('LINKADO_CUSTOMER_EVENTS_ENABLED', false),
        'billing_events' => env('LINKADO_BILLING_EVENTS_ENABLED', false),
        'refund_events' => env('LINKADO_REFUND_EVENTS_ENABLED', false),
    ],
    'tracking' => [
        'script_url' => env('LINKADO_TRACKING_SCRIPT_URL'),
        'endpoint_url' => env('LINKADO_TRACKING_ENDPOINT_URL'),
        'referral_parameter' => env('LINKADO_REFERRAL_PARAMETER', 'ref'),
        'visitor_cookie' => 'linkado_visitor',
        'click_cookie' => 'lk_click',
        'referral_cookie' => 'lk_referral',
        'ttl_seconds' => (int) env('LINKADO_ATTRIBUTION_TTL_SECONDS', 2592000),
    ],
    'sso' => [
        'route' => 'linkado.sso.launch',
        'middleware' => ['web', 'auth', 'throttle:6,1'],
        'error_redirect' => '/',
    ],
    'delivery' => [
        'max_attempts' => 8,
        'claim_timeout_seconds' => 600,
        'base_delay_seconds' => 60,
        'max_delay_seconds' => 21600,
        'retry_window_seconds' => 86400,
    ],
];
