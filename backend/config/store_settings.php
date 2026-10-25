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
        'addressLine2' => '',
        'area' => 'Manama',
        'phone' => '+973 0000 0000',
        'mobile' => '',
        'whatsapp' => '',
        'email' => 'hello@example.com',
        'hours' => 'Sat–Thu, 9 am – 9 pm',
        'freeDeliveryOver' => null,      // advertised free-delivery threshold (BHD) – one value used site-wide
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

    'currencies' => [
        'BHD' => 1, 'USD' => 2.6596, 'AED' => 9.7674, 'SAR' => 9.9735,
        'QAR' => 9.6810, 'OMR' => 1.0226, 'KWD' => 0.8157,
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
