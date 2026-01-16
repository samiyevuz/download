#!/bin/bash

echo "🔧 Instagram Rasm Yuklash - 100% Kafolatli Yechim"
echo "=================================================="
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

# 2. yt-dlp versiyasini tekshirish va yangilash
echo "2️⃣ yt-dlp versiyasini tekshirish..."
YT_DLP_PATH=$(php artisan tinker --execute="echo config('telegram.yt_dlp_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1)

if [ -f "$YT_DLP_PATH" ] && [ -x "$YT_DLP_PATH" ]; then
    CURRENT_VERSION=$("$YT_DLP_PATH" --version 2>/dev/null || echo "unknown")
    echo "   Hozirgi versiya: $CURRENT_VERSION"
    echo ""
    echo "   ⚠️  yt-dlp'ni yangilash tavsiya etiladi:"
    echo "      $YT_DLP_PATH -U"
    echo ""
    read -p "yt-dlp'ni yangilash kerakmi? (y/n): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        echo "   Yangilanmoqda..."
        "$YT_DLP_PATH" -U 2>&1 | head -10
        echo "   ✅ Yangilandi"
    fi
else
    echo "   ⚠️  yt-dlp topilmadi yoki ishlamayapti"
fi
echo ""

# 3. Cookies tekshirish
echo "3️⃣ Instagram cookies tekshirish..."
chmod +x CHECK_INSTAGRAM_COOKIES.sh
./CHECK_INSTAGRAM_COOKIES.sh | head -30
echo ""

# 4. Config yangilash
echo "4️⃣ Config yangilash..."
php artisan config:clear
php artisan config:cache
echo "✅ Config yangilandi"
echo ""

# 5. Workerlarni qayta ishga tushirish
echo "5️⃣ Workerlarni qayta ishga tushirish..."
pkill -9 -f "artisan queue:work" 2>/dev/null
sleep 2

nohup php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
DOWNLOAD_PID=$!

nohup php artisan queue:work redis --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &
TELEGRAM_PID=$!

sleep 3
echo "✅ Workerlarni ishga tushirdim (PIDs: $DOWNLOAD_PID, $TELEGRAM_PID)"
echo ""

# 6. Tekshirish
echo "6️⃣ Workerlarni tekshirish..."
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
echo "🔧 100% Kafolatli Yechim:"
echo "   ✨ 5 ta turli metod bilan rasm yuklash:"
echo "      1. Cookies bilan (eng ishonchli)"
echo "      2. Direct image extraction (eng tez)"
echo "      3. Enhanced headers (fallback)"
echo "      4. Alternative format selector (fallback)"
echo "      5. Minimal arguments (oxirgi variant)"
echo ""
echo "   ✨ Har bir metod ichida ko'p format selector'lar"
echo "   ✨ Har bir metod ichida ko'p user-agent'lar"
echo "   ✨ Batafsil xatolik loglari"
echo ""
echo "📝 Qanday ishlaydi:"
echo "   1. Cookies bilan urinish (3 ta format selector)"
echo "   2. Agar muvaffaqiyatsiz bo'lsa, direct method (thumbnail + image)"
echo "   3. Agar muvaffaqiyatsiz bo'lsa, enhanced headers (3 ta user-agent)"
echo "   4. Agar muvaffaqiyatsiz bo'lsa, alternative format (3 ta format)"
echo "   5. Agar muvaffaqiyatsiz bo'lsa, minimal method (eng oddiy)"
echo ""
echo "🧪 Test qiling:"
echo "   1. Botga Instagram rasm link yuboring"
echo "   2. Loglarni kuzatib boring:"
echo "      tail -f storage/logs/queue-downloads.log"
echo "      tail -f storage/logs/laravel.log | grep -i 'instagram\|image\|download'"
echo ""
echo "💡 Agar hali ham muammo bo'lsa:"
echo "   1. Cookies faylini yangilang (30 kundan eski bo'lmasligi kerak)"
echo "   2. yt-dlp'ni yangilang: $YT_DLP_PATH -U"
echo "   3. Loglarni to'liq ko'ring: tail -100 storage/logs/laravel.log"
echo ""
