#!/bin/bash

echo "🔧 Instagram Rate Limiting Muammosini Tuzatish"
echo "================================================"
echo ""

cd ~/www/download.e-qarz.uz

# 1. PHP syntax tekshirish
echo "1️⃣ PHP syntax tekshirish..."
php -l app/Jobs/DownloadMediaJob.php
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
echo "   ✨ Instagram uchun retry delay qo'shildi (rate limiting oldini olish)"
echo "   ✨ Instagram uchun backoff vaqti uzaytirildi (10s, 30s)"
echo "   ✨ Xatoliklarni aniqlash yaxshilandi (rate_limit, authentication_required, extractor_error)"
echo "   ✨ Logging yanada batafsil qilindi"
echo ""
echo "📝 Qanday ishlaydi:"
echo "   1. Birinchi urinish: darhol ishlaydi"
echo "   2. Ikkinchi urinish (retry): 10 soniya kutadi"
echo "   3. Uchinchi urinish (retry): 30 soniya kutadi"
echo "   4. Instagram uchun qo'shimcha delay: 5-15 soniya (attempt asosida)"
echo ""
echo "🧪 Test qiling:"
echo "   1. Botga Instagram link yuboring"
echo "   2. Agar xato bo'lsa, avtomatik retry qiladi (delay bilan)"
echo "   3. Loglarni kuzatib boring:"
echo "      tail -f storage/logs/queue-downloads.log"
echo "      tail -f storage/logs/laravel.log"
echo ""
