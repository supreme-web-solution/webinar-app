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

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
        'from' => env('RESEND_FROM', 'onboarding@resend.dev'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'email' => [
        'primary' => env('EMAIL_PROVIDER_PRIMARY', 'resend'),
        'fallback' => env('EMAIL_PROVIDER_FALLBACK', 'ses_smtp'),
        'ses_smtp_mailer' => env('SES_SMTP_MAILER', 'ses'),
        'ses_smtp_from_address' => env('SES_SMTP_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', 'hello@example.com')),
        'ses_smtp_from_name' => env('SES_SMTP_FROM_NAME', env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel'))),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'chat_model' => env('OPENAI_CHAT_MODEL', 'gpt-4o-mini'),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
    ],

    'scrapingbee' => [
        'api_key' => env('SCRAPINGBEE_API_KEY'),
    ],

];
