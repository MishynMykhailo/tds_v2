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
    | traffic-core (separate Composer project, see docs/ARCHITECTURE_PLAN.md)
    |--------------------------------------------------------------------------
    |
    | Used by LandingsController::previewAction()/OffersController::
    | previewAction() to mint the HMAC-signed preview link traffic-core's
    | public/preview.php validates — see that file's docblock. `secret`
    | MUST match traffic-core's PREVIEW_SECRET env var exactly.
    */
    'traffic_core' => [
        'url' => env('TRAFFIC_CORE_URL', 'http://localhost:8080'),
        'preview_secret' => env('PREVIEW_SECRET', 'tds_v2-dev-only-preview-secret-override-via-PREVIEW_SECRET-env'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Headless-Chrome screenshot service (deploy/docker-compose.yml `screenshot`)
    |--------------------------------------------------------------------------
    |
    | Used by App\Services\PreviewImageService to render landing/offer
    | preview thumbnails — see that class's docblock.
    */
    'screenshot' => [
        'cdp_url' => env('SCREENSHOT_CDP_URL', 'http://localhost:9222'),
    ],

];
