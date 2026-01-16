#!/bin/bash

echo "🔧 Instagram rasm yuklab olish muammosini tuzatish..."
echo ""

cd ~/www/download.e-qarz.uz

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

# 3. yt-dlp versiyasini tekshirish
echo "3️⃣ yt-dlp versiyasini tekshirish..."
YT_DLP_PATH=$(php artisan tinker --execute="echo config('telegram.yt_dlp_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1)
if [ -f "$YT_DLP_PATH" ]; then
    VERSION=$("$YT_DLP_PATH" --version 2>/dev/null)
    echo "   ✅ yt-dlp versiyasi: $VERSION"
    
    # Check if version is recent (should be 2024+)
    if echo "$VERSION" | grep -q "202[4-9]"; then
        echo "   ✅ Versiya yangi"
    else
        echo "   ⚠️  Versiya eski, yangilash tavsiya etiladi"
    fi
else
    echo "   ❌ yt-dlp topilmadi: $YT_DLP_PATH"
fi
echo ""

# 4. Instagram cookies tekshirish
echo "4️⃣ Instagram cookies tekshirish..."
COOKIES_PATH=$(php artisan tinker --execute="echo config('telegram.instagram_cookies_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1)
if [ -n "$COOKIES_PATH" ] && [ "$COOKIES_PATH" != "null" ] && [ -f "$COOKIES_PATH" ]; then
    echo "   ✅ Instagram cookies fayli mavjud: $COOKIES_PATH"
    echo "   📝 Fayl hajmi: $(du -h "$COOKIES_PATH" | cut -f1)"
else
    echo "   ⚠️  Instagram cookies fayli topilmadi"
    echo "   💡 Instagram rasm yuklab olish uchun cookies fayli foydali"
    echo "   📝 Browser'dan Instagram cookies'ni export qiling va .env faylida sozlang:"
    echo "      INSTAGRAM_COOKIES_PATH=/path/to/instagram_cookies.txt"
fi
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
echo "6️⃣ Tekshirish..."
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
echo "   ✨ Instagram rasm yuklab olish uchun alternative method qo'shildi"
echo "   ✨ Format selector yaxshilandi (jpg, png, jpeg)"
echo "   ✨ Xatolarni qayta ishlash yaxshilandi (batafsil log'lar)"
echo "   ✨ Instagram uchun maxsus xabar qo'shildi"
echo ""
echo "📝 Tavsiyalar:"
echo "   - Instagram cookies faylini qo'shish rasm yuklab olishni yaxshilaydi"
echo "   - yt-dlp versiyasini yangilash (agar eski bo'lsa)"
echo ""
echo "📱 Test qiling:"
echo "   1. Botga Instagram rasm link yuboring"
echo "   2. Agar muammo bo'lsa, loglarni ko'ring:"
echo "      tail -f storage/logs/laravel.log | grep -i 'instagram\|image\|download'"
echo ""
