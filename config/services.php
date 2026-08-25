<?php

use App\Services\Publishing\Connectors\LinkedInConnector;

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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'x' => [
        'client_id' => env('X_CLIENT_ID'),
        'client_secret' => env('X_CLIENT_SECRET'),
        'redirect' => env('X_REDIRECT_URI'),
        'bearer_token' => env('X_BEARER_TOKEN'),
    ],

    'linkedin-openid' => [
        'client_id' => env('LINKEDIN_CLIENT_ID'),
        'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
        'redirect' => env('LINKEDIN_REDIRECT_URI'),
        'api_version' => env('LINKEDIN_API_VERSION', LinkedInConnector::DEFAULT_VERSION),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI'),
        'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v25.0'),
    ],

    'instagram' => [
        'client_id' => env('INSTAGRAM_APP_ID'),
        'client_secret' => env('INSTAGRAM_APP_SECRET'),
        'redirect' => env('INSTAGRAM_REDIRECT_URI'),
        'graph_version' => env('INSTAGRAM_GRAPH_API_VERSION', 'v25.0'),
    ],

    'threads' => [
        'client_id' => env('THREADS_CLIENT_ID'),
        'client_secret' => env('THREADS_CLIENT_SECRET'),
        'redirect' => env('THREADS_REDIRECT_URI'),
    ],

    'tiktok' => [
        'client_id' => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
        'redirect' => env('TIKTOK_REDIRECT_URI'),
        'inbox_enabled' => filter_var(env('TIKTOK_INBOX_ENABLED', false), FILTER_VALIDATE_BOOL),
    ],

    'youtube' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('YOUTUBE_REDIRECT_URI'),
        'publishing_enabled' => filter_var(env('YOUTUBE_PUBLISHING_ENABLED', false), FILTER_VALIDATE_BOOL),
        'privacy_status' => env('YOUTUBE_PRIVACY_STATUS'),
        'category_id' => env('YOUTUBE_CATEGORY_ID', '22'),
        'format_intent' => env('YOUTUBE_FORMAT_INTENT'),
        'made_for_kids' => filter_var(env('YOUTUBE_MADE_FOR_KIDS'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
        'contains_synthetic_media' => filter_var(env('YOUTUBE_CONTAINS_SYNTHETIC_MEDIA'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
        'has_paid_product_placement' => filter_var(env('YOUTUBE_HAS_PAID_PRODUCT_PLACEMENT'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
        'notify_subscribers' => filter_var(env('YOUTUBE_NOTIFY_SUBSCRIBERS'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
    ],

    'klipy' => [
        'key' => env('KLIPY_API_KEY'),
        'share_trigger' => env('KLIPY_SHARE_TRIGGER', true),
        'rating' => env('KLIPY_RATING', 'pg-13'),
    ],

];
