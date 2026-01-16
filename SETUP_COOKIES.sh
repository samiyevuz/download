#!/bin/bash

echo "🍪 Instagram Cookies Sozlash"
echo "=============================="
echo ""

cd ~/www/download.e-qarz.uz

# 1. Cookies faylini tekshirish
echo "1️⃣ Cookies faylini tekshirish..."
COOKIES_FILE="storage/app/cookies/instagram_cookies.txt"
COOKIES_FULL_PATH="$(pwd)/$COOKIES_FILE"

if [ ! -f "$COOKIES_FILE" ]; then
    echo "❌ Cookies fayli topilmadi: $COOKIES_FILE"
    echo "   📝 Faylni yuklang yoki yarating"
    exit 1
fi

COOKIES_SIZE=$(du -h "$COOKIES_FILE" | cut -f1)
echo "✅ Cookies fayli mavjud: $COOKIES_FILE"
echo "   📝 Fayl hajmi: $COOKIES_SIZE"
echo ""

# 2. Cookies fayli tarkibini tekshirish
echo "2️⃣ Cookies fayli tarkibini tekshirish..."
if grep -q "instagram.com" "$COOKIES_FILE" 2>/dev/null; then
    echo "   ✅ instagram.com topildi"
    INSTAGRAM_LINES=$(grep -c "instagram.com" "$COOKIES_FILE" 2>/dev/null || echo "0")
    echo "   📝 Instagram cookies qatorlari: $INSTAGRAM_LINES"
else
    echo "   ⚠️  instagram.com topilmadi!"
    echo "   ⚠️  Cookies fayli noto'g'ri formatda bo'lishi mumkin"
fi

if grep -q "sessionid" "$COOKIES_FILE" 2>/dev/null; then
    echo "   ✅ sessionid cookie topildi (muhim)"
else
    echo "   ❌ sessionid cookie topilmadi (MUHIM!)"
    echo "   ⚠️  Cookies faylida sessionid bo'lishi kerak"
fi

if grep -q "csrftoken" "$COOKIES_FILE" 2>/dev/null; then
    echo "   ✅ csrftoken cookie topildi"
else
    echo "   ⚠️  csrftoken cookie topilmadi"
fi
echo ""

# 3. .env faylini yangilash
echo "3️⃣ .env faylini yangilash..."
if grep -q "INSTAGRAM_COOKIES_PATH" .env 2>/dev/null; then
    # Update existing path
    sed -i "s|^INSTAGRAM_COOKIES_PATH=.*|INSTAGRAM_COOKIES_PATH=$COOKIES_FULL_PATH|" .env
    echo "✅ .env faylda path yangilandi"
    echo "   Path: $COOKIES_FULL_PATH"
else
    echo "INSTAGRAM_COOKIES_PATH=$COOKIES_FULL_PATH" >> .env
    echo "✅ .env faylga path qo'shildi"
    echo "   Path: $COOKIES_FULL_PATH"
fi
echo ""

# 4. Config yangilash
echo "4️⃣ Config yangilash..."
php artisan config:clear
php artisan config:cache
echo "✅ Config yangilandi"
echo ""

# 5. Cookies faylini to'liq tekshirish
echo "5️⃣ Cookies faylini to'liq tekshirish..."
chmod +x CHECK_INSTAGRAM_COOKIES.sh
./CHECK_INSTAGRAM_COOKIES.sh
echo ""

# 6. Workerlarni qayta ishga tushirish
echo "6️⃣ Workerlarni qayta ishga tushirish..."
pkill -9 -f "artisan queue:work" 2>/dev/null
sleep 2

nohup php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
DOWNLOAD_PID=$!

nohup php artisan queue:work redis --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &
TELEGRAM_PID=$!

sleep 3
echo "✅ Workerlarni ishga tushirdim (PIDs: $DOWNLOAD_PID, $TELEGRAM_PID)"
echo ""

# 7. Tekshirish
echo "7️⃣ Workerlarni tekshirish..."
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
echo "📝 Sozlash yakunlandi:"
echo "   ✅ Cookies fayli tekshirildi"
echo "   ✅ .env fayli yangilandi"
echo "   ✅ Config yangilandi"
echo "   ✅ Workerlarni qayta ishga tushirdim"
echo ""
echo "🧪 Test qiling:"
echo "   1. Botga Instagram link yuboring"
echo "   2. Loglarni kuzatib boring:"
echo "      tail -f storage/logs/queue-downloads.log"
echo "      tail -f storage/logs/laravel.log"
echo ""
