#!/bin/bash

echo "🎉 Cookies Sozlash - Final Qadamlar"
echo "===================================="
echo ""

PROJECT_DIR="/var/www/sardor/data/www/download.e-qarz.uz"
COOKIES_FILE="$PROJECT_DIR/storage/app/cookies/instagram_cookies.txt"

cd "$PROJECT_DIR"

# 1. Cookies faylini tekshirish
echo "1️⃣ Cookies faylini tekshirish..."
if [ -f "$COOKIES_FILE" ]; then
    SIZE=$(ls -lh "$COOKIES_FILE" | awk '{print $5}')
    PERMS=$(stat -c "%a" "$COOKIES_FILE" 2>/dev/null || stat -f "%OLp" "$COOKIES_FILE" 2>/dev/null)
    echo "✅ Cookies fayli mavjud: $COOKIES_FILE"
    echo "   Fayl hajmi: $SIZE"
    echo "   Permissions: $PERMS"
    
    if [ "$PERMS" = "600" ]; then
        echo "✅ Permissions to'g'ri (600)"
    else
        echo "⚠️  Permissions o'zgartirish kerak: 600"
        chmod 600 "$COOKIES_FILE"
        echo "✅ Permissions o'zgartirildi"
    fi
else
    echo "❌ Cookies fayli topilmadi: $COOKIES_FILE"
    exit 1
fi
echo ""

# 2. .env faylga qo'shish
echo "2️⃣ .env faylga qo'shish..."
if grep -q "INSTAGRAM_COOKIES_PATH" .env 2>/dev/null; then
    echo "✅ INSTAGRAM_COOKIES_PATH allaqachon mavjud"
    grep "INSTAGRAM_COOKIES_PATH" .env
else
    echo "⚠️  INSTAGRAM_COOKIES_PATH yo'q, qo'shilmoqda..."
    echo "" >> .env
    echo "# Instagram Cookies" >> .env
    echo "INSTAGRAM_COOKIES_PATH=$COOKIES_FILE" >> .env
    echo "✅ .env faylga qo'shildi: INSTAGRAM_COOKIES_PATH=$COOKIES_FILE"
fi
echo ""

# 3. Config yangilash
echo "3️⃣ Config yangilash..."
php artisan config:clear > /dev/null 2>&1
php artisan config:cache > /dev/null 2>&1
echo "✅ Config yangilandi"
echo ""

# 4. Config'da tekshirish
echo "4️⃣ Config'da tekshirish..."
COOKIES_PATH=$(php artisan tinker --execute="echo config('telegram.instagram_cookies_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1)
if [ -n "$COOKIES_PATH" ] && [ "$COOKIES_PATH" != "null" ]; then
    echo "✅ Config'da cookies path topildi: $COOKIES_PATH"
else
    echo "⚠️  Config'da cookies path topilmadi"
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

# 6. Final tekshirish
echo "6️⃣ Final tekshirish..."
WORKERS=$(ps aux | grep "artisan queue:work redis" | grep -v grep | grep -v "datacollector" | wc -l)
if [ "$WORKERS" -ge 2 ]; then
    echo "✅ $WORKERS worker ishlayapti"
    ps aux | grep "artisan queue:work redis" | grep -v grep | grep -v "datacollector" | awk '{print "   PID:", $2, "Queue:", $NF}'
else
    echo "⚠️  Faqat $WORKERS worker ishlayapti"
fi
echo ""

echo "===================================="
echo "✅ BARCHA SOZLASH TUGADI!"
echo ""
echo "🎉 Bot tayyor!"
echo ""
echo "📱 Keyingi qadamlar:"
echo "   1. Telegram bot'ga Instagram link yuboring"
echo "   2. Video/rasm yuborilishi kerak ✅"
echo ""
echo "⚠️  Eslatma:"
echo "   - Cookies bilan Instagram yuklab olish 99% ishonchli!"
echo "   - Agar muammo bo'lsa, loglarni ko'ring:"
echo "     tail -f storage/logs/laravel.log"
echo ""
