#!/bin/bash

echo "🔧 Video Yuklash Muammosini Tuzatish"
echo "===================================="
echo ""

cd ~/www/download.e-qarz.uz

# 1. PHP syntax tekshirish
echo "1️⃣ PHP syntax tekshirish..."
php -l app/Jobs/DownloadMediaJob.php
php -l app/Services/YtDlpService.php
php -l app/Services/TelegramService.php

if [ $? -ne 0 ]; then
    echo "❌ PHP syntax xatosi bor!"
    exit 1
fi
echo "✅ PHP syntax to'g'ri"
echo ""

# 2. Cookie faylini tekshirish
echo "2️⃣ Cookie Faylini Tekshirish..."
COOKIE_FILE=$(php artisan tinker --execute="echo config('telegram.instagram_cookies_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')

if [ -n "$COOKIE_FILE" ] && [ "$COOKIE_FILE" != "null" ]; then
    if [ -f "$COOKIE_FILE" ]; then
        COOKIE_SIZE=$(stat -f%z "$COOKIE_FILE" 2>/dev/null || stat -c%s "$COOKIE_FILE" 2>/dev/null || echo "0")
        echo "   ✅ Cookie fayli mavjud: $COOKIE_FILE (hajm: ${COOKIE_SIZE}B)"
        
        if grep -q "sessionid" "$COOKIE_FILE" 2>/dev/null; then
            echo "   ✅ sessionid cookie topildi"
        else
            echo "   ⚠️  sessionid cookie topilmadi"
        fi
    else
        echo "   ⚠️  Cookie fayli topilmadi: $COOKIE_FILE"
    fi
else
    echo "   ⚠️  Cookie path sozlanmagan"
fi
echo ""

# 3. Config yangilash
echo "3️⃣ Config yangilash..."
php artisan config:clear
php artisan config:cache
echo "✅ Config yangilandi"
echo ""

# 4. Queue worker'lar
echo "4️⃣ Queue Worker'lar..."
WORKERS=$(ps aux | grep "artisan queue:work" | grep -v grep | grep -v "datacollector" | wc -l)

if [ "$WORKERS" -lt 1 ]; then
    echo "   ⚠️  Worker ishlamayapti, ishga tushiryapman..."
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
fi
echo ""

echo "===================================="
echo "✅ Tuzatish tugadi!"
echo ""
echo "🔧 Tuzatilgan muammolar:"
echo "   ✨ downloadInstagramVideoWithCookies'ga absolute path qo'shildi"
echo "   ✨ Video fayllar topilmaganda batafsil logging qo'shildi"
echo "   ✨ sendVideo metodiga batafsil logging qo'shildi"
echo "   ✨ Downloaded files separation'da warning qo'shildi"
echo ""
echo "🧪 Test qiling:"
echo "   1. Bot'ga Instagram video linki yuboring (masalan: https://www.instagram.com/reel/...)"
echo "   2. Video yuklanadi va yuboriladi"
echo ""
echo "📊 Loglarni kuzatish:"
echo "   tail -f storage/logs/laravel.log | grep -E 'DownloadMediaJob|sendVideo|Sending videos|Instagram video|video_files|No video files'"
echo ""
