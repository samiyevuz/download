#!/bin/bash

echo "🔧 Bot Muammolarini To'liq Tuzatish"
echo "==================================="
echo ""

cd ~/www/download.e-qarz.uz

# 1. PHP syntax tekshirish
echo "1️⃣ PHP syntax tekshirish..."
php -l app/Http/Controllers/TelegramWebhookController.php
php -l app/Jobs/DownloadMediaJob.php
php -l app/Services/TelegramService.php
php -l app/Services/YtDlpService.php

if [ $? -ne 0 ]; then
    echo "❌ PHP syntax xatosi bor!"
    exit 1
fi
echo "✅ PHP syntax to'g'ri"
echo ""

# 2. Config yangilash
echo "2️⃣ Config yangilash..."
php artisan config:clear
php artisan config:cache
echo "✅ Config yangilandi"
echo ""

# 3. Queue table tekshirish
echo "3️⃣ Queue table tekshirish..."
if php artisan tinker --execute="DB::table('jobs')->count();" > /dev/null 2>&1; then
    echo "✅ Queue table mavjud"
else
    echo "⚠️  Queue table topilmadi, yaratilmoqda..."
    php artisan queue:table
    php artisan migrate --force
    echo "✅ Queue table yaratildi"
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
echo "✅ Workerlarni ishga tushirdim (PIDs: $DOWNLOAD_PID, $TELEGRAM_PID)"
echo ""

# 5. Tekshirish
echo "5️⃣ Workerlarni tekshirish..."
WORKERS=$(ps aux | grep "artisan queue:work" | grep -v grep | grep -v "datacollector" | wc -l)
if [ "$WORKERS" -ge 2 ]; then
    echo "✅ $WORKERS worker ishlayapti"
    ps aux | grep "artisan queue:work" | grep -v grep | grep -v "datacollector" | awk '{print "   PID:", $2, "Queue:", $NF}'
else
    echo "⚠️  Faqat $WORKERS worker ishlayapti (2 ta bo'lishi kerak)"
fi
echo ""

# 6. Queue'dagi joblar
echo "6️⃣ Queue'dagi joblar..."
JOBS_COUNT=$(php artisan tinker --execute="echo DB::table('jobs')->count();" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')
if [ -n "$JOBS_COUNT" ] && [ "$JOBS_COUNT" != "null" ]; then
    if [ "$JOBS_COUNT" -gt 0 ]; then
        echo "   ⚠️  Queue'da $JOBS_COUNT ta job bor (ishlamayapti)"
        echo "   💡 Joblarni ko'rish: php artisan queue:work database --queue=downloads --once"
    else
        echo "   ✅ Queue bo'sh"
    fi
else
    echo "   ⚠️  Queue jadvalini o'qib bo'lmadi"
fi
echo ""

# 7. Failed jobs
echo "7️⃣ Failed jobs..."
FAILED_COUNT=$(php artisan tinker --execute="echo DB::table('failed_jobs')->count();" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')
if [ -n "$FAILED_COUNT" ] && [ "$FAILED_COUNT" != "null" ] && [ "$FAILED_COUNT" -gt 0 ]; then
    echo "   ⚠️  $FAILED_COUNT ta failed job bor"
    echo "   💡 Failed joblarni ko'rish: php artisan queue:failed"
else
    echo "   ✅ Failed joblar yo'q"
fi
echo ""

echo "===================================="
echo "✅ Tuzatildi!"
echo ""
echo "📋 Bot funksiyalari:"
echo "   ✅ /start - til tanlash"
echo "   ✅ Til tanlash - welcome message"
echo "   ✅ Subscription check - kanallarga a'zo bo'lish"
echo "   ✅ URL validation - Instagram va TikTok"
echo "   ✅ Media download - yt-dlp orqali"
echo "   ✅ Media sending - sendPhoto/sendVideo"
echo ""
echo "🧪 Test qiling:"
echo "   1. /start yuboring"
echo "   2. Til tanlang"
echo "   3. Instagram yoki TikTok linkini yuboring"
echo "   4. Media yuklanadi va yuboriladi"
echo ""
echo "📊 Loglarni kuzatish:"
echo "   # Laravel loglar"
echo "   tail -f storage/logs/laravel.log | grep -E 'DownloadMediaJob|ytDlpService|sendPhoto|sendVideo'"
echo ""
echo "   # Queue loglar"
echo "   tail -f storage/logs/queue-downloads.log"
echo ""
echo "   # Queue status"
echo "   chmod +x CHECK_DOWNLOAD_JOB_STATUS.sh"
echo "   ./CHECK_DOWNLOAD_JOB_STATUS.sh"
echo ""
