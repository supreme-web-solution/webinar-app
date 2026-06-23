<?php

$cloudinaryUrl = (string) env('CLOUDINARY_URL', '');
$cloudinaryParsed = $cloudinaryUrl !== '' ? parse_url($cloudinaryUrl) : false;

$cloudinaryCloudNameFromUrl = is_array($cloudinaryParsed)
    ? (string) ($cloudinaryParsed['host'] ?? '')
    : '';

$cloudinaryApiKeyFromUrl = is_array($cloudinaryParsed)
    ? (string) ($cloudinaryParsed['user'] ?? '')
    : '';

$cloudinaryApiSecretFromUrl = is_array($cloudinaryParsed)
    ? (string) ($cloudinaryParsed['pass'] ?? '')
    : '';

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
        'key' => env('POSTMARK_API_KEY', env('POSTMARK_TOKEN')),
        'token' => env('POSTMARK_API_KEY', env('POSTMARK_TOKEN')),
        'from' => env('POSTMARK_FROM', env('MAIL_FROM_ADDRESS', 'hello@example.com')),
        'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
        'webhook_token' => env('POSTMARK_WEBHOOK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
        'from' => env('RESEND_FROM', 'onboarding@resend.dev'),
    ],

    'elastic' => [
        'key' => env('ELASTICEMAIL_API_KEY'),
        'from' => env('ELASTICEMAIL_FROM', env('MAIL_FROM_ADDRESS', 'hello@example.com')),
        'channel' => env('ELASTICEMAIL_CHANNEL'),
    ],

    'zeptomail' => [
        'key' => env('ZEPTOMAIL_API_KEY'),
        'from' => env('ZEPTOMAIL_FROM', env('MAIL_FROM_ADDRESS', 'hello@example.com')),
    ],

    'sendgrid' => [
        'key' => env('SENDGRID_API_KEY'),
        'from' => env('SENDGRID_FROM', env('MAIL_FROM_ADDRESS', 'hello@example.com')),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'email' => [
        'primary' => env('EMAIL_PROVIDER_PRIMARY', 'postmark'),
        'fallback' => env('EMAIL_PROVIDER_FALLBACK', 'resend'),
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
        'tts_model' => env('OPENAI_TTS_MODEL', 'gpt-4o-mini-tts'),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'transcription_model' => env('OPENAI_TRANSCRIPTION_MODEL', 'whisper-1'),
    ],

    'ai_transcript' => [
        'ffmpeg_bin' => env('FFMPEG_BIN', 'ffmpeg'),
        'yt_dlp_bin' => env('YT_DLP_BIN', 'yt-dlp'),
        'chunk_seconds' => (int) env('AI_TRANSCRIPT_CHUNK_SECONDS', 300),
        'ffmpeg_timeout_seconds' => (int) env('AI_TRANSCRIPT_FFMPEG_TIMEOUT_SECONDS', 7200),
        'openai_timeout_seconds' => (int) env('AI_TRANSCRIPT_OPENAI_TIMEOUT_SECONDS', 180),
    ],

    'scrapingbee' => [
        'api_key' => env('SCRAPINGBEE_API_KEY'),
    ],

    'heygen' => [
        'api_key' => env('HEYGEN_API_KEY'),
    ],

    'apollo' => [
        'api_key' => env('APOLLO_API_KEY'),
        'search_endpoint' => env('APOLLO_SEARCH_ENDPOINT', 'https://api.apollo.io/api/v1/mixed_people/search'),
        'max_fetch' => (int) env('APOLLO_FETCH_MAX_COUNT', 250),
    ],

    'd7' => [
        'api_key' => env('D7_API_KEY'),
        'timeout_seconds' => (int) env('D7_TIMEOUT_SECONDS', 60),
        'max_fetch' => (int) env('D7_FETCH_MAX_COUNT', 1200),
    ],

    'leads' => [
        'provider' => env('LEAD_PROVIDER', 'apollo'),
        'fallback_provider' => env('LEAD_PROVIDER_FALLBACK'),
    ],

    'queues' => [
        'emails' => env('QUEUE_EMAILS_NAME', 'emails'),
        'apollo_fetch' => env('QUEUE_APOLLO_FETCH_NAME', 'apollo-fetch'),
        'ai_transcript' => env('QUEUE_AI_TRANSCRIPT_NAME', 'ai-transcript'),
        'ai_ingest' => env('QUEUE_AI_INGEST_NAME', 'ai-ingest'),
        'ai_chat' => env('QUEUE_AI_CHAT_NAME', 'ai-chat'),
    ],

    'cloudinary' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME', $cloudinaryCloudNameFromUrl),
        'api_key' => env('CLOUDINARY_API_KEY', $cloudinaryApiKeyFromUrl),
        'api_secret' => env('CLOUDINARY_API_SECRET', $cloudinaryApiSecretFromUrl),
        'upload_preset' => env('CLOUDINARY_UPLOAD_PRESET'),
        'notification_url' => env('CLOUDINARY_NOTIFICATION_URL'),
        'url' => env('CLOUDINARY_URL'),
        'folder' => env('CLOUDINARY_VIDEO_FOLDER', 'webinars/heygen'),
    ],

];
