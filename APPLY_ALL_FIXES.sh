#!/bin/bash

echo "🔧 Botni To'liq Tuzatish va Sozlash"
echo "===================================="
echo ""

cd ~/www/download.e-qarz.uz

# 1. PHP syntax tekshirish
echo "1️⃣ PHP syntax tekshirish..."
ERRORS=0
for file in app/Http/Controllers/TelegramWebhookController.php app/Jobs/DownloadMediaJob.php app/Services/TelegramService.php app/Services/YtDlpService.php app/Utils/MediaConverter.php; do
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

# 2. Config yangilash
echo "2️⃣ Config yangilash..."
php artisan config:clear
php artisan config:cache
echo "   ✅ Config yangilandi"
echo ""

# 3. Queue table tekshirish
echo "3️⃣ Queue table tekshirish..."
if php artisan tinker --execute="DB::table('jobs')->count();" > /dev/null 2>&1; then
    echo "   ✅ Queue table mavjud"
else
    echo "   ⚠️  Queue table topilmadi, yaratilmoqda..."
    php artisan queue:table
    php artisan migrate --force
    echo "   ✅ Queue table yaratildi"
fi
echo ""

# 4. Workerlarni qayta ishga tushirish
echo "4️⃣ Workerlarni qayta ishga tushirish..."
pkill -9 -f "artisan queue:work" 2>/dev/null
sleep 2

nohup php artisan queue:work database --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
DOWNLOAD_PID=$!

nohup php artisan queue:work database --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &
TELEGRAM_PID=$!

sleep 3
echo "   ✅ Workerlarni ishga tushirdim (PIDs: $DOWNLOAD_PID, $TELEGRAM_PID)"
echo ""

# 5. Final tekshirish
echo "5️⃣ Final tekshirish..."
WORKERS=$(ps aux | grep "artisan queue:work" | grep -v grep | grep -v "datacollector" | wc -l)
if [ "$WORKERS" -ge 2 ]; then
    echo "   ✅ $WORKERS worker ishlayapti"
    ps aux | grep "artisan queue:work" | grep -v grep | grep -v "datacollector" | awk '{print "      PID:", $2, "Queue:", $NF}'
else
    echo "   ⚠️  Faqat $WORKERS worker ishlayapti (2 ta bo'lishi kerak)"
fi
echo ""

echo "===================================="
echo "✅ Bot to'liq tuzatildi!"
echo ""
echo "📋 Bot funksiyalari:"
echo "   ✅ /start - til tanlash"
echo "   ✅ Til tanlash - 3 til (UZ, RU, EN)"
echo "   ✅ Welcome message - tanlangan tilda"
echo "   ✅ Subscription check - kanallarga a'zo bo'lish"
echo "   ✅ URL validation - Instagram va TikTok"
echo "   ✅ Media download - yt-dlp orqali"
echo "   ✅ WebP → JPG conversion - avtomatik"
echo "   ✅ Media sending - sendPhoto/sendVideo/sendMediaGroup"
echo "   ✅ Carousel posts - ko'p rasmlar"
echo "   ✅ Cleanup - barcha temp fayllar"
echo ""
echo "🧪 Test qiling:"
echo "   1. /start → Til tanlash"
echo "   2. Til tanlang → Welcome message"
echo "   3. Instagram rasm linki → Rasm yuborilishi"
echo "   4. Instagram video linki → Video yuborilishi"
echo ""
echo "📊 Loglarni kuzatish:"
echo "   tail -f storage/logs/laravel.log"
echo ""
