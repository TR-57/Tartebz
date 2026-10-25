<?php

/*
 | Store-wide configuration that comes from the environment.
 | Business values (VAT, prices, contact details …) are settings, edited in the admin – see config/store_settings.php.
 */
return [
    'name' => env('APP_NAME', 'Tartebz'),
    'store_url' => rtrim(env('STORE_URL', env('APP_URL', 'http://localhost')), '/'),
    'admin_url' => env('ADMIN_URL', rtrim(env('STORE_URL', env('APP_URL', 'http://localhost')), '/').'/admin/'),

    // Cookies
    'cookie_prefix' => 'tz',
    'cookie_domain' => env('COOKIE_DOMAIN') ?: null,
    'cookie_secure' => (bool) env('COOKIE_SECURE', true),
    'trust_proxy' => (bool) env('TRUST_PROXY', false),

    // Sessions
    'admin_session_hours' => 12,     // fixed from sign-in, does not slide
    'customer_session_days' => 30,   // sliding

    // Files
    'upload_dir' => env('UPLOAD_DIR') ?: storage_path('app/uploads'),
    'upload_url' => env('UPLOAD_URL', '/uploads'),
    'thumb_dir' => env('THUMB_DIR') ?: storage_path('app/thumbs'),

    // Repo root (storefront pages, admin, generated i18n catalogue)
    'root' => realpath(base_path('..')) ?: base_path('..'),

    // Requests from these paths skip the origin check; they are verified by their own means.
    'origin_exempt' => [
        'api/payments/*/webhook',
        'api/integrations/*',
    ],

    'notify_email' => env('STORE_NOTIFY_EMAIL', ''),
];
