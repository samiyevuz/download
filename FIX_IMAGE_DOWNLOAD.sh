#!/bin/bash

echo "🔧 Rasm yuklab olish muammosini tuzatish..."
echo ""

cd /var/www/sardor/data/www/download.e-qarz.uz

# 1. PHP syntax tekshirish
echo "1️⃣ PHP syntax tekshirish..."
php -l app/Services/YtDlpService.php
php -l app/Jobs/DownloadMediaJob.php
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
echo "   ✨ Rasm fayllarini topish yaxshilandi (MIME type tekshirish)"
echo "   ✨ Instagram format yaxshilandi (best/bestvideo+bestaudio/best)"
echo "   ✨ Xatolarni qayta ishlash yaxshilandi (fallback individual photos)"
echo "   ✨ Debug log'lar yaxshilandi (directory contents)"
echo ""
echo "📝 Qo'shimcha tekshirishlar:"
echo "   - Instagram cookies fayli mavjudligini tekshiring:"
echo "     ls -la storage/instagram_cookies.txt (yoki .env dagi path)"
echo "   - yt-dlp versiyasini tekshiring:"
echo "     yt-dlp --version"
echo ""
echo "📱 Test qiling:"
echo "   1. Botga Instagram rasm link yuboring"
echo "   2. Rasm yuklab olinishi va yuborilishi kerak"
echo "   3. Agar muammo bo'lsa, loglarni ko'ring:"
echo "      tail -f storage/logs/laravel.log | grep -i 'image\|download\|instagram'"
echo ""
