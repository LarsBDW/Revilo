<?php

return [
    'test_checkout' => env('TEST_CHECKOUT_ENABLED', false),
    'reservation_minutes' => 30,
    'standard_warranty_months' => 12,
    'extended_warranty_cents' => 3900,
    'shipping_cents' => ['NL' => 695, 'BE' => 995, 'DE' => 995],
    'free_shipping_threshold_cents' => 25000,
    'countries' => ['NL', 'BE', 'DE'],
    'locales' => ['nl', 'en', 'de'],
    'contact_email' => env('SHOP_CONTACT_EMAIL'),
    'business_name' => 'Revilo',
];
