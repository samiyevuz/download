# Telegram Bot Setup Guide (No Sudo Required)

## Prerequisites

- PHP 8.1+ with GD extension
- Laravel 9+ or 10
- MySQL/PostgreSQL database
- yt-dlp binary (user-level installation)
- Telegram Bot Token

## Step 1: Install yt-dlp (No Sudo)

```bash
# Download yt-dlp to user directory
mkdir -p ~/bin
cd ~/bin
wget https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -O yt-dlp
chmod +x yt-dlp

# Test installation
~/bin/yt-dlp --version
```

## Step 2: Configure .env

```env
# Database (required for queue)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password

# Queue (use database, not Redis)
QUEUE_CONNECTION=database

# Telegram Bot
TELEGRAM_BOT_TOKEN=your_bot_token
TELEGRAM_API_URL=https://api.telegram.org/bot

# yt-dlp path (absolute path to user's yt-dlp)
YT_DLP_PATH=/home/username/bin/yt-dlp

# Temporary storage
TEMP_STORAGE_PATH=storage/app/temp/downloads
```

## Step 3: Create Database Tables

```bash
# Create jobs table for queue
php artisan queue:table
php artisan migrate

# Or manually create jobs table:
php artisan tinker
# Then run:
DB::statement('CREATE TABLE IF NOT EXISTS jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    queue VARCHAR(255) NOT NULL,
    payload LONGTEXT NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL,
    reserved_at INT UNSIGNED NULL,
    available_at INT UNSIGNED NOT NULL,
    created_at INT UNSIGNED NOT NULL,
    INDEX idx_queue (queue),
    INDEX idx_reserved_at (reserved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;');
```

## Step 4: Verify PHP GD Extension

```bash
php -m | grep gd
php -r "var_dump(function_exists('imagecreatefromwebp'));"
```

If GD is not available, install it (may require sudo, but try user-level first):
```bash
# Check if you can install PHP extensions without sudo
# If not, contact your hosting provider
```

## Step 5: Set Up Webhook

```bash
# Replace YOUR_BOT_TOKEN and YOUR_DOMAIN
curl -X POST "https://api.telegram.org/botYOUR_BOT_TOKEN/setWebhook?url=https://YOUR_DOMAIN/api/telegram/webhook"
```

## Step 6: Start Queue Worker

```bash
# Start queue worker (runs in background)
nohup php artisan queue:work database --queue=downloads,telegram --tries=2 --timeout=60 > storage/logs/queue.log 2>&1 &

# Or use supervisor (if available) for auto-restart
```

## Step 7: Test Bot

1. Send `/start` to bot
2. Bot should reply: "Welcome.\nSend an Instagram or TikTok link."
3. Send an Instagram or TikTok link
4. Bot should download and send media

## Troubleshooting

### Queue Not Processing

```bash
# Check if worker is running
ps aux | grep "queue:work"

# Check queue table
php artisan tinker
DB::table('jobs')->count();

# Manually process queue
php artisan queue:work database --once
```

### WebP Conversion Fails

```bash
# Check GD extension
php -r "var_dump(extension_loaded('gd'));"
php -r "var_dump(function_exists('imagecreatefromwebp'));"

# If WebP support is missing, images will be sent as-is (may fail)
```

### yt-dlp Not Found

```bash
# Verify path
ls -la ~/bin/yt-dlp

# Test execution
~/bin/yt-dlp --version

# Update .env with correct absolute path
```

## File Structure

```
app/
├── Http/Controllers/
│   └── TelegramWebhookController.php  # Webhook handler
├── Jobs/
│   └── DownloadMediaJob.php           # Media download job
├── Services/
│   ├── TelegramService.php            # Telegram API wrapper
│   └── YtDlpService.php              # yt-dlp wrapper
└── Utils/
    └── MediaConverter.php             # WebP to JPG converter
```

## Security Notes

- All URLs are validated and sanitized
- yt-dlp arguments are passed as arrays (no shell injection)
- Temporary files are always cleaned up
- File handles are used instead of loading entire files into memory

## Production Checklist

- [ ] Queue worker running (use supervisor for auto-restart)
- [ ] Database queue table created
- [ ] yt-dlp installed and accessible
- [ ] PHP GD extension with WebP support
- [ ] Webhook configured
- [ ] Logs directory writable
- [ ] Temp storage directory writable
- [ ] Test with Instagram image (should convert WebP to JPG)
- [ ] Test with Instagram video
- [ ] Test with TikTok video
- [ ] Test with carousel post (multiple images)
