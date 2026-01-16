#!/bin/bash

echo "🔧 /start Command Tuzatish"
echo "==========================="
echo ""

cd ~/www/download.e-qarz.uz

# 1. PHP syntax tekshirish
echo "1️⃣ PHP syntax tekshirish..."
php -l app/Http/Controllers/TelegramWebhookController.php
php -l app/Jobs/SendTelegramLanguageSelectionJob.php
php -l app/Services/TelegramService.php

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

# 3. Queue worker'lar tekshirish
echo "3️⃣ Queue Worker'lar Tekshirish..."
WORKERS=$(ps aux | grep "artisan queue:work" | grep -v grep | grep -v "datacollector" | wc -l)

if [ "$WORKERS" -lt 2 ]; then
    echo "⚠️  Faqat $WORKERS worker ishlayapti (2 ta bo'lishi kerak)"
    echo "   Ishga tushiryapman..."
    
    # Eski worker'larni o'chirish
    pkill -9 -f "artisan queue:work" 2>/dev/null
    sleep 2
    
    # Yangi worker'larni ishga tushirish
    nohup php artisan queue:work database --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
    DOWNLOAD_PID=$!
    
    nohup php artisan queue:work database --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &
    TELEGRAM_PID=$!
    
    sleep 3
    
    # Tekshirish
    NEW_WORKERS=$(ps aux | grep "artisan queue:work" | grep -v grep | grep -v "datacollector" | wc -l)
    if [ "$NEW_WORKERS" -ge 2 ]; then
        echo "   ✅ $NEW_WORKERS worker ishga tushdi (PIDs: $DOWNLOAD_PID, $TELEGRAM_PID)"
    else
        echo "   ❌ Workerlar ishga tushmadi!"
    fi
else
    echo "   ✅ $WORKERS worker ishlayapti"
    ps aux | grep "artisan queue:work" | grep -v grep | grep -v "datacollector" | awk '{print "      PID:", $2, "Queue:", $NF}'
fi
echo ""

# 4. Queue'dagi joblar
echo "4️⃣ Queue'dagi Joblar..."
JOBS_COUNT=$(php artisan tinker --execute="echo DB::table('jobs')->count();" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')

if [ -n "$JOBS_COUNT" ] && [ "$JOBS_COUNT" != "null" ] && [ "$JOBS_COUNT" -gt 0 ]; then
    echo "   ⚠️  Queue'da $JOBS_COUNT ta job bor"
    echo "   💡 Bu joblar tez orada ishlashi kerak"
else
    echo "   ✅ Queue bo'sh"
fi
echo ""

# 5. Failed jobs
echo "5️⃣ Failed Jobs..."
FAILED_COUNT=$(php artisan tinker --execute="echo DB::table('failed_jobs')->count();" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')

if [ -n "$FAILED_COUNT" ] && [ "$FAILED_COUNT" != "null" ] && [ "$FAILED_COUNT" -gt 0 ]; then
    echo "   ⚠️  $FAILED_COUNT ta failed job bor"
    echo "   💡 Failed joblarni ko'rish: php artisan queue:failed"
else
    echo "   ✅ Failed joblar yo'q"
fi
echo ""

echo "===================================="
echo "✅ Tuzatish tugadi!"
echo ""
echo "🔧 Tuzatilgan muammolar:"
echo "   ✨ /start command'ga batafsil logging qo'shildi"
echo "   ✨ SendTelegramLanguageSelectionJob'ga logging qo'shildi"
echo "   ✨ sendMessageWithKeyboard'ga logging qo'shildi"
echo "   ✨ Fallback direct send qo'shildi (agar job ishlamasa)"
echo "   ✨ Queue worker'lar ishga tushirildi"
echo ""
echo "🧪 Test qiling:"
echo "   1. Bot'ga /start yuboring"
echo "   2. Til tanlash tugmalari ko'rinishi kerak"
echo ""
echo "📊 Loglarni kuzatish:"
echo "   # Laravel loglar"
echo "   tail -f storage/logs/laravel.log | grep -E 'Dispatching language selection|SendTelegramLanguageSelectionJob|Sending message with keyboard'"
echo ""
echo "   # Queue loglar"
echo "   tail -f storage/logs/queue-telegram.log"
echo ""
echo "   # Barcha loglar"
echo "   tail -f storage/logs/laravel.log"
echo ""
