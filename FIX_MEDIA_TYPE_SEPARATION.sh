#!/bin/bash

echo "🔧 Video va rasm ajratish muammosini tuzatish..."
echo ""

cd ~/www/download.e-qarz.uz

# 1. PHP syntax tekshirish
echo "1️⃣ PHP syntax tekshirish..."
php -l app/Services/YtDlpService.php
php -l app/Jobs/DownloadMediaJob.php
if [ $? -ne 0 ]; then
    echo "❌ PHP syntax xatosi bor!"
    exit 1
fi
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
echo "🔧 Tuzatilgan muammolar:"
echo "   ✨ Media type detection qo'shildi (rasm yoki video aniqlash)"
echo "   ✨ Video postlar uchun maxsus method (faqat video yuklab oladi)"
echo "   ✨ Rasm postlar uchun maxsus method (faqat rasm yuklab oladi)"
echo "   ✨ Yuklab olingan fayllar filtrlash (faqat kerakli format)"
echo "   ✨ Agar ikkalasi ham bo'lsa, asosiy typeni tanlash"
echo ""
echo "📝 Qanday ishlaydi:"
echo "   ✅ Video link → faqat video yuklab oladi"
echo "   ✅ Rasm link → faqat rasm yuklab oladi"
echo "   ✅ Agar ikkalasi ham bo'lsa → asosiy typeni tanlaydi"
echo ""
echo "📱 Test qiling:"
echo "   1. Botga Instagram video link yuboring → faqat video yuklab olinishi kerak"
echo "   2. Botga Instagram rasm link yuboring → faqat rasm yuklab olinishi kerak"
echo ""
echo "🔍 Debug uchun:"
echo "   tail -f storage/logs/laravel.log | grep -i 'media type\|videos_count\|images_count\|separated'"
echo ""
