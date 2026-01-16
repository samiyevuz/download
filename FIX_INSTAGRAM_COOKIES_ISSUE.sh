#!/bin/bash

echo "🔧 Instagram cookies muammosini tuzatish..."
echo ""

cd ~/www/download.e-qarz.uz

# 1. Cookies faylini tekshirish
echo "1️⃣ Instagram cookies faylini tekshirish..."
chmod +x CHECK_INSTAGRAM_COOKIES.sh
./CHECK_INSTAGRAM_COOKIES.sh
echo ""

# 2. PHP syntax tekshirish
echo "2️⃣ PHP syntax tekshirish..."
php -l app/Services/YtDlpService.php
php -l app/Jobs/DownloadMediaJob.php
if [ $? -ne 0 ]; then
    echo "❌ PHP syntax xatosi bor!"
    exit 1
fi
echo ""

# 3. Config yangilash
echo "3️⃣ Config yangilash..."
php artisan config:clear
php artisan config:cache
echo "✅ Config yangilandi"
echo ""

# 4. Workerlarni qayta ishga tushirish
echo "4️⃣ Workerlarni qayta ishga tushirish..."
pkill -9 -f "artisan queue:work" 2>/dev/null
sleep 2

nohup php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
DOWNLOAD_PID=$!

nohup php artisan queue:work redis --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &
TELEGRAM_PID=$!

sleep 3
echo "✅ Workerlarni ishga tushirdim (PIDs: $DOWNLOAD_PID, $TELEGRAM_PID)"
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
echo "🔧 Tuzatilgan muammolar:"
echo "   ✨ Cookies fayli tekshirish yaxshilandi"
echo "   ✨ Video va rasm ajratish yaxshilandi (URL asosida)"
echo "   ✨ Reel uchun video, Post uchun rasm afzal qilinadi"
echo "   ✨ Xatolarni qayta ishlash yaxshilandi"
echo ""
echo "⚠️  MUHIM: Instagram cookies muammosi"
echo "   Loglardan ko'rinib turibdiki, cookies ishlamayapti"
echo "   Quyidagilarni qiling:"
echo ""
echo "   1. Browser'dan yangi Instagram cookies export qiling"
echo "      - Chrome: EditThisCookie extension"
echo "      - Firefox: Cookie-Editor extension"
echo "      - Format: Netscape HTTP Cookie File"
echo ""
echo "   2. Cookies faylini yangilang:"
echo "      INSTAGRAM_COOKIES_PATH=/path/to/instagram_cookies.txt"
echo ""
echo "   3. Config yangilang:"
echo "      php artisan config:clear && php artisan config:cache"
echo ""
echo "   4. Test qiling:"
echo "      ./CHECK_INSTAGRAM_COOKIES.sh"
echo ""
echo "📱 Test qiling:"
echo "   1. Yangi cookies export qiling"
echo "   2. .env faylida path'ni yangilang"
echo "   3. Config yangilang"
echo "   4. Botga Instagram link yuboring"
echo ""
