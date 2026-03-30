<?php

return [

    'paths' => ['*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://sellit-portfolio-dashboard.vercel.app',
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'https://tradeflow-production-c4ff.up.railway.app',
    ],

    'allowed_origins_patterns' => [
        '#^chrome-extension://.*#',   // Chrome extension service workers
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];