#!/bin/bash

echo "🔧 Instagram Cookie va Extractor Args Tuzatish"
echo "=============================================="
echo ""

cd ~/www/download.e-qarz.uz

# 1. PHP syntax tekshirish
echo "1️⃣ PHP syntax tekshirish..."
php -l app/Services/YtDlpService.php
if [ $? -ne 0 ]; then
    echo "❌ PHP syntax xatosi bor!"
    exit 1
fi
echo "✅ PHP syntax to'g'ri"
echo ""

# 2. yt-dlp'ni yangilash
echo "2️⃣ yt-dlp'ni yangilash..."
YTDLP_PATH="/var/www/sardor/data/bin/yt-dlp"
if [ -f "$YTDLP_PATH" ]; then
    "$YTDLP_PATH" -U 2>&1 | head -5
    echo "✅ yt-dlp yangilandi"
else
    echo "⚠️  yt-dlp topilmadi, o'tkazib yuborildi"
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
echo "5️⃣ Workerlarni tekshirish..."
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
echo "   ✨ --extractor-args 'instagram:skip_auth=False' qo'shildi"
echo "   ✨ Cookie path absolute path sifatida ishlatiladi"
echo "   ✨ yt-dlp yangilandi"
echo ""
echo "🧪 Test qiling:"
echo "   chmod +x TEST_COOKIE_WITH_YTDLP.sh"
echo "   ./TEST_COOKIE_WITH_YTDLP.sh"
echo ""
echo "   Yoki botga Instagram rasm linki yuboring"
echo ""
