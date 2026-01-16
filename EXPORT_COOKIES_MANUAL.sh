#!/bin/bash

echo "🍪 Instagram Cookies Export - Qo'llanma"
echo "=========================================="
echo ""

cd ~/www/download.e-qarz.uz

# 1. Cookies papkasini yaratish
echo "1️⃣ Cookies papkasini yaratish..."
COOKIES_DIR="storage/app/cookies"
mkdir -p "$COOKIES_DIR"
chmod 755 "$COOKIES_DIR"
echo "✅ Papka yaratildi: $COOKIES_DIR"
echo ""

# 2. .env faylini tekshirish
echo "2️⃣ .env faylini tekshirish..."
COOKIES_FILE="$COOKIES_DIR/instagram_cookies.txt"
COOKIES_FULL_PATH="$(pwd)/$COOKIES_FILE"

if grep -q "INSTAGRAM_COOKIES_PATH" .env 2>/dev/null; then
    echo "✅ INSTAGRAM_COOKIES_PATH mavjud"
    CURRENT_PATH=$(grep "INSTAGRAM_COOKIES_PATH" .env | cut -d'=' -f2)
    echo "   Hozirgi path: $CURRENT_PATH"
else
    echo "⚠️  INSTAGRAM_COOKIES_PATH yo'q"
    echo ""
    echo "📝 .env faylga qo'shish:"
    echo "INSTAGRAM_COOKIES_PATH=$COOKIES_FULL_PATH"
    echo ""
    read -p ".env faylga avtomatik qo'shish kerakmi? (y/n): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        echo "INSTAGRAM_COOKIES_PATH=$COOKIES_FULL_PATH" >> .env
        echo "✅ .env faylga qo'shildi"
    fi
fi
echo ""

# 3. Qo'llanma
echo "3️⃣ Cookies Export Qilish - Qo'llanma"
echo "======================================"
echo ""
echo "📱 Method 1: EditThisCookie (Chrome/Edge)"
echo "   1. Chrome/Edge'da Instagram.com ga login qiling"
echo "   2. EditThisCookie extension icon'iga bosing"
echo "   3. 'Export' tugmasini bosing"
echo "   4. Format: 'Netscape HTTP Cookie File' ni tanlang"
echo "   5. Faylni saqlang: instagram_cookies.txt"
echo "   6. Serverga yuklang: $COOKIES_FULL_PATH"
echo ""
echo "📱 Method 2: Cookie-Editor (Firefox/Chrome)"
echo "   1. Browser'da Instagram.com ga login qiling"
echo "   2. Cookie-Editor extension icon'iga bosing"
echo "   3. 'Export' tugmasini bosing"
echo "   4. Format: 'Netscape' ni tanlang"
echo "   5. Faylni saqlang: instagram_cookies.txt"
echo "   6. Serverga yuklang: $COOKIES_FULL_PATH"
echo ""
echo "📱 Method 3: Browser DevTools (Extension yo'q bo'lsa)"
echo "   1. F12 (DevTools) oching"
echo "   2. Application tab → Cookies → instagram.com"
echo "   3. Barcha cookies'ni ko'ring"
echo "   4. Quyidagi formatda fayl yarating:"
echo ""
echo "   Format (Netscape):"
echo "   # Netscape HTTP Cookie File"
echo "   .instagram.com	TRUE	/	FALSE	1735689600	sessionid	YOUR_SESSION_ID"
echo "   .instagram.com	TRUE	/	FALSE	1735689600	csrftoken	YOUR_CSRF_TOKEN"
echo ""
echo "   Muhim cookies:"
echo "   - sessionid (ENG MUHIM!)"
echo "   - csrftoken"
echo "   - ds_user_id"
echo ""
echo "   5. Faylni saqlang: instagram_cookies.txt"
echo "   6. Serverga yuklang: $COOKIES_FULL_PATH"
echo ""

# 4. Fayl yaratish (agar kerak bo'lsa)
echo "4️⃣ Cookies faylini yaratish (ixtiyoriy)..."
if [ ! -f "$COOKIES_FILE" ]; then
    echo "⚠️  Cookies fayli hozircha mavjud emas"
    echo ""
    read -p "Bo'sh template fayl yaratish kerakmi? (y/n): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        cat > "$COOKIES_FILE" << 'EOF'
# Netscape HTTP Cookie File
# This file was generated manually
# Format: domain	flag	path	secure	expiration	name	value
# 
# MUHIM: Quyidagi cookies'ni o'z values bilan to'ldiring:
# 
# .instagram.com	TRUE	/	FALSE	1735689600	sessionid	YOUR_SESSION_ID_HERE
# .instagram.com	TRUE	/	FALSE	1735689600	csrftoken	YOUR_CSRF_TOKEN_HERE
# .instagram.com	TRUE	/	FALSE	1735689600	ds_user_id	YOUR_USER_ID_HERE
#
# Qanday olish:
# 1. Browser'da Instagram.com ga login qiling
# 2. F12 (DevTools) → Application → Cookies → instagram.com
# 3. Har bir cookie'ning 'Value' qismini copy qiling
# 4. Yuqoridagi qatorlarda YOUR_*_HERE o'rniga qo'ying
EOF
        chmod 600 "$COOKIES_FILE"
        echo "✅ Template fayl yaratildi: $COOKIES_FILE"
        echo "   📝 Endi faylni tahrirlang va cookies values'larini qo'shing"
    fi
else
    echo "✅ Cookies fayli mavjud: $COOKIES_FILE"
    COOKIES_SIZE=$(du -h "$COOKIES_FILE" | cut -f1)
    echo "   📝 Fayl hajmi: $COOKIES_SIZE"
    
    # Check if file has content
    if [ -s "$COOKIES_FILE" ]; then
        if grep -q "instagram.com" "$COOKIES_FILE" 2>/dev/null; then
            echo "   ✅ instagram.com topildi"
            if grep -q "sessionid" "$COOKIES_FILE" 2>/dev/null; then
                echo "   ✅ sessionid cookie topildi"
            else
                echo "   ⚠️  sessionid cookie topilmadi (MUHIM!)"
            fi
        else
            echo "   ⚠️  instagram.com topilmadi"
        fi
    else
        echo "   ⚠️  Fayl bo'sh"
    fi
fi
echo ""

# 5. .env faylini yangilash
echo "5️⃣ .env faylini yangilash..."
if grep -q "INSTAGRAM_COOKIES_PATH" .env 2>/dev/null; then
    # Update existing path
    sed -i "s|^INSTAGRAM_COOKIES_PATH=.*|INSTAGRAM_COOKIES_PATH=$COOKIES_FULL_PATH|" .env
    echo "✅ .env faylda path yangilandi: $COOKIES_FULL_PATH"
else
    echo "INSTAGRAM_COOKIES_PATH=$COOKIES_FULL_PATH" >> .env
    echo "✅ .env faylga path qo'shildi: $COOKIES_FULL_PATH"
fi
echo ""

# 6. Config yangilash
echo "6️⃣ Config yangilash..."
php artisan config:clear
php artisan config:cache
echo "✅ Config yangilandi"
echo ""

echo "===================================="
echo "✅ Tugadi!"
echo ""
echo "📝 Keyingi qadamlar:"
echo "   1. Browser'dan Instagram cookies'ni export qiling"
echo "   2. Faylni serverga yuklang: $COOKIES_FULL_PATH"
echo "   3. Tekshirish: ./CHECK_INSTAGRAM_COOKIES.sh"
echo ""
echo "💡 Fayl yuklash:"
echo "   - FileZilla, WinSCP, yoki boshqa FTP client"
echo "   - Yoki: scp instagram_cookies.txt user@server:$COOKIES_FULL_PATH"
echo ""
