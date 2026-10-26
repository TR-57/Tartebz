<?php

/*
 | Default value of every settings key.
 | Settings::get() merges what was saved OVER these defaults, key by key, so a new default appears on old installs.
 | Lists are taken whole from what was saved.
 | Business details here are placeholders – the real ones are saved in the admin (or by the seeder).
 */
return [
    'i18n' => [
        'defaultLang' => 'ar',
    ],

    'business' => [
        'legalName' => 'Your Company W.L.L.',
        'cr' => '00000-0',
        'vat' => '',
        'vatOn' => true,
        'vatRate' => 10,
        'vatPrices' => 'exclusive',      // exclusive | inclusive
        'vatOutsideBahrain' => 'remove', // remove | keep  (inclusive prices only)
        'addressLine1' => 'Building 0, Road 0, Block 0',
        'addressLine1_ar' => '',
        'addressLine2' => '',
        'area' => 'Manama',
        'phone' => '+973 0000 0000',
        'mobile' => '',
        'whatsapp' => '',
        'email' => 'hello@example.com',
        'hours' => 'Sat–Thu, 9 am – 9 pm',
        'freeDeliveryOver' => null,      // advertised free-delivery threshold (BHD) – one value used site-wide
    ],

    // Shop name, logo and social links shown on the storefront and in emails.
    'brand' => [
        'name' => 'Tartebz',
        'name_ar' => 'ترتيبز',
        'logo' => '',          // media path, e.g. wp/2026/06/logo.png (empty: the drawn logo)
        'instagram' => '',
        'tiktok' => '',
        'mapsUrl' => '',
    ],

    // Storefront extras the manager switches in Admin › Settings › Website.
    'storefront' => [
        'whatsappOrders' => true,   // "Order on WhatsApp" button in the cart
        'whatsappNumber' => '',     // digits with country code; empty = the business WhatsApp/phone
        'backInStock' => true,      // "Tell me when it's back" on sold-out products
        'paymentLogos' => ['benefit', 'visa', 'mastercard'], // shown near prices, in the cart and the footer – only what you really accept
        // Delivery time per country, in days after the order (shown as "Arrives tomorrow" / "Arrives Sun 3 – Wed 6 Nov").
        'delivery' => [
            ['country' => 'BH', 'min' => 1, 'max' => 1],
            ['country' => 'SA', 'min' => 2, 'max' => 4],
            ['country' => 'AE', 'min' => 2, 'max' => 4],
            ['country' => 'QA', 'min' => 2, 'max' => 4],
            ['country' => 'KW', 'min' => 2, 'max' => 4],
            ['country' => 'OM', 'min' => 3, 'max' => 5],
        ],
    ],

    'orders' => [
        'holdMinutes' => 60,
        'numberPrefix' => '',
    ],

    'loyalty' => [
        'enabled' => true,
        'name' => 'Tartebz Rewards',
        'pointsPerBhd' => 10,
        'bhdPerPoint' => 0.005,
        'minPointsToRedeem' => 500,
    ],

    // Prices are kept in the base currency (BHD). Each other currency has a rate = how many of its units the customer
    // pays for 1 BHD (the old shop charged 10 SAR/AED/QAR, 0.82 KWD and 1.1 OMR per 1 BHD).
    // Orders save the currency, the rate used and the BHD amounts, so admin totals and reports are always in BHD.
    'currencies' => [
        'base' => 'BHD',
        'list' => [
            ['code' => 'BHD', 'rate' => 1, 'decimals' => 3, 'symbol' => 'BHD', 'symbol_ar' => 'د.ب', 'countries' => ['BH'], 'enabled' => true],
            ['code' => 'SAR', 'rate' => 10, 'decimals' => 2, 'symbol' => 'SAR', 'symbol_ar' => 'ر.س', 'countries' => ['SA'], 'enabled' => true],
            ['code' => 'AED', 'rate' => 10, 'decimals' => 2, 'symbol' => 'AED', 'symbol_ar' => 'د.إ', 'countries' => ['AE'], 'enabled' => true],
            ['code' => 'QAR', 'rate' => 10, 'decimals' => 2, 'symbol' => 'QAR', 'symbol_ar' => 'ر.ق', 'countries' => ['QA'], 'enabled' => true],
            ['code' => 'KWD', 'rate' => 0.82, 'decimals' => 3, 'symbol' => 'KWD', 'symbol_ar' => 'د.ك', 'countries' => ['KW'], 'enabled' => true],
            ['code' => 'OMR', 'rate' => 1.1, 'decimals' => 3, 'symbol' => 'OMR', 'symbol_ar' => 'ر.ع', 'countries' => ['OM'], 'enabled' => true],
            ['code' => 'USD', 'rate' => 2.66, 'decimals' => 2, 'symbol' => 'USD', 'symbol_ar' => 'دولار', 'countries' => [], 'enabled' => false],
        ],
    ],

    'mail' => [
        'host' => '',
        'port' => 587,
        'scheme' => '',
        'username' => '',
        'password' => '',   // sealed
        'fromAddress' => '',
        'fromName' => '',
        'notifyEmail' => '',
    ],

    'maintenance' => [
        'message_en' => '', 'message_ar' => '',
        'back_en' => '', 'back_ar' => '',
        'whatsapp' => '',
    ],
];
