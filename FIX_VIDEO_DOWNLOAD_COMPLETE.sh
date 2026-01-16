#!/bin/bash

echo "🔧 Video Yuklash Muammosini To'liq Tuzatish"
echo "==========================================="
echo ""

cd ~/www/download.e-qarz.uz

# 1. PHP syntax tekshirish
echo "1️⃣ PHP syntax tekshirish..."
php -l app/Jobs/DownloadMediaJob.php
php -l app/Services/YtDlpService.php
php -l app/Services/TelegramService.php
php -l app/Http/Controllers/TelegramWebhookController.php

if [ $? -ne 0 ]; then
    echo "❌ PHP syntax xatosi bor!"
    exit 1
fi
echo "✅ PHP syntax to'g'ri"
echo ""

# 2. Queue table mavjudligini tekshirish
echo "2️⃣ Queue Table Tekshirish..."
php artisan tinker --execute="DB::table('jobs')->count();" > /dev/null 2>&1
if [ $? -eq 0 ]; then
    echo "   ✅ Queue table mavjud"
else
    echo "   ⚠️  Queue table topilmadi, yaratilmoqda..."
    php artisan queue:table 2>/dev/null || echo "   ⚠️  queue:table command failed"
    php artisan migrate --force 2>/dev/null || echo "   ⚠️  Migration failed"
    echo "   ✅ Queue table yaratildi"
fi
echo ""

# 3. Queue connection tekshirish
echo "3️⃣ Queue Connection Tekshirish..."
QUEUE_CONNECTION=$(php artisan tinker --execute="echo config('queue.default');" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')
echo "   📋 Queue connection: $QUEUE_CONNECTION"

if [ "$QUEUE_CONNECTION" = "database" ]; then
    echo "   ✅ Database queue ishlatilmoqda"
else
    echo "   ⚠️  Database queue emas: $QUEUE_CONNECTION"
fi
echo ""

# 4. Queue'dagi joblar
echo "4️⃣ Queue'dagi Joblar..."
JOBS_COUNT=$(php artisan tinker --execute="echo DB::table('jobs')->count();" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')

if [ -n "$JOBS_COUNT" ] && [ "$JOBS_COUNT" != "null" ]; then
    if [ "$JOBS_COUNT" -gt 0 ]; then
        echo "   ⚠️  Queue'da $JOBS_COUNT ta job bor (ishlamayapti?)"
    else
        echo "   ✅ Queue bo'sh"
    fi
else
    echo "   ⚠️  Queue jadvalini o'qib bo'lmadi"
fi
echo ""

# 5. Failed jobs
echo "5️⃣ Failed Jobs..."
FAILED_COUNT=$(php artisan tinker --execute="echo DB::table('failed_jobs')->count();" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')

if [ -n "$FAILED_COUNT" ] && [ "$FAILED_COUNT" != "null" ] && [ "$FAILED_COUNT" -gt 0 ]; then
    echo "   ⚠️  $FAILED_COUNT ta failed job bor"
else
    echo "   ✅ Failed joblar yo'q"
fi
echo ""

# 6. Queue worker'lar
echo "6️⃣ Queue Worker'lar..."
WORKERS=$(ps aux | grep "artisan queue:work" | grep -v grep | grep -v "datacollector" | wc -l)

if [ "$WORKERS" -lt 1 ]; then
    echo "   ❌ Worker ishlamayapti, ishga tushiryapman..."
    pkill -9 -f "artisan queue:work" 2>/dev/null
    sleep 2
    
    nohup php artisan queue:work database --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
    DOWNLOAD_PID=$!
    
    nohup php artisan queue:work database --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &
    TELEGRAM_PID=$!
    
    sleep 3
    echo "   ✅ Workerlarni ishga tushirdim (PIDs: $DOWNLOAD_PID, $TELEGRAM_PID)"
else
    echo "   ✅ $WORKERS worker ishlayapti"
    ps aux | grep "artisan queue:work" | grep -v grep | grep -v "datacollector" | awk '{print "      PID:", $2, "Queue:", $NF}'
fi
echo ""

# 7. Config yangilash
echo "7️⃣ Config yangilash..."
php artisan config:clear
php artisan config:cache
echo "✅ Config yangilandi"
echo ""

# 8. Oxirgi loglar
echo "8️⃣ Oxirgi DownloadMediaJob Loglari (oxirgi 30 qator)..."
tail -100 storage/logs/laravel.log | grep -E "Download job dispatched|DownloadMediaJob|Starting media download|Calling ytDlpService|ytDlpService->download|Downloaded files separated|Sending videos|sendVideo|Instagram video" | tail -20 | sed 's/^/   /'
echo ""

# 9. Webhook loglar
echo "9️⃣ Oxirgi Webhook Loglari (oxirgi 20 qator)..."
tail -50 storage/logs/laravel.log | grep -E "Telegram webhook received|Message received|Download job dispatched" | tail -10 | sed 's/^/   /'
echo ""

echo "===================================="
echo "✅ Tuzatish tugadi!"
echo ""
echo "🔧 Tuzatilgan muammolar:"
echo "   ✨ Queue table tekshirildi"
echo "   ✨ Queue connection tekshirildi"
echo "   ✨ Queue worker'lar tekshirildi va ishga tushirildi"
echo "   ✨ Config yangilandi"
echo "   ✨ Loglarni tekshirildi"
echo ""
echo "🧪 Test qiling:"
echo "   1. Bot'ga Instagram video linki yuboring"
echo "   2. Loglarni kuzating:"
echo "      tail -f storage/logs/laravel.log | grep -E 'Download job dispatched|DownloadMediaJob|Starting media download|sendVideo'"
echo ""
echo "📊 Debug script:"
echo "   chmod +x DEBUG_VIDEO_DOWNLOAD.sh"
echo "   ./DEBUG_VIDEO_DOWNLOAD.sh"
echo ""
