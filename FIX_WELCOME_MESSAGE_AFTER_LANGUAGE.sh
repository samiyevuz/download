#!/bin/bash

echo "🔧 Welcome Message Tuzatish (Til Tanlagandan Keyin)"
echo "==================================================="
echo ""

cd ~/www/download.e-qarz.uz

# 1. PHP syntax tekshirish
echo "1️⃣ PHP syntax tekshirish..."
php -l app/Http/Controllers/TelegramWebhookController.php
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
echo "   ✨ Til tanlagandan keyin welcome message yuboriladi"
echo "   ✨ Subscription check to'g'ri ishlaydi"
echo "   ✨ Agar a'zo bo'lmasa, subscription message yuboriladi"
echo "   ✨ Agar a'zo bo'lsa yoki guruhda bo'lsa, welcome message yuboriladi"
echo "   ✨ Fallback: agar job ishlamasa, to'g'ridan-to'g'ri yuboriladi"
echo ""
echo "🧪 Test qiling:"
echo "   1. /start yuboring"
echo "   2. Til tanlang (masalan: 🇺🇿 Oʻzbek tili)"
echo "   3. Welcome message ko'rinishi kerak: 'Welcome.\nSend an Instagram or TikTok link.'"
echo "   4. Keyin Instagram yoki TikTok linkini yuboring"
echo ""
echo "📊 Loglarni kuzatish:"
echo "   tail -f storage/logs/laravel.log | grep -E 'Language selected|Welcome message|subscription check'"
echo ""
