#!/bin/bash

echo "🍪 Bir Nechta Instagram Cookie Fayllarini Sozlash"
echo "=================================================="
echo ""

cd ~/www/download.e-qarz.uz

# 1. Cookie fayllarini tekshirish
echo "1️⃣ Cookie fayllarini tekshirish..."
COOKIES_DIR="storage/app/cookies"
mkdir -p "$COOKIES_DIR"

if [ -f "$COOKIES_DIR/instagram_cookies.txt" ]; then
    echo "✅ instagram_cookies.txt mavjud"
    ls -lh "$COOKIES_DIR/instagram_cookies.txt"
else
    echo "⚠️  instagram_cookies.txt topilmadi"
fi

if [ -f "$COOKIES_DIR/instagram_cookies2.txt" ]; then
    echo "✅ instagram_cookies2.txt mavjud"
    ls -lh "$COOKIES_DIR/instagram_cookies2.txt"
else
    echo "ℹ️  instagram_cookies2.txt mavjud emas (ixtiyoriy)"
fi

if [ -f "$COOKIES_DIR/instagram_cookies3.txt" ]; then
    echo "✅ instagram_cookies3.txt mavjud"
    ls -lh "$COOKIES_DIR/instagram_cookies3.txt"
else
    echo "ℹ️  instagram_cookies3.txt mavjud emas (ixtiyoriy)"
fi
echo ""

# 2. .env faylini yangilash
echo "2️⃣ .env faylini yangilash..."

# Hozirgi cookie path'ni olish
CURRENT_COOKIE=$(grep "INSTAGRAM_COOKIES_PATH" .env 2>/dev/null | cut -d'=' -f2 | tr -d ' ')

# Barcha mavjud cookie fayllarini topish
COOKIE_FILES=()
if [ -f "$COOKIES_DIR/instagram_cookies.txt" ]; then
    COOKIE_FILES+=("$COOKIES_DIR/instagram_cookies.txt")
fi
if [ -f "$COOKIES_DIR/instagram_cookies2.txt" ]; then
    COOKIE_FILES+=("$COOKIES_DIR/instagram_cookies2.txt")
fi
if [ -f "$COOKIES_DIR/instagram_cookies3.txt" ]; then
    COOKIE_FILES+=("$COOKIES_DIR/instagram_cookies3.txt")
fi

if [ ${#COOKIE_FILES[@]} -eq 0 ]; then
    echo "❌ Hech qanday cookie fayli topilmadi!"
    echo ""
    echo "📝 Qanday qo'shish:"
    echo "   1. Cookie fayllarini quyidagi joyga qo'ying:"
    echo "      $COOKIES_DIR/instagram_cookies.txt"
    echo "      $COOKIES_DIR/instagram_cookies2.txt (ixtiyoriy)"
    echo "      $COOKIES_DIR/instagram_cookies3.txt (ixtiyoriy)"
    echo ""
    echo "   2. Keyin bu scriptni qayta ishga tushiring"
    exit 1
fi

# Cookie paths'ni comma-separated formatda yaratish
COOKIE_PATHS=$(IFS=','; echo "${COOKIE_FILES[*]}")

echo "   Topilgan cookie fayllar:"
for i in "${!COOKIE_FILES[@]}"; do
    echo "   $((i+1)). ${COOKIE_FILES[$i]}"
done
echo ""

# .env faylini yangilash
if grep -q "INSTAGRAM_COOKIES_PATHS" .env 2>/dev/null; then
    # Mavjud qatorni yangilash
    sed -i "s|INSTAGRAM_COOKIES_PATHS=.*|INSTAGRAM_COOKIES_PATHS=$COOKIE_PATHS|" .env
    echo "✅ INSTAGRAM_COOKIES_PATHS yangilandi"
else
    # Yangi qator qo'shish
    echo "" >> .env
    echo "# Multiple Instagram cookies for rotation (comma-separated)" >> .env
    echo "INSTAGRAM_COOKIES_PATHS=$COOKIE_PATHS" >> .env
    echo "✅ INSTAGRAM_COOKIES_PATHS qo'shildi"
fi

# Agar INSTAGRAM_COOKIES_PATH bo'lsa, uni ham saqlash
if [ -n "$CURRENT_COOKIE" ] && [ -f "$CURRENT_COOKIE" ]; then
    if ! echo "$COOKIE_PATHS" | grep -q "$CURRENT_COOKIE"; then
        # Agar hozirgi cookie paths'da yo'q bo'lsa, qo'shamiz
        COOKIE_PATHS="$CURRENT_COOKIE,$COOKIE_PATHS"
        sed -i "s|INSTAGRAM_COOKIES_PATHS=.*|INSTAGRAM_COOKIES_PATHS=$COOKIE_PATHS|" .env
        echo "✅ Hozirgi cookie ham qo'shildi"
    fi
fi
echo ""

# 3. Config yangilash
echo "3️⃣ Config yangilash..."
php artisan config:clear
php artisan config:cache
echo "✅ Config yangilandi"
echo ""

# 4. Cookie fayllarini tekshirish
echo "4️⃣ Cookie fayllarini batafsil tekshirish..."
for cookie_file in "${COOKIE_FILES[@]}"; do
    echo ""
    echo "📄 $(basename $cookie_file):"
    if [ -f "$cookie_file" ]; then
        SIZE=$(stat -f%z "$cookie_file" 2>/dev/null || stat -c%s "$cookie_file" 2>/dev/null)
        echo "   Hajm: $(numfmt --to=iec-i --suffix=B $SIZE 2>/dev/null || echo "${SIZE} bytes")"
        
        if grep -q "sessionid" "$cookie_file" 2>/dev/null; then
            echo "   ✅ sessionid topildi"
        else
            echo "   ⚠️  sessionid topilmadi"
        fi
        
        if grep -q "csrftoken" "$cookie_file" 2>/dev/null; then
            echo "   ✅ csrftoken topildi"
        else
            echo "   ⚠️  csrftoken topilmadi"
        fi
    fi
done
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
echo "🍪 Bir Nechta Cookie Tizimi:"
echo "   ✨ Bir nechta cookie fayllaridan foydalanish"
echo "   ✨ Avtomatik rotatsiya (agar biri ishlamasa, ikkinchisini sinaydi)"
echo "   ✨ Har bir cookie'ni alohida tekshirish"
echo ""
echo "📝 Qanday ishlaydi:"
echo "   1. Bot birinchi cookie'ni sinaydi"
echo "   2. Agar ishlamasa, ikkinchi cookie'ni sinaydi"
echo "   3. Agar hammasi ishlamasa, cookiesiz metodlarni sinaydi"
echo ""
echo "📋 Cookie fayllarini qo'shish:"
echo "   $COOKIES_DIR/instagram_cookies.txt (asosiy)"
echo "   $COOKIES_DIR/instagram_cookies2.txt (ixtiyoriy)"
echo "   $COOKIES_DIR/instagram_cookies3.txt (ixtiyoriy)"
echo ""
echo "   Keyin: ./SETUP_MULTIPLE_COOKIES.sh"
echo ""
echo "🧪 Test qiling:"
echo "   Botga Instagram rasm linki yuboring"
echo "   Loglarda qaysi cookie ishlatilganini ko'rasiz:"
echo "   tail -f storage/logs/laravel.log | grep -E 'cookie|Instagram image'"
echo ""
