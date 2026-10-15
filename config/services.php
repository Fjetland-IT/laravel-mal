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

    // SMTP2GO: API-nøkkel for e-post fra appen (MAIL_MAILER=smtp2go).
    'smtp2go' => [
        'key' => env('SMTP2GO_API_KEY', ''),
    ],

    // IndexNow (Bing, DuckDuckGo via Bing, Yandex m.fl.). Nøkkelen settes bare
    // i produksjon; den er offentlig og publiseres på /indexnow.txt.
    'indexnow' => [
        'key' => env('INDEXNOW_KEY'),
        'url' => env('INDEXNOW_URL'), // tom = APP_URL
        'endpoint' => env('INDEXNOW_ENDPOINT', 'https://api.indexnow.org/indexnow'),
    ],

];
