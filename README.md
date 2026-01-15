# Instagram & TikTok Downloader Telegram Bot

A production-ready Telegram bot built with Laravel that downloads videos and images from Instagram and TikTok using yt-dlp.

## 🚀 Features

- ✅ **Instagram Support**: Download videos and images from Instagram posts
- ✅ **TikTok Support**: Download videos from TikTok
- ✅ **Carousel Posts**: Handles multiple images in carousel posts
- ✅ **Queue-Based Processing**: Async downloads using Laravel queues
- ✅ **Webhook Integration**: Fast response times with Telegram webhooks
- ✅ **Error Handling**: Comprehensive error handling and user-friendly messages
- ✅ **Automatic Cleanup**: Temporary files are automatically deleted
- ✅ **Production Ready**: Stable, fault-tolerant, and scalable

## 📋 Requirements

- PHP 8.2+
- Laravel 12.x
- Composer
- yt-dlp (latest version)
- Redis or Database for queues
- Telegram Bot Token

## 🛠️ Quick Start

### 1. Installation

```bash
# Install dependencies
composer install

# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Run migrations
php artisan migrate
```

### 2. Configuration

Edit `.env` file:

```env
TELEGRAM_BOT_TOKEN=your_bot_token_here
QUEUE_CONNECTION=redis  # or 'database'
YT_DLP_PATH=yt-dlp
DOWNLOAD_TIMEOUT=60
```

### 3. Install yt-dlp

```bash
# Using pip (recommended)
sudo pip3 install yt-dlp

# Verify installation
yt-dlp --version
```

### 4. Start Queue Worker

```bash
# Development
php artisan queue:work --tries=2 --timeout=60

# Production (use Supervisor - see DEPLOYMENT.md)
```

### 5. Set Webhook

```bash
curl -X POST "https://api.telegram.org/botYOUR_BOT_TOKEN/setWebhook" \
  -H "Content-Type: application/json" \
  -d '{"url": "https://YOUR_DOMAIN.com/api/telegram/webhook"}'
```

## 📖 Usage

1. Start a conversation with your bot on Telegram
2. Send `/start` to receive welcome message
3. Send an Instagram or TikTok link
4. Bot will download and send the media back

## 🏗️ Architecture

```
app/
├── Http/
│   └── Controllers/
│       └── TelegramWebhookController.php  # Webhook handler
├── Jobs/
│   └── DownloadMediaJob.php               # Async download job
├── Services/
│   ├── TelegramService.php                # Telegram API client
│   └── YtDlpService.php                   # yt-dlp wrapper
└── Validators/
    └── UrlValidator.php                    # URL validation

config/
└── telegram.php                            # Bot configuration

routes/
└── api.php                                 # API routes
```

## 🔒 Security Features

- ✅ URL validation and sanitization
- ✅ Command injection prevention (Symfony Process)
- ✅ UUID-based temporary directories
- ✅ Automatic file cleanup
- ✅ Domain whitelist validation

## 📝 Environment Variables

| Variable | Description | Required |
|----------|-------------|----------|
| `TELEGRAM_BOT_TOKEN` | Telegram bot token from @BotFather | Yes |
| `QUEUE_CONNECTION` | Queue driver (redis/database) | Yes |
| `YT_DLP_PATH` | Path to yt-dlp binary | No (default: yt-dlp) |
| `DOWNLOAD_TIMEOUT` | Download timeout in seconds | No (default: 60) |

## 🚀 Production Deployment

See [DEPLOYMENT.md](DEPLOYMENT.md) for complete production deployment guide including:

- Server setup
- Queue worker configuration
- Webhook setup
- SSL/HTTPS configuration
- Monitoring and maintenance
- Troubleshooting

## 📊 Queue Configuration

The bot uses Laravel queues for async processing:

- **Queue Name**: `downloads`
- **Max Tries**: 2
- **Timeout**: 60 seconds
- **Driver**: Redis (recommended) or Database

## 🐛 Error Handling

The bot handles various error scenarios:

- Invalid URLs → User-friendly error message
- Private posts → Error message
- Download failures → Error message with logging
- Timeout errors → Automatic retry
- Telegram API errors → Logged and handled gracefully

## 📁 File Structure

```
storage/
└── app/
    └── temp/
        └── downloads/          # Temporary download directory
            └── {uuid}/         # UUID-based job directories
```

Temporary files are automatically cleaned up after sending to user.

## 🔧 Development

```bash
# Run queue worker
php artisan queue:work

# Clear cache
php artisan config:clear
php artisan cache:clear

# View logs
tail -f storage/logs/laravel.log
```

## 📄 License

MIT License

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## ⚠️ Disclaimer

This bot is for educational purposes. Ensure you comply with:
- Instagram Terms of Service
- TikTok Terms of Service
- Copyright laws in your jurisdiction
- Telegram Bot API Terms of Service

Use responsibly and respect content creators' rights.
