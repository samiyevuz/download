#!/bin/bash

echo "🔧 Cookie Path Muammosini Tuzatish (Absolute Path)"
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

# 2. Cookie faylini tekshirish
echo "2️⃣ Cookie faylini tekshirish..."
COOKIE_FILE="storage/app/cookies/instagram_cookies.txt"
if [ -f "$COOKIE_FILE" ]; then
    ABSOLUTE_PATH=$(realpath "$COOKIE_FILE")
    echo "✅ Cookie fayli mavjud"
    echo "   Nisbiy path: $COOKIE_FILE"
    echo "   Absolute path: $ABSOLUTE_PATH"
    echo "   Fayl hajmi: $(stat -f%z "$COOKIE_FILE" 2>/dev/null || stat -c%s "$COOKIE_FILE" 2>/dev/null) bytes"
    
    if grep -q "sessionid" "$COOKIE_FILE" 2>/dev/null; then
        echo "   ✅ sessionid topildi"
    else
        echo "   ❌ sessionid topilmadi!"
    fi
else
    echo "❌ Cookie fayli topilmadi: $COOKIE_FILE"
    exit 1
fi
echo ""

# 3. .env faylini tekshirish va yangilash
echo "3️⃣ .env faylini tekshirish..."
if grep -q "INSTAGRAM_COOKIES_PATHS" .env 2>/dev/null; then
    CURRENT_PATHS=$(grep "INSTAGRAM_COOKIES_PATHS" .env | cut -d'=' -f2)
    echo "   Hozirgi: $CURRENT_PATHS"
    
    # Absolute path'ga o'girish
    ABSOLUTE_COOKIE_PATHS=$(echo "$CURRENT_PATHS" | tr ',' '\n' | while read path; do
        path=$(echo "$path" | tr -d ' ')
        if [ ! -z "$path" ]; then
            if [[ "$path" != /* ]]; then
                # Nisbiy path - absolute'ga o'girish
                realpath "$path" 2>/dev/null || echo "$(pwd)/$path"
            else
                # Absolute path
                realpath "$path" 2>/dev/null || echo "$path"
            fi
        fi
    done | tr '\n' ',' | sed 's/,$//')
    
    if [ ! -z "$ABSOLUTE_COOKIE_PATHS" ]; then
        sed -i "s|INSTAGRAM_COOKIES_PATHS=.*|INSTAGRAM_COOKIES_PATHS=$ABSOLUTE_COOKIE_PATHS|" .env
        echo "   ✅ Yangilandi: $ABSOLUTE_COOKIE_PATHS"
    fi
else
    # Agar yo'q bo'lsa, qo'shish
    ABSOLUTE_PATH=$(realpath "$COOKIE_FILE")
    echo "" >> .env
    echo "# Instagram Cookies (absolute paths)" >> .env
    echo "INSTAGRAM_COOKIES_PATHS=$ABSOLUTE_PATH" >> .env
    echo "   ✅ Qo'shildi: $ABSOLUTE_PATH"
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
echo "🔧 Tuzatilgan muammolar:"
echo "   ✨ Cookie path'lar absolute path'ga o'girildi"
echo "   ✨ Path'lar to'g'ri normalize qilindi (realpath)"
echo "   ✨ Cookie fayl mavjudligi va o'qilishi tekshiriladi"
echo "   ✨ Takrorlanish oldini olindi"
echo ""
echo "📝 Qanday ishlaydi:"
echo "   1. Cookie path'lar absolute path'ga o'giriladi"
echo "   2. Path'lar normalize qilinadi (realpath)"
echo "   3. Cookie fayl mavjudligi va o'qilishi tekshiriladi"
echo "   4. yt-dlp absolute path bilan ishlaydi"
echo ""
echo "🧪 Test qiling:"
echo "   Botga Instagram rasm linki yuboring"
echo "   Loglarni kuzatib turing:"
echo "   tail -f storage/logs/laravel.log | grep -E 'cookie|Instagram image|cookie_path'"
echo ""
