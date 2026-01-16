#!/bin/bash

echo "📁 Cookies papkasini yaratish..."
echo ""

PROJECT_DIR="/var/www/sardor/data/www/download.e-qarz.uz"
COOKIES_DIR="$PROJECT_DIR/storage/app/cookies"

# 1. Cookies papkasini yaratish
echo "1️⃣ Cookies papkasini yaratish..."
mkdir -p "$COOKIES_DIR"
chmod 755 "$COOKIES_DIR"

if [ -d "$COOKIES_DIR" ]; then
    echo "✅ Papka muvaffaqiyatli yaratildi: $COOKIES_DIR"
else
    echo "❌ Papka yaratilmadi!"
    exit 1
fi
echo ""

# 2. Permissions tekshirish
echo "2️⃣ Permissions tekshirish..."
PERMS=$(stat -c "%a" "$COOKIES_DIR" 2>/dev/null || stat -f "%OLp" "$COOKIES_DIR" 2>/dev/null || echo "unknown")
echo "   Permissions: $PERMS"
echo ""

# 3. .gitignore ga qo'shish
echo "3️⃣ .gitignore ni tekshirish..."
cd "$PROJECT_DIR"
if grep -q "storage/app/cookies" .gitignore 2>/dev/null; then
    echo "✅ Cookies papkasi .gitignore da mavjud"
else
    echo "" >> .gitignore
    echo "# Instagram cookies" >> .gitignore
    echo "/storage/app/cookies" >> .gitignore
    echo "✅ .gitignore ga qo'shildi"
fi
echo ""

echo "✅ Tugadi!"
echo ""
echo "📋 Keyingi qadamlar:"
echo "   1. Chrome'da Instagram.com ga login qiling"
echo "   2. 'Get cookies.txt' extension orqali cookies'ni export qiling"
echo "   3. Faylni 'instagram_cookies.txt' deb nomlang"
echo "   4. Serverga yuklang: $COOKIES_DIR/instagram_cookies.txt"
echo ""
echo "📂 Papka manzili: $COOKIES_DIR"
