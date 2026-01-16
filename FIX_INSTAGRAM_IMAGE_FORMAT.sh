#!/bin/bash

echo "🔧 Instagram Rasm Format Muammosini Tuzatish"
echo "============================================="
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

# 2. yt-dlp yangilash
echo "2️⃣ yt-dlp yangilash..."
YT_DLP_PATH=$(php artisan tinker --execute="echo config('telegram.yt_dlp_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1)

if [ -f "$YT_DLP_PATH" ] && [ -x "$YT_DLP_PATH" ]; then
    echo "   Hozirgi versiya: $($YT_DLP_PATH --version 2>/dev/null || echo 'unknown')"
    echo "   Yangilanmoqda..."
    "$YT_DLP_PATH" -U 2>&1 | head -10
    echo "   ✅ Yangilandi"
else
    echo "   ⚠️  yt-dlp topilmadi"
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
echo "   ✨ Format selector o'zgartirildi: 'best' format birinchi o'ringa qo'yildi"
echo "   ✨ 'No video formats' xatosi uchun maxsus boshqaruv qo'shildi"
echo "   ✨ Format selector'siz urinish qo'shildi (agar barcha formatlar muvaffaqiyatsiz bo'lsa)"
echo "   ✨ Ko'proq format selector'lar qo'shildi (best, worst, specific formats)"
echo "   ✨ yt-dlp yangilandi"
echo ""
echo "📝 Qanday ishlaydi:"
echo "   1. 'best' format bilan urinish (eng ishonchli)"
echo "   2. Agar 'No video formats' xatosi bo'lsa, 'worst' format bilan urinish"
echo "   3. Agar muvaffaqiyatsiz bo'lsa, specific format selector'lar bilan urinish"
echo "   4. Agar hali ham muvaffaqiyatsiz bo'lsa, format selector'siz urinish"
echo ""
echo "🧪 Test qiling:"
echo "   ./TEST_INSTAGRAM_IMAGE.sh"
echo ""
echo "   Yoki botga Instagram rasm link yuboring"
echo ""
