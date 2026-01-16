#!/bin/bash

echo "🔧 Bot To'liq Flow Tuzatish"
echo "============================="
echo ""

cd ~/www/download.e-qarz.uz

# 1. PHP syntax tekshirish
echo "1️⃣ PHP syntax tekshirish..."
php -l app/Http/Controllers/TelegramWebhookController.php
php -l app/Jobs/DownloadMediaJob.php
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
echo "✅ Bot to'liq tuzatildi!"
echo ""
echo "📋 Bot Flow:"
echo "   1. /start → Til tanlash yuboriladi"
echo "   2. Til tanlang → Welcome message yuboriladi"
echo "   3. Link yuborish → Media yuklanadi va yuboriladi"
echo ""
echo "🔧 Tuzatilgan muammolar:"
echo "   ✨ Til tanlagandan keyin welcome message yuboriladi"
echo "   ✨ Subscription check to'g'ri ishlaydi (private chat uchun)"
echo "   ✨ Guruhlarda subscription check o'tkazib yuboriladi"
echo "   ✨ Link yuborilganda media yuklanadi va yuboriladi"
echo "   ✨ Barcha xabarlar tanlangan tilda"
echo ""
echo "🧪 Test qiling:"
echo "   1. /start yuboring"
echo "   2. Til tanlang (masalan: 🇺🇿 Oʻzbek tili)"
echo "   3. Welcome message ko'rinishi kerak"
echo "   4. Instagram yoki TikTok linkini yuboring"
echo "   5. Media yuklanadi va yuboriladi"
echo ""
echo "📊 Loglarni kuzatish:"
echo "   tail -f storage/logs/laravel.log | grep -E 'Language selected|Welcome message|subscription check|Download job dispatched'"
echo ""
