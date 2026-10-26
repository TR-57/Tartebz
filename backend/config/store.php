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
    // false | true (a proxy on a private address in front of us) | cloudflare (requests through Cloudflare's own addresses)
    'trust_proxy' => env('TRUST_PROXY', false),

    // Sessions
    'admin_session_hours' => 12,     // fixed from sign-in, does not slide
    'customer_session_days' => 30,   // sliding

    // Files
    // On Hostinger both live inside public_html, so the web server serves them directly:
    //   UPLOAD_DIR=/home/<user>/domains/<domain>/public_html/uploads   THUMB_DIR=…/public_html/img
    // Locally they default to uploads/ and img/ in the repo root (git-ignored).
    'upload_dir' => env('UPLOAD_DIR') ?: (realpath(base_path('..')) ?: base_path('..')).'/uploads',
    'upload_url' => rtrim(env('UPLOAD_URL', '/uploads'), '/'),
    'thumb_dir' => env('THUMB_DIR') ?: (realpath(base_path('..')) ?: base_path('..')).'/img',
    'thumb_widths' => [200, 400, 800, 1200, 1600, 2400],
    'upload_max_mb' => 8,

    // Repo root (storefront pages, admin, generated i18n catalogue)
    'root' => realpath(base_path('..')) ?: base_path('..'),
    // Where the live storefront pages are (Hostinger: public_html, written by deploy.sh). Product pages are built from its product.html.
    'public_dir' => env('PUBLIC_DIR') ?: (realpath(base_path('..')) ?: base_path('..')),

    // Requests from these paths skip the origin check; they are verified by their own means.
    'origin_exempt' => [
        'api/payments/*/webhook',
        'api/integrations/*',
    ],

    'notify_email' => env('STORE_NOTIFY_EMAIL', ''),

    // Local testing only: a stand-in for Tap's API. Leave unset in production.
    'tap_base_url' => env('TAP_BASE_URL'),
];
