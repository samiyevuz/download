#!/bin/bash

echo "🔍 Instagram Cookie Holati - To'liq Tekshirish"
echo "=============================================="
echo ""

COOKIES_PATH="/var/www/sardor/data/www/download.e-qarz.uz/storage/app/cookies/instagram_cookies.txt"
YT_DLP_PATH="/var/www/sardor/data/bin/yt-dlp"
TEST_URL="https://www.instagram.com/reel/DTlK_pNDEeb/"

# 1. Fayl mavjudligi
echo "1️⃣ Fayl Mavjudligi"
echo "-------------------"
if [ ! -f "$COOKIES_PATH" ]; then
    echo "❌ Cookie fayl topilmadi: $COOKIES_PATH"
    exit 1
fi

echo "✅ Cookie fayl mavjud: $COOKIES_PATH"
COOKIES_SIZE=$(du -h "$COOKIES_PATH" | cut -f1)
COOKIES_PERM=$(stat -c "%a" "$COOKIES_PATH" 2>/dev/null || stat -f "%OLp" "$COOKIES_PATH" 2>/dev/null || echo "N/A")
COOKIES_OWNER=$(stat -c "%U:%G" "$COOKIES_PATH" 2>/dev/null || stat -f "%Su:%Sg" "$COOKIES_PATH" 2>/dev/null || echo "N/A")
COOKIES_MODIFIED=$(stat -c "%y" "$COOKIES_PATH" 2>/dev/null || stat -f "%Sm" "$COOKIES_PATH" 2>/dev/null || echo "N/A")

echo "   📏 Hajm: $COOKIES_SIZE"
echo "   🔐 Permissions: $COOKIES_PERM"
echo "   👤 Owner: $COOKIES_OWNER"
echo "   📅 O'zgartirilgan: $COOKIES_MODIFIED"
echo ""

# 2. Fayl formati
echo "2️⃣ Fayl Formati"
echo "----------------"
FIRST_LINE=$(head -1 "$COOKIES_PATH")
if echo "$FIRST_LINE" | grep -qi "netscape\|http cookie"; then
    echo "✅ Netscape format to'g'ri"
    echo "   Header: $FIRST_LINE"
else
    echo "⚠️  Netscape format bo'lmasligi mumkin"
    echo "   Birinchi qator: $FIRST_LINE"
fi
echo ""

# 3. Cookie kontenti
echo "3️⃣ Cookie Kontenti"
echo "-------------------"
COOKIE_CONTENT=$(cat "$COOKIES_PATH")

# sessionid tekshiruvi
if echo "$COOKIE_CONTENT" | grep -q "sessionid"; then
    SESSIONID_COUNT=$(echo "$COOKIE_CONTENT" | grep -c "sessionid")
    echo "✅ sessionid cookie mavjud ($SESSIONID_COUNT qator)"
    
    # sessionid expiration tekshiruvi
    SESSIONID_LINE=$(echo "$COOKIE_CONTENT" | grep "sessionid" | grep -v "^#" | head -1)
    if [ -n "$SESSIONID_LINE" ]; then
        EXPIRATION=$(echo "$SESSIONID_LINE" | awk -F'\t' '{print $5}')
        if [ -n "$EXPIRATION" ] && [ "$EXPIRATION" != "0" ]; then
            CURRENT_TIME=$(date +%s)
            if [ "$EXPIRATION" -gt "$CURRENT_TIME" ]; then
                EXPIRATION_DATE=$(date -d "@$EXPIRATION" 2>/dev/null || date -r "$EXPIRATION" 2>/dev/null || echo "N/A")
                echo "   ✅ sessionid haqiqiy (expires: $EXPIRATION_DATE)"
            else
                EXPIRATION_DATE=$(date -d "@$EXPIRATION" 2>/dev/null || date -r "$EXPIRATION" 2>/dev/null || echo "N/A")
                echo "   ❌ sessionid eskirgan (expired: $EXPIRATION_DATE)"
            fi
        else
            echo "   ⚠️  sessionid session cookie (expiration: 0)"
        fi
    fi
else
    echo "❌ sessionid cookie topilmadi!"
fi

# csrftoken tekshiruvi
if echo "$COOKIE_CONTENT" | grep -q "csrftoken"; then
    echo "✅ csrftoken cookie mavjud"
else
    echo "⚠️  csrftoken cookie topilmadi"
fi

# instagram.com domain cookie'lari
INSTAGRAM_COUNT=$(echo "$COOKIE_CONTENT" | grep -i "instagram.com" | grep -v "^#" | wc -l)
echo "📊 Instagram.com cookie'lari: $INSTAGRAM_COUNT ta"

# Barcha cookie'lar
TOTAL_COOKIES=$(echo "$COOKIE_CONTENT" | grep -v "^#" | grep -v "^$" | wc -l)
echo "📊 Jami cookie'lar: $TOTAL_COOKIES ta"
echo ""

# 4. O'qish mumkinligi
echo "4️⃣ O'qish Mumkinligi"
echo "---------------------"
if [ -r "$COOKIES_PATH" ]; then
    echo "✅ Cookie fayl o'qish mumkin"
else
    echo "❌ Cookie fayl o'qish mumkin emas!"
fi
echo ""

# 5. yt-dlp tekshiruvi
echo "5️⃣ yt-dlp Tekshiruvi"
echo "---------------------"
if [ ! -f "$YT_DLP_PATH" ] || [ ! -x "$YT_DLP_PATH" ]; then
    echo "⚠️  yt-dlp topilmadi yoki ishlatib bo'lmaydi: $YT_DLP_PATH"
else
    YT_DLP_VERSION=$("$YT_DLP_PATH" --version 2>/dev/null || echo "N/A")
    echo "✅ yt-dlp mavjud (version: $YT_DLP_VERSION)"
fi
echo ""

# 6. Test yuklab olish
echo "6️⃣ Test Yuklab Olish"
echo "---------------------"
echo "URL: $TEST_URL"
echo "Buyruq: $YT_DLP_PATH --cookies \"$COOKIES_PATH\" --dump-json \"$TEST_URL\" 2>&1 | head -30"
echo ""

if [ -f "$YT_DLP_PATH" ] && [ -x "$YT_DLP_PATH" ]; then
    TEST_OUTPUT=$("$YT_DLP_PATH" --cookies "$COOKIES_PATH" --dump-json "$TEST_URL" 2>&1 | head -30)
    
    if echo "$TEST_OUTPUT" | grep -q "login required\|rate-limit\|Requested content is not available"; then
        echo "❌ Cookie'lar ishlamayapti - 'login required' xatosi"
        echo ""
        echo "⚠️  Muammo sabablari:"
        echo "   1. Cookie'lar eskirgan (sessionid expired)"
        echo "   2. Cookie'lar Instagram tomonidan bloklangan"
        echo "   3. IP yoki rate limit muammosi"
        echo ""
        echo "💡 Yechim:"
        echo "   1. Yangi cookie'lar export qiling (Chrome'da Instagram.com ga login)"
        echo "   2. Cookie faylni yangilang: $COOKIES_PATH"
        echo "   3. Tekshirish: ./CHECK_COOKIE_STATUS.sh"
    elif echo "$TEST_OUTPUT" | grep -q "\"id\"" || echo "$TEST_OUTPUT" | grep -q "\"title\""; then
        echo "✅ Cookie'lar ishlayapti - test muvaffaqiyatli!"
        echo ""
        echo "📝 Test natijasi:"
        echo "$TEST_OUTPUT" | head -10
    else
        echo "⚠️  Test natijasi noaniq"
        echo ""
        echo "📝 yt-dlp chiqishi:"
        echo "$TEST_OUTPUT"
    fi
else
    echo "⚠️  yt-dlp topilmadi, test o'tkazib bo'lmaydi"
fi

echo ""
echo "=============================================="
echo "✅ Tekshirish tugadi!"
echo ""
