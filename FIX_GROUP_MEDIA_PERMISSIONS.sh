#!/bin/bash

echo "🔧 Guruhda Media Yuborish Muammosini Tuzatish"
echo "=============================================="
echo ""

cd ~/www/download.e-qarz.uz

# 1. PHP syntax tekshirish
echo "1️⃣ PHP syntax tekshirish..."
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

nohup php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
DOWNLOAD_PID=$!

nohup php artisan queue:work redis --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &
TELEGRAM_PID=$!

sleep 3
echo "✅ Workerlarni ishga tushirdim (PIDs: $DOWNLOAD_PID, $TELEGRAM_PID)"
echo ""

# 4. Tekshirish
echo "4️⃣ Workerlarni tekshirish..."
WORKERS=$(ps aux | grep "artisan queue:work redis" | grep -v grep | grep -v "datacollector" | wc -l)
if [ "$WORKERS" -ge 2 ]; then
    echo "✅ $WORKERS worker ishlayapti"
    ps aux | grep "artisan queue:work redis" | grep -v grep | grep -v "datacollector" | awk '{print "   PID:", $2, "Queue:", $NF}'
else
    echo "⚠️  Faqat $WORKERS worker ishlayapti"
fi
echo ""

echo "===================================="
echo "✅ Tugadi!"
echo ""
echo "🔧 Tuzatilgan muammolar:"
echo "   ✨ Telegram API xatolarini batafsil log qilish"
echo "   ✨ Permission xatolarini aniqlash"
echo "   ✨ Foydalanuvchiga tushunarli xabar ko'rsatish"
echo ""
echo "📝 Qanday ishlaydi:"
echo "   1. Agar bot guruhda media yubora olmasa, xato log qilinadi"
echo "   2. Foydalanuvchiga tushunarli xabar yuboriladi"
echo "   3. Loglarda batafsil ma'lumot ko'rinadi"
echo ""
echo "⚠️  MUHIM:"
echo "   Bot guruhda media yuborish uchun:"
echo "   - Bot admin bo'lishi KERAK yoki"
echo "   - Bot'ga 'Send Messages' permission berilishi KERAK"
echo ""
echo "🧪 Test qiling:"
echo "   1. Botni guruhga qo'shing"
echo "   2. Bot'ga admin qiling yoki 'Send Messages' permission bering"
echo "   3. Instagram link yuboring"
echo "   4. Video yuklab olinishi kerak"
echo ""
echo "📋 Loglarni tekshirish:"
echo "   tail -f storage/logs/laravel.log | grep -E 'sendVideo|sendPhoto|Telegram API error'"
echo ""
