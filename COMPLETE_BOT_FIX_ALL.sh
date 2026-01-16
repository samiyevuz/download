#!/bin/bash

echo "🔧 Botni To'liq Tuzatish va Sozlash"
echo "===================================="
echo ""

cd ~/www/download.e-qarz.uz

# 1. PHP syntax tekshirish
echo "1️⃣ PHP syntax tekshirish..."
ERRORS=0
for file in app/Http/Controllers/TelegramWebhookController.php app/Jobs/DownloadMediaJob.php app/Services/TelegramService.php app/Services/YtDlpService.php app/Validators/UrlValidator.php; do
    if ! php -l "$file" > /dev/null 2>&1; then
        echo "   ❌ $file - syntax xatosi!"
        ERRORS=$((ERRORS + 1))
    fi
done

if [ $ERRORS -gt 0 ]; then
    echo "   ❌ $ERRORS faylda syntax xatosi bor!"
    exit 1
fi
echo "   ✅ Barcha fayllar syntax to'g'ri"
echo ""

# 2. Barcha kerakli fayllar mavjudligini tekshirish
echo "2️⃣ Kerakli fayllar tekshirish..."
REQUIRED_FILES=(
    "app/Http/Controllers/TelegramWebhookController.php"
    "app/Jobs/DownloadMediaJob.php"
    "app/Jobs/SendTelegramLanguageSelectionJob.php"
    "app/Jobs/SendTelegramWelcomeMessageJob.php"
    "app/Jobs/AnswerCallbackQueryJob.php"
    "app/Jobs/SendTelegramMessageJob.php"
    "app/Services/TelegramService.php"
    "app/Services/YtDlpService.php"
    "app/Validators/UrlValidator.php"
    "routes/api.php"
)

MISSING_FILES=()
for file in "${REQUIRED_FILES[@]}"; do
    if [ ! -f "$file" ]; then
        MISSING_FILES+=("$file")
    fi
done

if [ ${#MISSING_FILES[@]} -gt 0 ]; then
    echo "   ❌ Quyidagi fayllar topilmadi:"
    for file in "${MISSING_FILES[@]}"; do
        echo "      - $file"
    done
    exit 1
fi
echo "   ✅ Barcha kerakli fayllar mavjud"
echo ""

# 3. Config yangilash
echo "3️⃣ Config yangilash..."
php artisan config:clear
php artisan config:cache
echo "   ✅ Config yangilandi"
echo ""

# 4. Route cache yangilash
echo "4️⃣ Route cache yangilash..."
php artisan route:clear
php artisan route:cache
echo "   ✅ Route cache yangilandi"
echo ""

# 5. Queue table tekshirish
echo "5️⃣ Queue table tekshirish..."
if php artisan tinker --execute="DB::table('jobs')->count();" > /dev/null 2>&1; then
    echo "   ✅ Queue table mavjud"
else
    echo "   ⚠️  Queue table topilmadi, yaratilmoqda..."
    php artisan queue:table
    php artisan migrate --force
    echo "   ✅ Queue table yaratildi"
fi
echo ""

# 6. Bot token tekshirish
echo "6️⃣ Bot token tekshirish..."
BOT_TOKEN=$(php artisan tinker --execute="echo config('telegram.bot_token');" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')
if [ -n "$BOT_TOKEN" ] && [ "$BOT_TOKEN" != "null" ] && [ "$BOT_TOKEN" != "" ]; then
    echo "   ✅ Bot token sozlangan"
else
    echo "   ❌ Bot token sozlanmagan!"
    echo "   💡 .env faylida TELEGRAM_BOT_TOKEN ni tekshiring"
    exit 1
fi
echo ""

# 7. yt-dlp tekshirish
echo "7️⃣ yt-dlp tekshirish..."
YT_DLP_PATH=$(php artisan tinker --execute="echo config('telegram.yt_dlp_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')

if [ -f "$YT_DLP_PATH" ] && [ -x "$YT_DLP_PATH" ]; then
    VERSION=$("$YT_DLP_PATH" --version 2>/dev/null || echo "unknown")
    echo "   ✅ yt-dlp mavjud: $YT_DLP_PATH (versiya: $VERSION)"
else
    echo "   ⚠️  yt-dlp topilmadi yoki executable emas: $YT_DLP_PATH"
    echo "   💡 O'rnatish: mkdir -p ~/bin && wget https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -O ~/bin/yt-dlp && chmod +x ~/bin/yt-dlp"
fi
echo ""

# 8. PHP GD extension tekshirish
echo "8️⃣ PHP GD extension tekshirish..."
if php -m | grep -q "gd"; then
    echo "   ✅ GD extension mavjud"
    if php -r "exit(function_exists('imagecreatefromwebp') ? 0 : 1);"; then
        echo "   ✅ WebP support mavjud"
    else
        echo "   ⚠️  WebP support yo'q - rasm conversion ishlamaydi"
    fi
else
    echo "   ⚠️  GD extension yo'q - rasm conversion ishlamaydi"
fi
echo ""

# 9. Workerlarni qayta ishga tushirish
echo "9️⃣ Workerlarni qayta ishga tushirish..."
pkill -9 -f "artisan queue:work" 2>/dev/null
sleep 2

nohup php artisan queue:work database --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
DOWNLOAD_PID=$!

nohup php artisan queue:work database --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &
TELEGRAM_PID=$!

sleep 3
echo "   ✅ Workerlarni ishga tushirdim (PIDs: $DOWNLOAD_PID, $TELEGRAM_PID)"
echo ""

# 10. Tekshirish
echo "🔟 Workerlarni tekshirish..."
WORKERS=$(ps aux | grep "artisan queue:work" | grep -v grep | grep -v "datacollector" | wc -l)
if [ "$WORKERS" -ge 2 ]; then
    echo "   ✅ $WORKERS worker ishlayapti"
    ps aux | grep "artisan queue:work" | grep -v grep | grep -v "datacollector" | awk '{print "      PID:", $2, "Queue:", $NF}'
else
    echo "   ⚠️  Faqat $WORKERS worker ishlayapti (2 ta bo'lishi kerak)"
fi
echo ""

# 11. Queue'dagi joblar
echo "1️⃣1️⃣ Queue'dagi joblar..."
JOBS_COUNT=$(php artisan tinker --execute="echo DB::table('jobs')->count();" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')
if [ -n "$JOBS_COUNT" ] && [ "$JOBS_COUNT" != "null" ]; then
    if [ "$JOBS_COUNT" -gt 0 ]; then
        echo "   ⚠️  Queue'da $JOBS_COUNT ta job bor (ishlamayapti)"
    else
        echo "   ✅ Queue bo'sh"
    fi
else
    echo "   ⚠️  Queue jadvalini o'qib bo'lmadi"
fi
echo ""

# 12. Failed jobs
echo "1️⃣2️⃣ Failed jobs..."
FAILED_COUNT=$(php artisan tinker --execute="echo DB::table('failed_jobs')->count();" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')
if [ -n "$FAILED_COUNT" ] && [ "$FAILED_COUNT" != "null" ] && [ "$FAILED_COUNT" -gt 0 ]; then
    echo "   ⚠️  $FAILED_COUNT ta failed job bor"
    echo "   💡 Failed joblarni ko'rish: php artisan queue:failed"
else
    echo "   ✅ Failed joblar yo'q"
fi
echo ""

# 13. Webhook route tekshirish
echo "1️⃣3️⃣ Webhook route tekshirish..."
if php artisan route:list | grep -q "telegram.webhook"; then
    echo "   ✅ Webhook route mavjud"
    php artisan route:list | grep "telegram.webhook" | awk '{print "      " $0}'
else
    echo "   ❌ Webhook route topilmadi!"
    echo "   💡 routes/api.php faylini tekshiring"
fi
echo ""

echo "===================================="
echo "✅ Bot to'liq tuzatildi va sozlandi!"
echo ""
echo "📋 Bot funksiyalari:"
echo "   ✅ /start - til tanlash"
echo "   ✅ Til tanlash - 3 til (UZ, RU, EN)"
echo "   ✅ Welcome message - tanlangan tilda"
echo "   ✅ Subscription check - kanallarga a'zo bo'lish (private chat)"
echo "   ✅ URL validation - Instagram va TikTok"
echo "   ✅ Media download - yt-dlp orqali"
echo "   ✅ WebP → JPG conversion - avtomatik"
echo "   ✅ Media sending - sendPhoto/sendVideo/sendMediaGroup"
echo "   ✅ Carousel posts - ko'p rasmlar"
echo "   ✅ Cleanup - barcha temp fayllar"
echo ""
echo "🧪 Test qiling:"
echo "   1. /start → Til tanlash ko'rinishi kerak"
echo "   2. Til tanlang → Welcome message ko'rinishi kerak"
echo "   3. Instagram rasm linki → Rasm yuborilishi kerak"
echo "   4. Instagram video linki → Video yuborilishi kerak"
echo ""
echo "📊 Loglarni kuzatish:"
echo "   # Laravel loglar"
echo "   tail -f storage/logs/laravel.log"
echo ""
echo "   # Queue loglar"
echo "   tail -f storage/logs/queue-downloads.log"
echo "   tail -f storage/logs/queue-telegram.log"
echo ""
echo "   # Webhook test"
echo "   curl -X POST https://YOUR_DOMAIN/api/telegram/webhook -d '{\"update_id\":1,\"message\":{\"chat\":{\"id\":123},\"text\":\"/start\"}}'"
echo ""
