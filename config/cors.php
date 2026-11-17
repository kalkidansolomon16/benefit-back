<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    | Allows the Vue frontend (localhost:5173 dev / production domain) to
    | communicate with the Laravel API.
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter([
        'http://localhost:5173',   // Vite dev server
        'http://localhost:3000',   // fallback dev port
        'http://127.0.0.1:5173',
        env('FRONTEND_URL'),       // production/staging frontend origin
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Authorization', 'Accept', 'X-Requested-With', 'X-Gym-Id'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
