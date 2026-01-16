#!/bin/bash

echo "⚡ Bot javoblarini tezlashtirish optimallashtirishlari..."
echo ""

cd /var/www/sardor/data/www/download.e-qarz.uz

# 1. PHP syntax tekshirish
echo "1️⃣ PHP syntax tekshirish..."
php -l app/Http/Controllers/TelegramWebhookController.php
php -l app/Jobs/DownloadMediaJob.php
php -l app/Services/TelegramService.php
echo ""

# 2. Config yangilash
echo "2️⃣ Config yangilash..."
php artisan config:clear
php artisan config:cache
echo "✅ Config yangilandi"
echo ""

# 3. Cache yangilash
echo "3️⃣ Cache tozalash..."
php artisan cache:clear
echo "✅ Cache tozalandi"
echo ""

# 4. Workerlarni qayta ishga tushirish
echo "4️⃣ Workerlarni qayta ishga tushirish..."
pkill -9 -f "artisan queue:work" 2>/dev/null
sleep 2

# Optimize: Run multiple workers for faster processing
nohup php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
DOWNLOAD_PID1=$!

# Optional: Add second download worker for parallel processing
# nohup php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads-2.log 2>&1 &
# DOWNLOAD_PID2=$!

nohup php artisan queue:work redis --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &
TELEGRAM_PID=$!

# Optional: Add second telegram worker for faster message sending
nohup php artisan queue:work redis --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram-2.log 2>&1 &
TELEGRAM_PID2=$!

sleep 3
echo "✅ Workerlarni ishga tushirdim"
echo "   Downloads: PID $DOWNLOAD_PID1"
echo "   Telegram: PID $TELEGRAM_PID, $TELEGRAM_PID2"
echo ""

# 5. Tekshirish
echo "5️⃣ Tekshirish..."
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
echo "⚡ Optimallashtirishlar:"
echo "   ✨ 'Downloading...' xabari endi darhol yuboriladi (webhook'da)"
echo "   ✨ Subscription check cache'lanadi (5 daqiqa)"
echo "   ✨ Telegram API timeout kamaytirildi (10s → 5s)"
echo "   ✨ Qo'shimcha telegram worker qo'shildi (parallel processing)"
echo ""
echo "📊 Kutilayotgan natijalar:"
echo "   ⚡ Foydalanuvchi javobini darhol ko'radi (< 1 soniya)"
echo "   ⚡ Xabarlar tezroq yuboriladi (parallel workers)"
echo "   ⚡ Subscription check tezroq (cache'dan)"
echo ""
echo "📱 Test qiling:"
echo "   1. Botga link yuboring"
echo "   2. 'Downloading...' xabari darhol ko'rinishi kerak"
echo "   3. Video/rasm tezroq yuborilishi kerak"
echo ""
