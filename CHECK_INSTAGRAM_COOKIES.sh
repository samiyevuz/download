#!/bin/bash

echo "🔍 Instagram cookies faylini tekshirish..."
echo ""

cd ~/www/download.e-qarz.uz

# 1. Cookies path'ni olish
echo "1️⃣ Cookies path'ni tekshirish..."
COOKIES_PATH=$(php artisan tinker --execute="echo config('telegram.instagram_cookies_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1)
echo "   Cookies path: $COOKIES_PATH"
echo ""

if [ -z "$COOKIES_PATH" ] || [ "$COOKIES_PATH" = "null" ] || [ "$COOKIES_PATH" = "" ]; then
    echo "❌ Instagram cookies fayli sozlanmagan!"
    echo ""
    echo "📝 Qanday sozlash:"
    echo "   1. Browser'dan Instagram cookies'ni export qiling (Netscape format)"
    echo "   2. .env faylida qo'shing:"
    echo "      INSTAGRAM_COOKIES_PATH=/path/to/instagram_cookies.txt"
    echo "   3. php artisan config:clear && php artisan config:cache"
    exit 1
fi

# 2. Cookies faylini tekshirish
echo "2️⃣ Cookies faylini tekshirish..."
if [ ! -f "$COOKIES_PATH" ]; then
    echo "   ❌ Cookies fayli topilmadi: $COOKIES_PATH"
    echo "   📝 Fayl yo'lini tekshiring yoki yangi cookies export qiling"
    exit 1
fi

echo "   ✅ Cookies fayli mavjud"
COOKIES_SIZE=$(du -h "$COOKIES_PATH" | cut -f1)
echo "   📝 Fayl hajmi: $COOKIES_SIZE"
echo ""

# 3. Cookies faylini tekshirish
echo "3️⃣ Cookies fayli tarkibini tekshirish..."
if grep -q "instagram.com" "$COOKIES_PATH" 2>/dev/null; then
    echo "   ✅ instagram.com topildi"
    INSTAGRAM_LINES=$(grep -c "instagram.com" "$COOKIES_PATH" 2>/dev/null || echo "0")
    echo "   📝 Instagram cookies qatorlari: $INSTAGRAM_LINES"
else
    echo "   ❌ instagram.com topilmadi!"
    echo "   ⚠️  Cookies fayli noto'g'ri formatda bo'lishi mumkin"
fi

# Check for sessionid cookie (most important)
if grep -q "sessionid" "$COOKIES_PATH" 2>/dev/null; then
    echo "   ✅ sessionid cookie topildi (muhim)"
else
    echo "   ⚠️  sessionid cookie topilmadi (muhim cookie)"
fi

# Check for csrftoken
if grep -q "csrftoken" "$COOKIES_PATH" 2>/dev/null; then
    echo "   ✅ csrftoken cookie topildi"
else
    echo "   ⚠️  csrftoken cookie topilmadi"
fi

# Check file age
COOKIES_AGE_DAYS=$(find "$COOKIES_PATH" -mtime +30 2>/dev/null && echo "yes" || echo "no")
if [ "$COOKIES_AGE_DAYS" = "yes" ]; then
    echo "   ⚠️  Cookies fayli 30 kundan eski, yangilash tavsiya etiladi"
    COOKIES_DATE=$(stat -c %y "$COOKIES_PATH" 2>/dev/null || stat -f "%Sm" "$COOKIES_PATH" 2>/dev/null || echo "unknown")
    echo "   📅 Oxirgi o'zgarish: $COOKIES_DATE"
else
    COOKIES_DATE=$(stat -c %y "$COOKIES_PATH" 2>/dev/null || stat -f "%Sm" "$COOKIES_PATH" 2>/dev/null || echo "unknown")
    echo "   ✅ Cookies fayli yangi: $COOKIES_DATE"
fi
echo ""

# 4. Cookies fayli tarkibini batafsil ko'rish
echo "4️⃣ Cookies fayli tarkibini ko'rish..."
if [ -f "$COOKIES_PATH" ]; then
    echo "   📝 Fayl tarkibi (birinchi 10 qator):"
    head -10 "$COOKIES_PATH" | sed 's/^/      /'
    echo ""
    
    # Check for required cookies
    REQUIRED_COOKIES=("sessionid" "csrftoken")
    for cookie in "${REQUIRED_COOKIES[@]}"; do
        if grep -q "$cookie" "$COOKIES_PATH" 2>/dev/null; then
            echo "   ✅ $cookie cookie topildi"
        else
            echo "   ❌ $cookie cookie topilmadi (MUHIM!)"
        fi
    done
else
    echo "   ❌ Cookies fayli topilmadi"
fi
echo ""

# 5. yt-dlp bilan test qilish (ixtiyoriy)
echo "5️⃣ yt-dlp bilan cookies test qilish (ixtiyoriy)..."
YT_DLP_PATH=$(php artisan tinker --execute="echo config('telegram.yt_dlp_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1)

if [ -f "$YT_DLP_PATH" ] && [ -x "$YT_DLP_PATH" ] && [ -f "$COOKIES_PATH" ]; then
    echo "   Testing with a simple Instagram URL..."
    TEST_URL="https://www.instagram.com/p/DTjumuHiMr9/"
    
    # Try to get info with cookies (timeout 10 seconds)
    echo "   yt-dlp --dump-json --cookies \"$COOKIES_PATH\" \"$TEST_URL\" 2>&1 | head -5"
    TEST_OUTPUT=$(timeout 10 "$YT_DLP_PATH" --dump-json --cookies "$COOKIES_PATH" "$TEST_URL" 2>&1 | head -5)
    
    if echo "$TEST_OUTPUT" | grep -q "login required\|rate-limit\|private"; then
        echo "   ❌ Cookies ishlamayapti yoki eskirgan"
        echo "   💡 Cookies faylini yangilang"
    elif echo "$TEST_OUTPUT" | grep -q '"id"\|"title"\|"ext"'; then
        echo "   ✅ Cookies ishlayapti!"
    else
        echo "   ⚠️  Natija noaniq, to'liq test kerak"
        echo "   📝 Test output:"
        echo "$TEST_OUTPUT" | sed 's/^/      /'
    fi
else
    if [ ! -f "$YT_DLP_PATH" ] || [ ! -x "$YT_DLP_PATH" ]; then
        echo "   ⚠️  yt-dlp topilmadi, test qilib bo'lmadi"
    elif [ ! -f "$COOKIES_PATH" ]; then
        echo "   ⚠️  Cookies fayli topilmadi, test qilib bo'lmadi"
    fi
fi
echo ""

echo "===================================="
echo "📝 Xulosa:"
echo ""
if [ -f "$COOKIES_PATH" ] && [ -s "$COOKIES_PATH" ]; then
    if grep -q "YOUR_SESSIONID_VALUE_HERE\|YOUR_CSRFTOKEN_VALUE_HERE" "$COOKIES_PATH" 2>/dev/null; then
        echo "⚠️  Cookies fayli template formatda (values to'ldirilmagan)"
        echo ""
        echo "📝 Qanday to'ldirish:"
        echo "   1. Browser'da Instagram.com ga login qiling"
        echo "   2. F12 (DevTools) → Application → Cookies → instagram.com"
        echo "   3. sessionid, csrftoken, ds_user_id values'larini copy qiling"
        echo "   4. Faylni tahrirlang: nano $COOKIES_PATH"
        echo "   5. YOUR_*_HERE o'rniga real values'larini qo'ying"
        echo ""
    else
        echo "✅ Cookies fayli to'ldirilgan ko'rinadi"
        echo ""
        echo "Agar cookies ishlamayapti:"
        echo "   1. Browser'dan yangi cookies export qiling"
        echo "   2. Netscape formatda saqlang"
        echo "   3. .env faylida path'ni yangilang"
        echo "   4. php artisan config:clear && php artisan config:cache"
        echo ""
    fi
else
    echo "❌ Cookies fayli mavjud emas yoki bo'sh"
    echo ""
    echo "📝 Cookies faylini yaratish:"
    echo "   chmod +x CREATE_COOKIES_FILE.sh"
    echo "   ./CREATE_COOKIES_FILE.sh"
    echo ""
    echo "Yoki qo'lda:"
    echo "   nano storage/app/cookies/instagram_cookies.txt"
    echo ""
fi

echo "Cookies export qilish (mavjud extension'lar):"
echo "   - EditThisCookie (Chrome/Edge): Export → Netscape format"
echo "   - Cookie-Editor (Firefox/Chrome): Export → Netscape format"
echo "   - DevTools (Extension yo'q): F12 → Application → Cookies → qo'lda yozish"
echo ""
echo "📖 Batafsil qo'llanma:"
echo "   cat EXPORT_COOKIES_STEP_BY_STEP.md"
echo ""
