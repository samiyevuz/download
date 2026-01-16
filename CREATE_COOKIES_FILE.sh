#!/bin/bash

echo "🍪 Instagram Cookies Faylini Yaratish"
echo "======================================"
echo ""

cd ~/www/download.e-qarz.uz

# 1. Cookies papkasini yaratish
echo "1️⃣ Cookies papkasini yaratish..."
COOKIES_DIR="storage/app/cookies"
mkdir -p "$COOKIES_DIR"
chmod 755 "$COOKIES_DIR"
echo "✅ Papka yaratildi: $COOKIES_DIR"
echo ""

# 2. Template fayl yaratish
echo "2️⃣ Template cookies faylini yaratish..."
COOKIES_FILE="$COOKIES_DIR/instagram_cookies.txt"
COOKIES_FULL_PATH="$(pwd)/$COOKIES_FILE"

if [ -f "$COOKIES_FILE" ]; then
    echo "⚠️  Cookies fayli allaqachon mavjud: $COOKIES_FILE"
    echo "   Fayl hajmi: $(du -h "$COOKIES_FILE" | cut -f1)"
    echo ""
    read -p "Faylni o'chirib, yangi template yaratish kerakmi? (y/n): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        rm -f "$COOKIES_FILE"
        echo "✅ Eski fayl o'chirildi"
    else
        echo "✅ Eski fayl saqlandi"
        exit 0
    fi
fi

# Create template file
cat > "$COOKIES_FILE" << 'EOF'
# Netscape HTTP Cookie File
# This file was generated manually
# 
# Format: domain	flag	path	secure	expiration	name	value
# 
# MUHIM: Quyidagi cookies'ni browser'dan oling va values'larini qo'ying
# 
# Qanday olish:
# 1. Browser'da Instagram.com ga login qiling
# 2. F12 (DevTools) → Application → Cookies → instagram.com
# 3. Har bir cookie'ning 'Value' qismini copy qiling
# 4. Quyidagi qatorlarda YOUR_*_HERE o'rniga qo'ying
#
# Expiration: Unix timestamp (masalan: 1735689600 - 2025 yil uchun)
# Expiration hisoblash: date -d "+365 days" +%s
#
# Muhim cookies:
# - sessionid (ENG MUHIM! - uzun string)
# - csrftoken (Muhim - qisqa string)
# - ds_user_id (Foydali - raqam)

.instagram.com	TRUE	/	FALSE	1735689600	sessionid	YOUR_SESSIONID_VALUE_HERE
.instagram.com	TRUE	/	FALSE	1735689600	csrftoken	YOUR_CSRFTOKEN_VALUE_HERE
.instagram.com	TRUE	/	FALSE	1735689600	ds_user_id	YOUR_USER_ID_HERE
EOF

chmod 600 "$COOKIES_FILE"
echo "✅ Template fayl yaratildi: $COOKIES_FILE"
echo ""

# 3. .env faylini yangilash
echo "3️⃣ .env faylini yangilash..."
if grep -q "INSTAGRAM_COOKIES_PATH" .env 2>/dev/null; then
    sed -i "s|^INSTAGRAM_COOKIES_PATH=.*|INSTAGRAM_COOKIES_PATH=$COOKIES_FULL_PATH|" .env
    echo "✅ .env faylda path yangilandi"
else
    echo "INSTAGRAM_COOKIES_PATH=$COOKIES_FULL_PATH" >> .env
    echo "✅ .env faylga path qo'shildi"
fi
echo "   Path: $COOKIES_FULL_PATH"
echo ""

# 4. Ko'rsatmalar
echo "4️⃣ Keyingi qadamlar:"
echo "======================================"
echo ""
echo "📝 Cookies values'larini qo'shish:"
echo "   1. Browser'da Instagram.com ga login qiling"
echo "   2. F12 (DevTools) oching"
echo "   3. Application tab → Cookies → instagram.com"
echo "   4. Quyidagi cookies'ni toping va Value'larini copy qiling:"
echo ""
echo "   ✅ sessionid - ENG MUHIM! (uzun string)"
echo "   ✅ csrftoken - Muhim (qisqa string)"
echo "   ✅ ds_user_id - Foydali (raqam)"
echo ""
echo "   5. Faylni tahrirlang:"
echo "      nano $COOKIES_FILE"
echo ""
echo "   6. YOUR_*_HERE o'rniga real values'larini qo'ying"
echo ""
echo "   7. Expiration yangilang (1 yil uchun):"
echo "      date -d \"+365 days\" +%s"
echo ""
echo "📤 Faylni serverga yuklash (agar local'da yaratgan bo'lsangiz):"
echo "   scp $COOKIES_FILE user@server:$COOKIES_FULL_PATH"
echo ""
echo "✅ Tekshirish:"
echo "   chmod +x CHECK_INSTAGRAM_COOKIES.sh"
echo "   ./CHECK_INSTAGRAM_COOKIES.sh"
echo ""
