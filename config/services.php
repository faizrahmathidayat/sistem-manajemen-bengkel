<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        // Secret Telegram echoes back on every webhook call; the endpoint refuses everything without it.
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    ],

    // channel: auto = Telegram when TELEGRAM_BOT_TOKEN is set, otherwise log only; telegram; log (never send).
    // base_url: address put in message links so they open from a phone (e.g. http://192.168.1.20:8010);
    // falls back to APP_URL.
    'approval_notifications' => [
        'channel' => env('APPROVAL_NOTIFY_CHANNEL', 'auto'),
        'base_url' => env('NOTIFY_BASE_URL'),
    ],

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

];
