#!/bin/bash

echo "🔧 Instagram rasm format muammosini tuzatish..."
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

# 2. yt-dlp versiyasini yangilash (agar kerak bo'lsa)
echo "2️⃣ yt-dlp versiyasini tekshirish..."
YT_DLP_PATH=$(php artisan tinker --execute="echo config('telegram.yt_dlp_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1)
if [ -f "$YT_DLP_PATH" ] && [ -x "$YT_DLP_PATH" ]; then
    VERSION=$("$YT_DLP_PATH" --version 2>/dev/null)
    echo "   ✅ yt-dlp versiyasi: $VERSION"
    
    # Check if version is recent
    if echo "$VERSION" | grep -q "202[4-9]\|2025"; then
        echo "   ✅ Versiya yangi"
    else
        echo "   ⚠️  Versiya eski, yangilash tavsiya etiladi"
        echo "   💡 Yangilash uchun: $YT_DLP_PATH -U"
    fi
else
    echo "   ❌ yt-dlp topilmadi: $YT_DLP_PATH"
    exit 1
fi
echo ""

# 3. Instagram cookies tekshirish
echo "3️⃣ Instagram cookies tekshirish..."
COOKIES_PATH=$(php artisan tinker --execute="echo config('telegram.instagram_cookies_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1)
if [ -n "$COOKIES_PATH" ] && [ "$COOKIES_PATH" != "null" ] && [ -f "$COOKIES_PATH" ]; then
    echo "   ✅ Instagram cookies fayli mavjud: $COOKIES_PATH"
    
    # Check cookies file validity
    if grep -q "instagram.com" "$COOKIES_PATH" 2>/dev/null; then
        echo "   ✅ Cookies fayli to'g'ri ko'rinadi"
        
        # Check if cookies are recent (not too old)
        COOKIES_AGE=$(find "$COOKIES_PATH" -mtime +30 2>/dev/null)
        if [ -n "$COOKIES_AGE" ]; then
            echo "   ⚠️  Cookies fayli 30 kundan eski, yangilash tavsiya etiladi"
        fi
    else
        echo "   ⚠️  Cookies faylida instagram.com topilmadi"
        echo "   💡 Cookies faylini yangilang"
    fi
else
    echo "   ⚠️  Instagram cookies fayli topilmadi"
    echo "   💡 Instagram rasm yuklab olish uchun cookies fayli JUDA MUHIM!"
    echo "   📝 Browser'dan Instagram cookies'ni export qiling (Netscape format)"
    echo "   📝 Keyin .env faylida sozlang:"
    echo "      INSTAGRAM_COOKIES_PATH=/path/to/instagram_cookies.txt"
fi
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
echo "   ✨ Instagram rasm postlar uchun maxsus method qo'shildi"
echo "   ✨ Format selector yaxshilandi (rasm formatlarini birinchi qidiradi)"
echo "   ✨ 'No video formats' xatosi uchun retry qo'shildi"
echo "   ✨ Media info tekshirish qo'shildi (rasm yoki video aniqlash)"
echo ""
echo "📝 MUHIM: Instagram rasm yuklab olish uchun:"
echo "   1. ✅ Instagram cookies faylini qo'shing (JUDA MUHIM!)"
echo "   2. ✅ yt-dlp versiyasini yangilang (agar eski bo'lsa)"
echo ""
echo "📱 Test qiling:"
echo "   1. Botga Instagram rasm link yuboring"
echo "   2. Endi rasm yuklab olinishi kerak"
echo ""
echo "🔍 Agar muammo bo'lsa:"
echo "   tail -100 storage/logs/laravel.log | grep -i 'instagram\|image\|download\|error\|no video'"
echo ""
