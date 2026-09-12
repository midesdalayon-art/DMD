<?php

$frontendUrls = env('FRONTEND_URLS', env('FRONTEND_URL', 'http://localhost:5173'));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', $frontendUrls),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Device-Key',
        'X-Device-Id',
        'X-Device-Timestamp',
        'X-Device-Nonce',
        'X-Device-Signature',
        'X-Guest-Access-Token',
        'X-Guest-Checkout-Token',
        'X-Requested-With',
        'X-XSRF-TOKEN',
    ],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
