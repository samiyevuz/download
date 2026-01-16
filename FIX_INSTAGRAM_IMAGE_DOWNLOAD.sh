#!/bin/bash

echo "🔧 Instagram Rasm Yuklash Muammosini Tuzatish"
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
echo "   ✨ URL pattern asosida rasm/video aniqlash qo'shildi (/p/ = rasm, /reel/ = video)"
echo "   ✨ Rasm yuklash uchun ko'proq fallback metodlar qo'shildi"
echo "   ✨ Bir nechta user-agent'lar bilan urinish qo'shildi"
echo "   ✨ Turli format selector'lar bilan urinish qo'shildi"
echo "   ✨ Agar media info olish muvaffaqiyatsiz bo'lsa, avtomatik rasm yuklashga harakat qiladi"
echo ""
echo "📝 Qanday ishlaydi:"
echo "   1. Media info olishga harakat qiladi"
echo "   2. Agar muvaffaqiyatsiz bo'lsa, URL pattern'dan aniqlaydi (/p/ = rasm)"
echo "   3. Rasm yuklash uchun:"
echo "      - Cookies bilan urinish"
echo "      - Cookies'siz, bir nechta user-agent'lar bilan urinish"
echo "      - Turli format selector'lar bilan urinish"
echo ""
echo "🧪 Test qiling:"
echo "   1. Botga Instagram rasm link yuboring (masalan: /p/...)"
echo "   2. Loglarni kuzatib boring:"
echo "      tail -f storage/logs/queue-downloads.log"
echo "      tail -f storage/logs/laravel.log"
echo ""
