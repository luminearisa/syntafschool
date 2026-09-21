<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Midtrans — pembayaran online PPDB
    |--------------------------------------------------------------------------
    |
    | Dipakai oleh `PpdbMidtransGateway`. Biarkan `MIDTRANS_SERVER_KEY` kosong
    | saat mengembangkan tanpa kredensial: portal otomatis hanya menawarkan
    | transfer manual.
    |
    | Callback pembayaran adalah rute publik, jadi notifikasi tanpa tanda tangan
    | SHA-512 ditolak secara default — termasuk saat server key belum dipasang.
    | Untuk menjalankan alur pembayaran online tanpa kredensial (mock/uji),
    | nyalakan `MIDTRANS_ALLOW_UNSIGNED_NOTIFICATIONS=true` secara sadar; jangan
    | pernah menyalakannya di produksi.
    |
    | `is_production=false` mengarahkan ke URL sandbox. Pengujian integrasi
    | memakai HTTP fake sehingga tidak pernah memanggil Midtrans sungguhan.
    |
    */

    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'is_production' => env('MIDTRANS_IS_PRODUCTION', false),
        'url' => env('MIDTRANS_BASE_URL'),
        'allow_unsigned_notifications' => env('MIDTRANS_ALLOW_UNSIGNED_NOTIFICATIONS', false),
    ],

];
