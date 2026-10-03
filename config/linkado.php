<?php

declare(strict_types=1);

return [
    'mode' => env('LINKADO_MODE', 'live'),
    'connection' => env('LINKADO_DB_CONNECTION'),
    'queue' => env('LINKADO_QUEUE'),
    'url' => env('LINKADO_URL'),
    'base_url' => env('LINKADO_BASE_URL'),
    'token' => env('LINKADO_TOKEN'),
    'program_key' => env('LINKADO_PROGRAM_KEY'),
    'features' => [
        'sso' => env('LINKADO_SSO_ENABLED', false),
        'tracking' => env('LINKADO_TRACKING_ENABLED', true),
        'customer_events' => env('LINKADO_CUSTOMER_EVENTS_ENABLED', true),
        'billing_events' => env('LINKADO_BILLING_EVENTS_ENABLED', true),
        'refund_events' => env('LINKADO_REFUND_EVENTS_ENABLED', true),
    ],
    'tracking' => [
        'script_url' => env('LINKADO_TRACKING_SCRIPT_URL'),
        'endpoint_url' => env('LINKADO_TRACKING_ENDPOINT_URL'),
        'referral_parameter' => env('LINKADO_REFERRAL_PARAMETER', 'via'),
        'visitor_cookie' => 'linkado_visitor',
        'click_cookie' => 'lk_click',
        'referral_cookie' => 'lk_referral',
        'ttl_seconds' => (int) env('LINKADO_ATTRIBUTION_TTL_SECONDS', 5184000),
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
