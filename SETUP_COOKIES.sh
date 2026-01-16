#!/bin/bash

echo "🍪 Instagram Cookies Sozlash"
echo "=============================="
echo ""

# 1. Cookies papkasini yaratish
echo "1️⃣ Cookies papkasini yaratish..."
COOKIES_DIR="/var/www/sardor/data/www/download.e-qarz.uz/storage/app/cookies"
mkdir -p "$COOKIES_DIR"
chmod 755 "$COOKIES_DIR"
echo "✅ Papka yaratildi: $COOKIES_DIR"
echo ""

# 2. .env faylini tekshirish
echo "2️⃣ .env faylini tekshirish..."
if grep -q "INSTAGRAM_COOKIES_PATH" .env 2>/dev/null; then
    echo "✅ INSTAGRAM_COOKIES_PATH allaqachon mavjud"
    grep "INSTAGRAM_COOKIES_PATH" .env
else
    echo "⚠️  INSTAGRAM_COOKIES_PATH yo'q"
    echo ""
    echo "📝 .env fayliga qo'shing:"
    echo "INSTAGRAM_COOKIES_PATH=$COOKIES_DIR/instagram_cookies.txt"
    echo ""
    read -p ".env faylga qo'shish kerakmi? (y/n): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        echo "INSTAGRAM_COOKIES_PATH=$COOKIES_DIR/instagram_cookies.txt" >> .env
        echo "✅ .env faylga qo'shildi"
    fi
fi
echo ""

# 3. Ko'rsatmalar
echo "3️⃣ Ko'rsatmalar:"
echo "-----------------------------------"
echo "1. Chrome/Edge da Instagram.com ga login qiling"
echo "2. Extension o'rnating: Get cookies.txt"
echo "   https://chrome.google.com/webstore/detail/get-cookiestxt-locally/cclelndahbckbenkjhflpdbgdldlbecc"
echo "3. Extension orqali cookies.txt ni saqlang"
echo "4. Fayl nomini o'zgartiring: instagram_cookies.txt"
echo "5. Serverga yuklang: $COOKIES_DIR/instagram_cookies.txt"
echo ""
echo "Yoki:"
echo "6. Browser DevTools (F12) > Application > Cookies > instagram.com"
echo "7. Barcha cookies ni copy qiling"
echo "8. cookies.txt formatiga convert qiling"
echo "9. Serverga yuklang"
echo ""

# 4. Tekshirish
echo "4️⃣ Tekshirish..."
if [ -f "$COOKIES_DIR/instagram_cookies.txt" ]; then
    echo "✅ Cookies fayli mavjud: $COOKIES_DIR/instagram_cookies.txt"
    chmod 600 "$COOKIES_DIR/instagram_cookies.txt"
    echo "✅ Permissions o'rnatildi (600)"
    
    # Fayl hajmini ko'rsatish
    SIZE=$(ls -lh "$COOKIES_DIR/instagram_cookies.txt" | awk '{print $5}')
    echo "   Fayl hajmi: $SIZE"
    
    # Bir nechta cookie bor-yo'qligini tekshirish
    COOKIE_COUNT=$(grep -c "^" "$COOKIES_DIR/instagram_cookies.txt" 2>/dev/null || echo "0")
    echo "   Qatorlar soni: $COOKIE_COUNT"
else
    echo "⚠️  Cookies fayli hali mavjud emas"
    echo "   Yuqoridagi ko'rsatmalarga amal qiling"
fi
echo ""

# 5. .gitignore ga qo'shish
echo "5️⃣ .gitignore ni tekshirish..."
if grep -q "storage/app/cookies" .gitignore 2>/dev/null; then
    echo "✅ Cookies papkasi .gitignore da mavjud"
else
    echo "# Instagram cookies" >> .gitignore
    echo "/storage/app/cookies" >> .gitignore
    echo "✅ .gitignore ga qo'shildi"
fi
echo ""

echo "============================================"
echo "✅ Sozlash tugadi!"
echo ""
echo "📱 Keyingi qadamlar:"
echo "   1. Cookies faylini yuklang: $COOKIES_DIR/instagram_cookies.txt"
echo "   2. Config yangilang: php artisan config:clear && php artisan config:cache"
echo "   3. Botga Instagram link yuborib test qiling!"
echo ""
echo "🎉 Cookies bilan Instagram yuklab olish 99% ishonchli!"
