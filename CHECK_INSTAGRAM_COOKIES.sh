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

# 4. yt-dlp bilan test qilish
echo "4️⃣ yt-dlp bilan cookies test qilish..."
YT_DLP_PATH=$(php artisan tinker --execute="echo config('telegram.yt_dlp_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1)

if [ -f "$YT_DLP_PATH" ] && [ -x "$YT_DLP_PATH" ]; then
    echo "   Testing with a simple Instagram URL..."
    TEST_URL="https://www.instagram.com/p/DTjumuHiMr9/"
    
    # Try to get info with cookies
    echo "   yt-dlp --dump-json --cookies \"$COOKIES_PATH\" \"$TEST_URL\" 2>&1 | head -5"
    TEST_OUTPUT=$("$YT_DLP_PATH" --dump-json --cookies "$COOKIES_PATH" "$TEST_URL" 2>&1 | head -5)
    
    if echo "$TEST_OUTPUT" | grep -q "login required\|rate-limit\|private"; then
        echo "   ❌ Cookies ishlamayapti yoki eskirgan"
        echo "   💡 Cookies faylini yangilang"
    elif echo "$TEST_OUTPUT" | grep -q '"id"\|"title"\|"ext"'; then
        echo "   ✅ Cookies ishlayapti!"
    else
        echo "   ⚠️  Natija noaniq, to'liq test kerak"
    fi
else
    echo "   ⚠️  yt-dlp topilmadi, test qilib bo'lmadi"
fi
echo ""

echo "===================================="
echo "📝 Xulosa:"
echo ""
echo "Agar cookies ishlamayapti:"
echo "   1. Browser'dan yangi cookies export qiling"
echo "   2. Netscape formatda saqlang"
echo "   3. .env faylida path'ni yangilang"
echo "   4. php artisan config:clear && php artisan config:cache"
echo ""
echo "Cookies export qilish:"
echo "   - Chrome: EditThisCookie extension"
echo "   - Firefox: Cookie-Editor extension"
echo "   - Format: Netscape HTTP Cookie File"
echo ""
