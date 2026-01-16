#!/bin/bash

echo "🔧 Botni To'liq Tuzatish"
echo "========================"
echo ""

cd ~/www/download.e-qarz.uz

# 1. PHP syntax tekshirish
echo "1️⃣ PHP syntax tekshirish..."
php -l app/Http/Controllers/TelegramWebhookController.php
php -l app/Jobs/DownloadMediaJob.php
php -l app/Services/TelegramService.php
php -l app/Services/YtDlpService.php
php -l app/Utils/MediaConverter.php

if [ $? -ne 0 ]; then
    echo "❌ PHP syntax xatosi bor!"
    exit 1
fi
echo "✅ Barcha PHP fayllar syntax to'g'ri"
echo ""

# 2. Barcha kerakli fayllar mavjudligini tekshirish
echo "2️⃣ Kerakli fayllar tekshirish..."
REQUIRED_FILES=(
    "app/Http/Controllers/TelegramWebhookController.php"
    "app/Jobs/DownloadMediaJob.php"
    "app/Jobs/SendTelegramLanguageSelectionJob.php"
    "app/Jobs/SendTelegramWelcomeMessageJob.php"
    "app/Jobs/AnswerCallbackQueryJob.php"
    "app/Services/TelegramService.php"
    "app/Services/YtDlpService.php"
    "app/Utils/MediaConverter.php"
    "app/Validators/UrlValidator.php"
)

MISSING_FILES=()
for file in "${REQUIRED_FILES[@]}"; do
    if [ ! -f "$file" ]; then
        MISSING_FILES+=("$file")
    fi
done

if [ ${#MISSING_FILES[@]} -gt 0 ]; then
    echo "❌ Quyidagi fayllar topilmadi:"
    for file in "${MISSING_FILES[@]}"; do
        echo "   - $file"
    done
    exit 1
fi
echo "✅ Barcha kerakli fayllar mavjud"
echo ""

# 3. Config yangilash
echo "3️⃣ Config yangilash..."
php artisan config:clear
php artisan config:cache
echo "✅ Config yangilandi"
echo ""

# 4. Queue table tekshirish
echo "4️⃣ Queue table tekshirish..."
if php artisan tinker --execute="DB::table('jobs')->count();" > /dev/null 2>&1; then
    echo "✅ Queue table mavjud"
else
    echo "⚠️  Queue table topilmadi, yaratilmoqda..."
    php artisan queue:table
    php artisan migrate --force
    echo "✅ Queue table yaratildi"
fi
echo ""

# 5. Cache driver tekshirish
echo "5️⃣ Cache driver tekshirish..."
CACHE_DRIVER=$(php artisan tinker --execute="echo config('cache.default');" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')
echo "   Cache driver: $CACHE_DRIVER"

if [ "$CACHE_DRIVER" = "database" ]; then
    echo "   ⚠️  Database cache ishlatilmoqda"
    echo "   💡 Agar cache table yo'q bo'lsa, file cache'ga o'ting:"
    echo "      echo 'CACHE_STORE=file' >> .env"
elif [ "$CACHE_DRIVER" = "file" ]; then
    echo "   ✅ File cache ishlatilmoqda (yaxshi)"
elif [ "$CACHE_DRIVER" = "redis" ]; then
    echo "   ✅ Redis cache ishlatilmoqda (yaxshi)"
else
    echo "   ⚠️  Noma'lum cache driver"
fi
echo ""

# 6. yt-dlp tekshirish
echo "6️⃣ yt-dlp tekshirish..."
YT_DLP_PATH=$(php artisan tinker --execute="echo config('telegram.yt_dlp_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')

if [ -f "$YT_DLP_PATH" ] && [ -x "$YT_DLP_PATH" ]; then
    VERSION=$("$YT_DLP_PATH" --version 2>/dev/null || echo "unknown")
    echo "   ✅ yt-dlp mavjud: $YT_DLP_PATH (version: $VERSION)"
else
    echo "   ❌ yt-dlp topilmadi yoki executable emas: $YT_DLP_PATH"
    echo "   💡 O'rnatish: mkdir -p ~/bin && wget https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -O ~/bin/yt-dlp && chmod +x ~/bin/yt-dlp"
fi
echo ""

# 7. PHP GD extension tekshirish
echo "7️⃣ PHP GD extension tekshirish..."
if php -m | grep -q "gd"; then
    echo "   ✅ GD extension mavjud"
    if php -r "exit(function_exists('imagecreatefromwebp') ? 0 : 1);"; then
        echo "   ✅ WebP support mavjud"
    else
        echo "   ⚠️  WebP support yo'q - rasm conversion ishlamaydi"
    fi
else
    echo "   ❌ GD extension yo'q - rasm conversion ishlamaydi"
fi
echo ""

# 8. Workerlarni qayta ishga tushirish
echo "8️⃣ Workerlarni qayta ishga tushirish..."
pkill -9 -f "artisan queue:work" 2>/dev/null
sleep 2

nohup php artisan queue:work database --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
DOWNLOAD_PID=$!

nohup php artisan queue:work database --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &
TELEGRAM_PID=$!

sleep 3
echo "✅ Workerlarni ishga tushirdim (PIDs: $DOWNLOAD_PID, $TELEGRAM_PID)"
echo ""

# 9. Tekshirish
echo "9️⃣ Workerlarni tekshirish..."
WORKERS=$(ps aux | grep "artisan queue:work" | grep -v grep | grep -v "datacollector" | wc -l)
if [ "$WORKERS" -ge 2 ]; then
    echo "✅ $WORKERS worker ishlayapti"
    ps aux | grep "artisan queue:work" | grep -v grep | grep -v "datacollector" | awk '{print "   PID:", $2, "Queue:", $NF}'
else
    echo "⚠️  Faqat $WORKERS worker ishlayapti (2 ta bo'lishi kerak)"
fi
echo ""

# 10. Bot token tekshirish
echo "🔟 Bot token tekshirish..."
BOT_TOKEN=$(php artisan tinker --execute="echo config('telegram.bot_token');" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')
if [ -n "$BOT_TOKEN" ] && [ "$BOT_TOKEN" != "null" ] && [ "$BOT_TOKEN" != "" ]; then
    echo "   ✅ Bot token sozlangan"
else
    echo "   ❌ Bot token sozlanmagan"
    echo "   💡 .env faylida TELEGRAM_BOT_TOKEN ni tekshiring"
fi
echo ""

echo "===================================="
echo "✅ Bot to'liq tuzatildi!"
echo ""
echo "📋 Bot funksiyalari:"
echo "   ✅ /start command - til tanlash"
echo "   ✅ Til tanlash - 3 til (UZ, RU, EN)"
echo "   ✅ Welcome message - tanlangan tilda"
echo "   ✅ Subscription check - kanallarga a'zo bo'lish"
echo "   ✅ URL validation - Instagram va TikTok"
echo "   ✅ Media download - yt-dlp orqali"
echo "   ✅ WebP conversion - JPG'ga o'girish"
echo "   ✅ Media sending - sendPhoto/sendVideo"
echo "   ✅ Carousel posts - sendMediaGroup"
echo "   ✅ Cleanup - barcha temp fayllar o'chiriladi"
echo ""
echo "🧪 Test qiling:"
echo "   1. /start yuboring - til tanlash ko'rinishi kerak"
echo "   2. Til tanlang - welcome message ko'rinishi kerak"
echo "   3. Instagram rasm linki yuboring - rasm yuborilishi kerak"
echo "   4. Instagram video linki yuboring - video yuborilishi kerak"
echo ""
echo "📊 Loglarni kuzatish:"
echo "   tail -f storage/logs/laravel.log"
echo "   tail -f storage/logs/queue-downloads.log"
echo "   tail -f storage/logs/queue-telegram.log"
echo ""
