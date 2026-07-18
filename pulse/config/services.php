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

    // AI news rewriter (OpenAI). Leave the key blank to run the pipeline
    // with a safe stub rewriter until you're ready to enable live rewriting.
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('PULSE_AI_MODEL', 'gpt-4o-mini'),
        'image_model' => env('PULSE_AI_IMAGE_MODEL', 'dall-e-3'),
    ],

    // Google AdSense. Leave client blank until approved — ad slots render
    // nothing in production (and a labeled placeholder in local dev).
    'adsense' => [
        'client' => env('ADSENSE_CLIENT'),                    // e.g. ca-pub-1234567890123456
        'slot_article' => env('ADSENSE_SLOT_ARTICLE'),        // in-article (fluid) unit
        'slot_display' => env('ADSENSE_SLOT_DISPLAY'),        // responsive display unit
    ],

    // Stripe payments (hosted Checkout). Prefer setting these in
    // Admin → AI & Ads Settings; env is a fallback. Blank = checkout falls
    // back to cash-on-delivery pending orders.
    'stripe' => [
        'key' => env('STRIPE_KEY'),                           // pk_live_… / pk_test_… (publishable)
        'secret' => env('STRIPE_SECRET'),                     // sk_live_… / sk_test_…
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),     // whsec_…
    ],

    // Google Search Console — real ranking data (avg position, clicks, impressions).
    // Prefer setting these in Admin → AI & Ads Settings; env is a fallback.
    'gsc' => [
        'property' => env('GSC_PROPERTY'),                    // sc-domain:example.com or https://example.com/
        'service_account' => env('GSC_SERVICE_ACCOUNT_JSON'), // full service-account JSON key
    ],

];
