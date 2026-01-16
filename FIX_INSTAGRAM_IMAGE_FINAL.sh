#!/bin/bash

echo "🔧 Instagram Rasm Yuklash - Final Yechim"
echo "========================================="
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
echo "🔧 Final Yechim:"
echo "   ✨ --list-formats bilan formatlarni ko'rish"
echo "   ✨ Format ID asosida yuklash"
echo "   ✨ --write-thumbnail metod (eng ishonchli)"
echo "   ✨ Format selector'siz urinish"
echo "   ✨ 'No video formats' xatosi uchun maxsus boshqaruv"
echo ""
echo "📝 Qanday ishlaydi:"
echo "   1. Formatlarni ko'rish (--list-formats)"
echo "   2. Agar image formatlar topilsa, format ID bilan yuklash"
echo "   3. Thumbnail metod (--write-thumbnail + --skip-download)"
echo "   4. Format selector'siz urinish"
echo "   5. Thumbnail write (--write-thumbnail)"
echo "   6. Explicit image format"
echo ""
echo "🧪 Test qiling:"
echo "   ./TEST_INSTAGRAM_URL.sh"
echo ""
echo "   Yoki botga Instagram rasm link yuboring"
echo ""
