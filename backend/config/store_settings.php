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
    ],

    // Delivery prices (BHD). A country with a fixed price pays that; the other delivery countries pay by weight.
    // Delivery prices (Admin › Settings › Shipping rates) – the same model and rates as Sniper Shopping. Each destination
    // offers its own methods; how a method is priced: see App\Support\Shipping. Money in BHD.
    // Delivery times count working days (`weekend`: 5 = Friday, 6 = Saturday); an order after `cutoffHour` (Bahrain time)
    // counts from the next day. A product with no weight counts as `fallbackKg`.
    'shipping' => [
        'fallbackKg' => 0.5,
        'cutoffHour' => 14,
        'weekend' => [5, 6],
        'destinations' => [
            ['id' => 'bh', 'name' => ['en' => 'Bahrain', 'ar' => 'البحرين'], 'countries' => ['BH'], 'methods' => [
                // our own team, not SMSA
                ['id' => 'bh-std', 'name' => ['en' => 'Standard delivery', 'ar' => 'توصيل عادي'], 'pricing' => 'formula', 'base' => 2,
                    'firstKg' => 5, 'stepKg' => 1, 'perStep' => 0.3, 'table' => [], 'min' => null, 'max' => 6, 'freeOver' => 25, 'maxKg' => null,
                    'etaMin' => 1, 'etaMax' => 2, 'etaUnit' => 'working', 'active' => true],
                ['id' => 'bh-exp', 'name' => ['en' => 'Same-day express', 'ar' => 'توصيل سريع في نفس اليوم'], 'pricing' => 'flat', 'base' => 4,
                    'firstKg' => 0, 'stepKg' => 1, 'perStep' => 0, 'table' => [], 'min' => null, 'max' => null, 'freeOver' => null, 'maxKg' => 20,
                    'etaMin' => 0, 'etaMax' => 0, 'etaUnit' => 'working', 'active' => true],
                ['id' => 'bh-pick', 'name' => ['en' => 'Collect from branch', 'ar' => 'الاستلام من الفرع'], 'pricing' => 'flat', 'base' => 0,
                    'firstKg' => 0, 'stepKg' => 1, 'perStep' => 0, 'table' => [], 'min' => null, 'max' => null, 'freeOver' => null, 'maxKg' => null,
                    'etaMin' => 0, 'etaMax' => 1, 'etaUnit' => 'working', 'active' => true],
            ]],
            ['id' => 'sa', 'name' => ['en' => 'Saudi Arabia', 'ar' => 'السعودية'], 'countries' => ['SA'], 'methods' => [
                // SMSA (Admin › SMSA shipping)
                ['id' => 'sa-std', 'name' => ['en' => 'GCC courier', 'ar' => 'شحن سريع لدول الخليج'], 'pricing' => 'formula', 'base' => 3.5,
                    'firstKg' => 0.5, 'stepKg' => 0.5, 'perStep' => 0.8, 'table' => [], 'min' => null, 'max' => null, 'freeOver' => null, 'maxKg' => 30,
                    'etaMin' => 3, 'etaMax' => 5, 'etaUnit' => 'working', 'active' => true],
            ]],
            ['id' => 'gcc', 'name' => ['en' => 'Rest of GCC', 'ar' => 'باقي دول الخليج'], 'countries' => ['AE', 'KW', 'QA', 'OM'], 'methods' => [
                ['id' => 'gcc-std', 'name' => ['en' => 'GCC courier', 'ar' => 'شحن سريع لدول الخليج'], 'pricing' => 'formula', 'base' => 4,
                    'firstKg' => 0.5, 'stepKg' => 0.5, 'perStep' => 1, 'table' => [], 'min' => null, 'max' => null, 'freeOver' => null, 'maxKg' => 30,
                    'etaMin' => 4, 'etaMax' => 7, 'etaUnit' => 'working', 'active' => true],
            ]],
        ],
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
        'retailId' => '',
        'methods' => ['sa-std', 'gcc-std'], // delivery-method ids (Shipping rates) whose paid orders get an SMSA waybill by themselves (the GCC courier)
        'services' => ['sa-std' => 'EIDL', 'gcc-std' => 'EIDL'], // {method id: EIDL (by air) | EIED (by road)} abroad; inside the sender's country EDDL
        'dutiesBy' => 'consignee',        // import VAT and duty abroad: consignee (customer, on delivery) | shipper (billed to our SMSA account)
        'contents' => 'Sweets',           // on every waybill (customs); empty = the order's item names
        'autoStatus' => true,             // the order becomes Shipped / Delivered from SMSA's tracking
        'since' => null,                  // automatic waybills only for orders placed after SMSA was switched on
        'shipper' => [
            'name' => 'Tartebz', 'phone' => '', 'country' => 'BH', 'city' => '', 'district' => '',
            'address1' => '', 'address2' => '', 'postalCode' => '',
        ],
    ],

    // Maintenance mode (Admin › Maintenance): customers see a "back soon" page; the team keeps working.
    'maintenance' => ['on' => false, 'message' => '', 'messageAr' => '', 'since' => null, 'by' => ''],

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

];
