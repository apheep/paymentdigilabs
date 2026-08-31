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

    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),

        'notification' => [
            'connect_timeout' => (int) env('MIDTRANS_NOTIFICATION_CONNECT_TIMEOUT', 3),
            'timeout' => (int) env('MIDTRANS_NOTIFICATION_TIMEOUT', 5),
            'idempotency_ttl' => (int) env('MIDTRANS_NOTIFICATION_IDEMPOTENCY_TTL', 86400),
            'processing_lock_seconds' => (int) env('MIDTRANS_NOTIFICATION_LOCK_SECONDS', 10),

            'targets' => [
                'kuotaumroh' => [
                    'prefix' => 'KU-',
                    'url' => env('KUOTAUMROH_API_URL', 'https://kuotaumroh.id/api/payment/midtrans/update'),
                    'api_key' => env('KUOTAUMROH_API_KEY'),
                ],

                'wargame' => [
                    'prefix' => 'GAME-',
                    'url' => env('WARGAME_API_URL', 'https://wargame.id/api/payment/midtrans/update'),
                    'api_key' => env('WARGAME_API_KEY'),
                ],
            ],
        ],
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

];
