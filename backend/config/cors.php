<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'storage/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter([
        env('FRONTEND_URL', 'http://localhost:5173'),
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ])),

    /*
     * Vite memilih port lain (5174, 5175, ...) begitu 5173 sedang dipakai,
     * sehingga daftar origin eksplisit di atas cepat basi dan preflight
     * gagal dengan "No 'Access-Control-Allow-Origin' header".
     *
     * Di lingkungan non-produksi saja, izinkan localhost/127.0.0.1 pada port
     * berapa pun. Pola ini di-anchor (^...$) agar tidak cocok dengan host
     * lain yang namanya diawali "localhost", mis. http://localhost.evil.com.
     * Di produksi daftar pola tetap kosong.
     */
    'allowed_origins_patterns' => in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)
        ? [
            '#^http://(localhost|127\.0\.0\.1)(:\d+)?$#',
            '#^https://[a-z0-9-]+\.w\.modal\.host$#',
        ]
        : [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    // Cache hasil preflight 1 jam agar tidak ada OPTIONS di setiap request.
    'max_age' => 3600,

    'supports_credentials' => true,

];
