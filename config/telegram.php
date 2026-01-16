<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Telegram Bot Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Telegram Bot API integration.
    |
    */

    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    
    'api_url' => env('TELEGRAM_API_URL', 'https://api.telegram.org/bot'),
    
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET', null),
    
    /*
    |--------------------------------------------------------------------------
    | Allowed Domains
    |--------------------------------------------------------------------------
    |
    | List of allowed domains for media downloads.
    |
    */
    
    'allowed_domains' => [
        'instagram.com',
        'www.instagram.com',
        'tiktok.com',
        'www.tiktok.com',
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Download Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for media downloads.
    |
    */
    
    'download_timeout' => env('DOWNLOAD_TIMEOUT', 60),
    
    'temp_storage_path' => storage_path('app/temp/downloads'),
    
    'yt_dlp_path' => env('YT_DLP_PATH', 'yt-dlp'),
    
    /*
    |--------------------------------------------------------------------------
    | Instagram Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for Instagram downloads (cookies for better reliability).
    |
    */
    
    'instagram_cookies_path' => env('INSTAGRAM_COOKIES_PATH', null),
];
