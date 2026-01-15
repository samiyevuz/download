# Quick Setup Guide

This guide will help you set up the Telegram bot quickly.

## Step 1: Install Dependencies

```bash
# Install PHP dependencies
composer install

# Install yt-dlp
sudo pip3 install yt-dlp

# Verify yt-dlp
yt-dlp --version
```

## Step 2: Configure Environment

```bash
# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Edit .env and add your bot token
nano .env
```

Add to `.env`:
```env
TELEGRAM_BOT_TOKEN=your_bot_token_from_botfather
QUEUE_CONNECTION=database  # or 'redis' if you have Redis
```

## Step 3: Setup Database

```bash
# Create database tables
php artisan migrate

# If using database queue, create jobs table
php artisan queue:table
php artisan migrate
```

## Step 4: Create Storage Directories

```bash
# Create temp directory
mkdir -p storage/app/temp/downloads
chmod -R 775 storage
```

## Step 5: Start Queue Worker

```bash
# In a separate terminal
php artisan queue:work --tries=2 --timeout=60
```

## Step 6: Set Webhook

Replace `YOUR_BOT_TOKEN` and `YOUR_DOMAIN`:

```bash
curl -X POST "https://api.telegram.org/botYOUR_BOT_TOKEN/setWebhook" \
  -H "Content-Type: application/json" \
  -d '{"url": "https://YOUR_DOMAIN.com/api/telegram/webhook"}'
```

## Step 7: Test

1. Open Telegram
2. Find your bot
3. Send `/start`
4. Send an Instagram or TikTok link
5. Wait for download

## Troubleshooting

### Bot not responding?
- Check webhook: `curl "https://api.telegram.org/botYOUR_BOT_TOKEN/getWebhookInfo"`
- Check queue worker is running
- Check logs: `tail -f storage/logs/laravel.log`

### Downloads failing?
- Verify yt-dlp: `yt-dlp --version`
- Test manually: `yt-dlp "https://www.instagram.com/p/example/" --dry-run`
- Check disk space: `df -h`

### Queue not processing?
- Check if worker is running: `ps aux | grep queue:work`
- Check failed jobs: `php artisan queue:failed`
- Restart worker

## Production Deployment

For production deployment, see [DEPLOYMENT.md](DEPLOYMENT.md)
