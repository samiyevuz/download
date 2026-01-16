#!/bin/bash

echo "🔧 Video Yuklash Tuzatish"
echo "=========================="
echo ""

cd ~/www/download.e-qarz.uz

# 1. PHP syntax tekshirish
echo "1️⃣ PHP syntax tekshirish..."
php -l app/Jobs/DownloadMediaJob.php

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

# 3. Workerlarni qayta ishga tushirish
echo "3️⃣ Workerlarni qayta ishga tushirish..."
pkill -9 -f "artisan queue:work" 2>/dev/null
sleep 2

nohup php artisan queue:work database --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
DOWNLOAD_PID=$!

nohup php artisan queue:work database --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &
TELEGRAM_PID=$!

sleep 3
echo "✅ Workerlarni ishga tushirdim (PIDs: $DOWNLOAD_PID, $TELEGRAM_PID)"
echo ""

# 4. Tekshirish
echo "4️⃣ Workerlarni tekshirish..."
WORKERS=$(ps aux | grep "artisan queue:work" | grep -v grep | grep -v "datacollector" | wc -l)
if [ "$WORKERS" -ge 2 ]; then
    echo "✅ $WORKERS worker ishlayapti"
    ps aux | grep "artisan queue:work" | grep -v grep | grep -v "datacollector" | awk '{print "   PID:", $2, "Queue:", $NF}'
else
    echo "⚠️  Faqat $WORKERS worker ishlayapti"
fi
echo ""

# 5. Queue'dagi joblar
echo "5️⃣ Queue'dagi joblar..."
JOBS_COUNT=$(php artisan tinker --execute="echo DB::table('jobs')->count();" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')
if [ -n "$JOBS_COUNT" ] && [ "$JOBS_COUNT" != "null" ]; then
    if [ "$JOBS_COUNT" -gt 0 ]; then
        echo "   ⚠️  Queue'da $JOBS_COUNT ta job bor"
    else
        echo "   ✅ Queue bo'sh"
    fi
else
    echo "   ⚠️  Queue jadvalini o'qib bo'lmadi"
fi
echo ""

# 6. Failed jobs
echo "6️⃣ Failed jobs..."
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
echo "🔧 Tuzatilgan muammolar:"
echo "   ✨ Video fayl mavjudligini tekshirish qo'shildi"
echo "   ✨ Video yuborishdan oldin batafsil logging"
echo "   ✨ 'Downloading...' xabari o'chiriladi"
echo "   ✨ Agar media topilmasa, xato xabari yuboriladi"
echo ""
echo "🧪 Test qiling:"
echo "   1. Instagram yoki TikTok video linkini yuboring"
echo "   2. Video yuklanadi va yuboriladi"
echo ""
echo "📊 Loglarni kuzatish:"
echo "   # Laravel loglar"
echo "   tail -f storage/logs/laravel.log | grep -E 'DownloadMediaJob|Sending videos|ytDlpService|sendVideo'"
echo ""
echo "   # Queue loglar"
echo "   tail -f storage/logs/queue-downloads.log"
echo ""
echo "   # Queue status"
echo "   chmod +x CHECK_DOWNLOAD_JOB_STATUS.sh"
echo "   ./CHECK_DOWNLOAD_JOB_STATUS.sh"
echo ""
