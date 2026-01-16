#!/bin/bash

echo "🚀 Production Deployment Script (No Sudo Required)"
echo "=================================================="
echo ""

# Get project directory
PROJECT_DIR="${1:-$(pwd)}"
cd "$PROJECT_DIR" || exit 1

echo "📁 Project directory: $PROJECT_DIR"
echo ""

# 1. Check PHP version
echo "1️⃣ Checking PHP version..."
PHP_VERSION=$(php -r "echo PHP_VERSION;")
echo "   PHP Version: $PHP_VERSION"

if php -r "exit(version_compare(PHP_VERSION, '8.1.0', '<') ? 1 : 0);"; then
    echo "   ✅ PHP 8.1+ detected"
else
    echo "   ❌ PHP 8.1+ required"
    exit 1
fi
echo ""

# 2. Check GD extension
echo "2️⃣ Checking GD extension..."
if php -m | grep -q "gd"; then
    echo "   ✅ GD extension found"
    
    if php -r "exit(function_exists('imagecreatefromwebp') ? 0 : 1);"; then
        echo "   ✅ WebP support available"
    else
        echo "   ⚠️  WebP support NOT available - images may fail"
    fi
else
    echo "   ❌ GD extension NOT found - WebP conversion will fail"
    echo "   💡 Contact hosting provider to enable GD extension"
fi
echo ""

# 3. Check yt-dlp
echo "3️⃣ Checking yt-dlp..."
YT_DLP_PATH=$(php artisan tinker --execute="echo config('telegram.yt_dlp_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1)

if [ -f "$YT_DLP_PATH" ] && [ -x "$YT_DLP_PATH" ]; then
    VERSION=$("$YT_DLP_PATH" --version 2>/dev/null || echo "unknown")
    echo "   ✅ yt-dlp found: $YT_DLP_PATH (version: $VERSION)"
else
    echo "   ❌ yt-dlp not found or not executable: $YT_DLP_PATH"
    echo "   💡 Install yt-dlp: mkdir -p ~/bin && wget https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -O ~/bin/yt-dlp && chmod +x ~/bin/yt-dlp"
    exit 1
fi
echo ""

# 4. Check database connection
echo "4️⃣ Checking database connection..."
if php artisan tinker --execute="DB::connection()->getPdo();" > /dev/null 2>&1; then
    echo "   ✅ Database connection OK"
else
    echo "   ❌ Database connection failed"
    echo "   💡 Check .env file for database credentials"
    exit 1
fi
echo ""

# 5. Check queue table
echo "5️⃣ Checking queue table..."
if php artisan tinker --execute="DB::table('jobs')->count();" > /dev/null 2>&1; then
    echo "   ✅ Queue table exists"
else
    echo "   ⚠️  Queue table not found, creating..."
    php artisan queue:table
    php artisan migrate --force
    echo "   ✅ Queue table created"
fi
echo ""

# 6. Clear and cache config
echo "6️⃣ Clearing and caching config..."
php artisan config:clear
php artisan config:cache
echo "   ✅ Config cached"
echo ""

# 7. Create required directories
echo "7️⃣ Creating required directories..."
mkdir -p storage/app/temp/downloads
mkdir -p storage/app/cookies
mkdir -p storage/logs
chmod -R 755 storage
echo "   ✅ Directories created"
echo ""

# 8. Check webhook
echo "8️⃣ Checking webhook configuration..."
BOT_TOKEN=$(php artisan tinker --execute="echo config('telegram.bot_token');" 2>&1 | grep -v "Psy\|tinker" | tail -1)
if [ -n "$BOT_TOKEN" ] && [ "$BOT_TOKEN" != "null" ]; then
    echo "   ✅ Bot token configured"
    echo "   💡 Set webhook: curl -X POST \"https://api.telegram.org/bot$BOT_TOKEN/setWebhook?url=https://YOUR_DOMAIN/api/telegram/webhook\""
else
    echo "   ❌ Bot token not configured"
    echo "   💡 Add TELEGRAM_BOT_TOKEN to .env"
fi
echo ""

# 9. Start queue worker
echo "9️⃣ Starting queue worker..."
# Kill existing workers
pkill -f "artisan queue:work" 2>/dev/null
sleep 2

# Start new worker
nohup php artisan queue:work database --queue=downloads,telegram --tries=2 --timeout=60 > storage/logs/queue.log 2>&1 &
WORKER_PID=$!

sleep 2
if ps -p $WORKER_PID > /dev/null 2>&1; then
    echo "   ✅ Queue worker started (PID: $WORKER_PID)"
else
    echo "   ❌ Queue worker failed to start"
    echo "   💡 Check logs: tail -f storage/logs/queue.log"
fi
echo ""

# 10. Final checks
echo "🔟 Final checks..."
WORKERS=$(ps aux | grep "artisan queue:work" | grep -v grep | wc -l)
if [ "$WORKERS" -ge 1 ]; then
    echo "   ✅ $WORKERS queue worker(s) running"
else
    echo "   ⚠️  No queue workers running"
fi

echo ""
echo "===================================="
echo "✅ Deployment complete!"
echo ""
echo "📝 Next steps:"
echo "   1. Set webhook URL (see step 8)"
echo "   2. Test bot with /start command"
echo "   3. Monitor logs: tail -f storage/logs/laravel.log"
echo "   4. Monitor queue: tail -f storage/logs/queue.log"
echo ""
echo "🔄 To restart workers:"
echo "   pkill -f 'artisan queue:work'"
echo "   nohup php artisan queue:work database --queue=downloads,telegram --tries=2 --timeout=60 > storage/logs/queue.log 2>&1 &"
echo ""
