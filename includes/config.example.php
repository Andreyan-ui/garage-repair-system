<?php

return [
    'debug' => false,
    'timezone' => 'Africa/Kampala',
    'app_key' => 'replace-with-a-random-64-character-hex-string',

    'db' => [
        'host' => 'localhost',
        'user' => 'root',
        'pass' => '',
        'name' => 'garage_system',
    ],

    'garage' => [
        'name' => 'Wycliffe Motors',
        'full_name' => 'Wycliffe Motors',
        'tagline' => 'Get back on the road',
        'phone' => '+256782006103',
        'whatsapp' => '+256782006103',
        'email' => 'andreyanauto@gmail.com',
        'address' => 'Kampala, Uganda',
        'opening_hours' => [
            'Monday to Friday' => '8:00am - 6:00pm',
            'Saturday' => '8:00am - 4:00pm',
            'Sunday' => 'Closed',
        ],
        'breakdown_hours' => '24 hours a day, 7 days a week',
        'breakdown_callback' => 'Keep your phone switched on. We will call you shortly to confirm the details.',
        'emergency_numbers' => '999 or 112',
        'facebook_url' => '',
        'instagram_url' => '',
    ],

    'phone' => [
        'country_code' => '256',
        'local_length' => 9,
        'example' => '0772 123 456',
    ],

    'booking' => [
        'closed_days' => [0],
        'max_days_ahead' => 60,
        'slots' => [
            'morning' => ['label' => 'Morning', 'hours' => '8am - 12pm', 'ends_at_hour' => 12],
            'afternoon' => ['label' => 'Afternoon', 'hours' => '12pm - 4pm', 'ends_at_hour' => 16],
        ],
    ],

    'currency' => [
        'code' => 'UGX',
    ],

    'invoice' => [
        'tax_rate' => 0,
        'tax_label' => 'VAT',
        'tax_number' => '',
        'estimate_valid_days' => 14,
        'payment_terms' => 'Payment is due when you collect your vehicle.',
        'thank_you' => 'Thank you for choosing Wycliffe Motors.',
    ],

    'security' => [
        'max_attempts' => 5,
        'max_attempts_per_ip' => 30,
        'attempt_window_minutes' => 15,
        'min_password_length' => 8,
    ],
];
