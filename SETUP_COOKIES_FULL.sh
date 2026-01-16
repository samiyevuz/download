#!/bin/bash

echo "🍪 Instagram Cookies To'liq Sozlash"
echo "===================================="
echo ""

PROJECT_DIR="/var/www/sardor/data/www/download.e-qarz.uz"
COOKIES_DIR="$PROJECT_DIR/storage/app/cookies"
COOKIES_FILE="$COOKIES_DIR/instagram_cookies.txt"

# 1. Cookies papkasini yaratish
echo "1️⃣ Cookies papkasini yaratish..."
mkdir -p "$COOKIES_DIR"
chmod 755 "$COOKIES_DIR"
echo "✅ Papka yaratildi: $COOKIES_DIR"
echo ""

# 2. .env faylini tekshirish va yangilash
echo "2️⃣ .env faylini tekshirish..."
cd "$PROJECT_DIR"

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

# 3. Cookies faylini tekshirish
echo "3️⃣ Cookies faylini tekshirish..."
if [ -f "$COOKIES_FILE" ]; then
    echo "✅ Cookies fayli mavjud: $COOKIES_FILE"
    chmod 600 "$COOKIES_FILE"
    echo "✅ Permissions o'rnatildi (600)"
    
    SIZE=$(ls -lh "$COOKIES_FILE" | awk '{print $5}')
    LINES=$(wc -l < "$COOKIES_FILE" 2>/dev/null || echo "0")
    echo "   Fayl hajmi: $SIZE"
    echo "   Qatorlar soni: $LINES"
    
    # Cookies faylini to'g'ri formatda ekanligini tekshirish
    if head -1 "$COOKIES_FILE" | grep -q "# Netscape HTTP Cookie File"; then
        echo "✅ Cookies fayli to'g'ri formatda (Netscape)"
    else
        echo "⚠️  Cookies fayli format to'g'ri emas (Netscape format bo'lishi kerak)"
    fi
else
    echo "⚠️  Cookies fayli hali mavjud emas: $COOKIES_FILE"
    echo ""
    echo "📋 Keyingi qadamlar:"
    echo "   1. Chrome'da Instagram.com ga login qiling"
    echo "   2. 'Get cookies.txt' extension'ni o'rnating"
    echo "   3. Extension orqali cookies'ni export qiling"
    echo "   4. Faylni 'instagram_cookies.txt' deb nomlang"
    echo "   5. Serverga yuklang: $COOKIES_FILE"
    echo ""
    echo "   Yoki:"
    echo "   WinSCP/FileZilla orqali yuklang"
fi
echo ""

# 4. .gitignore ni tekshirish
echo "4️⃣ .gitignore ni tekshirish..."
if grep -q "storage/app/cookies" .gitignore 2>/dev/null; then
    echo "✅ Cookies papkasi .gitignore da mavjud"
else
    echo "" >> .gitignore
    echo "# Instagram cookies" >> .gitignore
    echo "/storage/app/cookies" >> .gitignore
    echo "✅ .gitignore ga qo'shildi"
fi
echo ""

# 5. Config yangilash
echo "5️⃣ Config yangilash..."
php artisan config:clear > /dev/null 2>&1
php artisan config:cache > /dev/null 2>&1
echo "✅ Config yangilandi"
echo ""

# 6. Tekshirish
echo "6️⃣ Tekshirish..."
COOKIES_PATH=$(php artisan tinker --execute="echo config('telegram.instagram_cookies_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1)
if [ -n "$COOKIES_PATH" ] && [ "$COOKIES_PATH" != "null" ]; then
    echo "✅ Config'da cookies path topildi: $COOKIES_PATH"
else
    echo "⚠️  Config'da cookies path topilmadi"
fi
echo ""

echo "===================================="
if [ -f "$COOKIES_FILE" ]; then
    echo "✅ BARCHA SOZLASH TUGADI!"
    echo ""
    echo "🎉 Cookies fayli mavjud va bot ishlatishi mumkin!"
    echo ""
    echo "📱 Keyingi qadamlar:"
    echo "   1. Workerlarni qayta ishga tushiring"
    echo "   2. Botga Instagram link yuborib test qiling!"
else
    echo "⚠️  COOKIES FAYLI HALI MAVJUD EMAS!"
    echo ""
    echo "📋 Ko'rsatmalar:"
    echo "   1. Chrome'da Instagram.com ga login qiling"
    echo "   2. 'Get cookies.txt' extension'ni o'rnating"
    echo "   3. Extension orqali cookies'ni export qiling"
    echo "   4. Faylni 'instagram_cookies.txt' deb nomlang"
    echo "   5. Serverga yuklang: $COOKIES_FILE"
    echo "   6. Keyin bu script'ni qayta ishga tushiring"
fi
echo ""
