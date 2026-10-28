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
        'cartHoldMinutes' => 15,    // a product added to a cart is kept for that customer this long (renewed while they are active)
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

    // Delivery prices (BHD). A country with a fixed price pays that; the other delivery countries pay by weight.
    'shipping' => [
        'countries' => ['BH', 'SA', 'AE', 'QA', 'KW', 'OM'],
        'flat' => [['country' => 'BH', 'price' => 1.5]],
        // [up to kg, BHD] – the old shop's rate card; above the last row: + extraPerHalfKg for every started 0.5 kg
        'table' => [[0.5, 3.8], [1, 4.2], [1.5, 5.1], [2, 6.1], [2.5, 7.3], [3, 8.7], [3.5, 9.6], [4, 12.1], [4.5, 12.2], [5, 13.8],
            [5.5, 14.9], [6, 15.9], [6.5, 17], [7, 18.1], [7.5, 19.1], [8, 20.2], [8.5, 21.2], [9, 22.3], [9.5, 23.3], [10, 24.4]],
        'extraPerHalfKg' => 1.05,
    ],

    // Card payments through Tap (tap.company). Keys are sealed; test keys start sk_test_, live keys sk_live_.
    'payments' => [
        'tap' => [
            'enabled' => false,
            'mode' => 'test',             // test | live
            'testSecret' => '', 'testPublishable' => '',
            'liveSecret' => '', 'livePublishable' => '',
            'merchantId' => '',
            'chargeCurrencies' => ['BHD'], // currencies your Tap account can charge in; others are charged in BHD
        ],
        'whatsapp' => ['enabled' => true], // "order and pay on WhatsApp" at checkout
        // Apple Pay: shows on Tap's payment page once Tap switches it on for the account. If Tap (or Apple) asks to
        // verify the domain, the file they give is uploaded in Payments and served at
        // /.well-known/apple-developer-merchantid-domain-association.
        'applePay' => ['domainFile' => '', 'uploadedAt' => null],
    ],

    // SMSA Express (smsaexpress.com) – shipments, labels and tracking through the eCommerce API.
    'smsa' => [
        'enabled' => false,
        'mode' => 'test',                 // test (sandbox) | live – a live shipment is a real, billable parcel
        'testKey' => '', 'liveKey' => '', // sealed
        'testUrl' => 'https://ecomapis-sandbox.azurewebsites.net',
        'liveUrl' => 'https://ecomapis.smsaexpress.com',
        'serviceCode' => '',              // empty = your account's default (SMSA gives you this, e.g. EDDL)
        'retailId' => '',
        'contentDescription' => 'Sweets / حلويات',
        'declaredCurrency' => 'BHD',
        'shipper' => [
            'name' => 'Tartebz', 'phone' => '', 'country' => 'BH', 'city' => '', 'district' => '',
            'address1' => '', 'address2' => '', 'postalCode' => '',
        ],
    ],

    // Phone / computer notifications for new orders (Web Push). The key pair is made on first use; the private key is sealed.
    'push' => ['publicKey' => '', 'privateKey' => ''],

    'orders' => [
        'holdMinutes' => 60,          // an unpaid card order keeps its stock this long (Tap's payment page closes before)
        'whatsappHoldHours' => 24,    // a WhatsApp order keeps its stock this long, until staff mark it paid
        'numberPrefix' => '',
        'firstNumber' => 20001,   // order numbers continue after the old shop's
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
