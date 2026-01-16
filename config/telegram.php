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
    
    // Multiple cookies support (comma-separated paths for rotation)
    'instagram_cookies_paths' => env('INSTAGRAM_COOKIES_PATHS', null), // Format: "path1,path2,path3"
    
    /*
    |--------------------------------------------------------------------------
    | Required Channel Subscription
    |--------------------------------------------------------------------------
    |
    | Configuration for mandatory channel subscription.
    | Users must subscribe to this channel to use the bot.
    |
    */
    
    'required_channel_id' => env('TELEGRAM_REQUIRED_CHANNEL_ID', null),
    'required_channel_username' => env('TELEGRAM_REQUIRED_CHANNEL_USERNAME', null),
    
    // Multiple channels support (comma-separated)
    'required_channels' => env('TELEGRAM_REQUIRED_CHANNELS', null), // Format: "channel1,channel2" or "@channel1,@channel2"
];
