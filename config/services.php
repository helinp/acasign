<?php

return [

    'entra' => [
        'tenant_id' => env('ENTRA_TENANT_ID'),
        'client_id' => env('ENTRA_CLIENT_ID'),
        'client_secret' => env('ENTRA_CLIENT_SECRET'),
        'redirect' => env('ENTRA_REDIRECT_URI'),
        'allowed_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('ENTRA_ALLOWED_DOMAINS', ''))))),
        'forbidden_username_fragment' => env('ENTRA_FORBIDDEN_USERNAME_FRAGMENT', '.ELEVE'),
        'bootstrap_admin_email' => env('BOOTSTRAP_ADMIN_EMAIL'),
    ],

    'acanet' => [
        'jwt_secret' => env('ACANET_JWT_SECRET'),
        'jwt_issuer' => env('ACANET_JWT_ISSUER', 'acanet'),
        'webhook_url' => env('ACANET_WEBHOOK_URL'),
        'webhook_secret' => env('ACANET_WEBHOOK_SECRET'),
    ],

    'teams' => ['webhook_url' => env('TEAMS_WEBHOOK_URL')],
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
    ],

    'signature' => [
        'certificate' => env('PDF_SIGNING_CERTIFICATE', '/run/secrets/pdf_signing_certificate'),
        'private_key' => env('PDF_SIGNING_PRIVATE_KEY', '/run/secrets/pdf_signing_private_key'),
        'private_key_password' => env('PDF_SIGNING_PRIVATE_KEY_PASSWORD'),
        'retention_days' => (int) env('DOCUMENT_RETENTION_DAYS', 90),
    ],

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

];
