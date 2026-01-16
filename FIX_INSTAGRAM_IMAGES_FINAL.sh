#!/bin/bash

echo "🔧 Instagram rasm yuklab olish - Final tuzatish..."
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

# 2. yt-dlp test qilish
echo "2️⃣ yt-dlp test qilish..."
YT_DLP_PATH=$(php artisan tinker --execute="echo config('telegram.yt_dlp_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1)
if [ -f "$YT_DLP_PATH" ] && [ -x "$YT_DLP_PATH" ]; then
    VERSION=$("$YT_DLP_PATH" --version 2>/dev/null)
    echo "   ✅ yt-dlp versiyasi: $VERSION"
    
    # Test Instagram extractor
    echo "   🔍 Instagram extractor test..."
    "$YT_DLP_PATH" --list-extractors 2>/dev/null | grep -q instagram
    if [ $? -eq 0 ]; then
        echo "   ✅ Instagram extractor mavjud"
    else
        echo "   ⚠️  Instagram extractor topilmadi (lekin ishlashi mumkin)"
    fi
else
    echo "   ❌ yt-dlp topilmadi yoki executable emas: $YT_DLP_PATH"
    exit 1
fi
echo ""

# 3. Config yangilash
echo "3️⃣ Config yangilash..."
php artisan config:clear
php artisan config:cache
echo "✅ Config yangilandi"
echo ""

# 4. Instagram cookies tekshirish
echo "4️⃣ Instagram cookies tekshirish..."
COOKIES_PATH=$(php artisan tinker --execute="echo config('telegram.instagram_cookies_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1)
if [ -n "$COOKIES_PATH" ] && [ "$COOKIES_PATH" != "null" ] && [ -f "$COOKIES_PATH" ]; then
    echo "   ✅ Instagram cookies fayli mavjud: $COOKIES_PATH"
    COOKIES_SIZE=$(du -h "$COOKIES_PATH" | cut -f1)
    echo "   📝 Fayl hajmi: $COOKIES_SIZE"
    
    # Check if cookies file is valid (should contain instagram.com)
    if grep -q "instagram.com" "$COOKIES_PATH" 2>/dev/null; then
        echo "   ✅ Cookies fayli to'g'ri ko'rinadi"
    else
        echo "   ⚠️  Cookies faylida instagram.com topilmadi"
    fi
else
    echo "   ⚠️  Instagram cookies fayli topilmadi"
    echo "   💡 Instagram rasm yuklab olish uchun cookies fayli juda foydali"
    echo "   📝 Browser'dan Instagram cookies'ni export qiling (Netscape format)"
    echo "   📝 Keyin .env faylida sozlang:"
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
echo "🔧 Qo'shilgan yaxshilanishlar:"
echo "   ✨ Multiple user agent support (iPhone, Chrome, Safari)"
echo "   ✨ Alternative download method (3 ta fallback)"
echo "   ✨ Yaxshiroq format selector (jpg, jpeg, png, webp)"
echo "   ✨ Batafsil xato log'lari (Instagram specific errors)"
echo "   ✨ MIME type tekshirish yaxshilandi"
echo ""
echo "📝 MUHIM: Instagram rasm yuklab olish uchun:"
echo "   1. Instagram cookies faylini qo'shing (juda foydali)"
echo "   2. yt-dlp versiyasini yangilang (agar eski bo'lsa)"
echo ""
echo "📱 Test qiling:"
echo "   1. Botga Instagram rasm link yuboring"
echo "   2. Agar muammo bo'lsa, batafsil loglarni ko'ring:"
echo "      tail -100 storage/logs/laravel.log | grep -i 'instagram\|image\|download\|error'"
echo ""
echo "🔍 Debug uchun:"
echo "   # yt-dlp'ni to'g'ridan-to'g'ri test qilish:"
echo "   $YT_DLP_PATH --list-formats 'https://www.instagram.com/p/DThZG8ojDRy/' 2>&1 | head -30"
echo ""
