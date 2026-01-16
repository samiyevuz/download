#!/bin/bash

echo "🔄 Config Yangilash va Workerlarni Qayta Ishga Tushirish"
echo "========================================================"
echo ""

cd /var/www/sardor/data/www/download.e-qarz.uz

# 1. Config tozalash va yangilash
echo "1️⃣ Config tozalash va yangilash..."
php artisan config:clear
php artisan config:cache
echo "✅ Config yangilandi"
echo ""

# 2. Config'da cookies path tekshirish
echo "2️⃣ Config'da cookies path tekshirish..."
COOKIES_PATH=$(php artisan tinker --execute="echo config('telegram.instagram_cookies_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1)
echo "   Cookies path: $COOKIES_PATH"

if [ -n "$COOKIES_PATH" ] && [ "$COOKIES_PATH" != "null" ]; then
    if [ -f "$COOKIES_PATH" ]; then
        echo "✅ Cookies fayli mavjud va to'g'ri"
    else
        echo "❌ Cookies fayli topilmadi: $COOKIES_PATH"
    fi
else
    echo "❌ Config'da cookies path yo'q!"
fi
echo ""

# 3. Workerlarni to'xtatish
echo "3️⃣ Workerlarni to'xtatish..."
pkill -9 -f "artisan queue:work" 2>/dev/null
sleep 3
echo "✅ Workerlarni to'xtatildi"
echo ""

# 4. Loglarni tozalash (eski xatolarni olib tashlash)
echo "4️⃣ Loglarni tozalash..."
> storage/logs/queue-downloads.log
> storage/logs/queue-telegram.log
echo "✅ Loglar tozalandi"
echo ""

# 5. Workerlarni qayta ishga tushirish
echo "5️⃣ Workerlarni qayta ishga tushirish..."
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
    echo "❌ Workerlarni ishlamayapti!"
fi
echo ""

echo "========================================================"
echo "✅ Tugadi!"
echo ""
echo "📱 Keyingi qadam:"
echo "   1. Telegram bot'ga YANGI Instagram link yuboring"
echo "   2. Real-time loglarni kuzatish uchun:"
echo "      tail -f storage/logs/laravel.log"
echo ""
echo "⚠️  Eslatma:"
echo "   - Oxirgi xato eski edi (05:02, hozir 06:37)"
echo "   - Yangi test qiling - cookies bilan ishlashi kerak"
echo ""
