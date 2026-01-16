#!/bin/bash

echo "🔧 YouTube qo'llab-quvvatlash qo'shish..."
echo ""

cd /var/www/sardor/data/www/download.e-qarz.uz

# 1. PHP syntax tekshirish
echo "1️⃣ PHP syntax tekshirish..."
php -l app/Validators/UrlValidator.php
php -l app/Jobs/DownloadMediaJob.php
php -l app/Http/Controllers/TelegramWebhookController.php
php -l app/Jobs/SendTelegramWelcomeMessageJob.php
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
echo "4️⃣ Tekshirish..."
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
echo "✨ Qo'shilgan funksiyalar:"
echo "   ✅ YouTube qo'llab-quvvatlash (youtube.com, youtu.be)"
echo "   ✅ Katta videolar uchun maxsus xabar (50MB+ videolar)"
echo ""
echo "⚠️  MUHIM: Telegram API limiti"
echo "   📹 Telegram maksimal 50MB videolarni qabul qiladi"
echo "   📹 Agar video 50MB dan katta bo'lsa, foydalanuvchiga xabar yuboriladi"
echo ""
echo "📱 Test qiling:"
echo "   1. Botga YouTube link yuboring (masalan: https://youtu.be/...)"
echo "   2. Video yuklab olinadi"
echo "   3. Agar video 50MB dan katta bo'lsa, maxsus xabar ko'rinadi"
echo ""
echo "💡 Eslatma:"
echo "   - yt-dlp YouTube videolarni yuklab oladi"
echo "   - Kichik videolar (<50MB) to'g'ridan-to'g'ri yuboriladi"
echo "   - Katta videolar (>50MB) uchun xabar yuboriladi"
echo ""
