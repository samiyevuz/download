#!/bin/bash

echo "🔧 Subscription Check Tugmasi Tuzatish"
echo "======================================"
echo ""

cd ~/www/download.e-qarz.uz

# 1. PHP syntax tekshirish
echo "1️⃣ PHP syntax tekshirish..."
php -l app/Http/Controllers/TelegramWebhookController.php
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

echo "===================================="
echo "✅ Tuzatildi!"
echo ""
echo "🔧 Tuzatilgan muammolar:"
echo "   ✨ Subscription check callback'da cache tozalanadi (fresh result)"
echo "   ✨ Callback query to'g'ridan-to'g'ri javob beradi (tez)"
echo "   ✨ Welcome message to'g'ridan-to'g'ri yuboriladi (tez)"
echo "   ✨ Batafsil logging qo'shildi"
echo ""
echo "🧪 Test qiling:"
echo "   1. /start yuboring"
echo "   2. Til tanlang"
echo "   3. Agar subscription message ko'rsa, '✅ Tekshirish' tugmasini bosing"
echo "   4. Tugma ishlashi kerak va welcome message ko'rinishi kerak"
echo ""
echo "📊 Loglarni kuzatish:"
echo "   tail -f storage/logs/laravel.log | grep -E 'check_subscription|Subscription check|callback query'"
echo ""
