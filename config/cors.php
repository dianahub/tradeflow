return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'register', 'logout'],  // Add your auth routes if needed

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://sellit-portfolio-dashboard.vercel.app',  // ← Add this exact URL!
        'http://localhost:3000', 'http://localhost:5173', // For local dev (Vite/React/Next)
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,  // Critical for cookies/auth!
];