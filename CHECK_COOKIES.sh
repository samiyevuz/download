#!/bin/bash

echo "🔍 Instagram cookie faylni tekshirish..."
echo ""

COOKIES_PATH="/var/www/sardor/data/www/download.e-qarz.uz/storage/app/cookies/instagram_cookies.txt"

if [ ! -f "$COOKIES_PATH" ]; then
    echo "❌ Cookie fayl topilmadi: $COOKIES_PATH"
    exit 1
fi

echo "✅ Cookie fayl mavjud: $COOKIES_PATH"
echo "📏 Fayl hajmi: $(du -h "$COOKIES_PATH" | cut -f1)"
echo ""

# Netscape format tekshiruvi
if head -1 "$COOKIES_PATH" | grep -q "# Netscape HTTP Cookie File"; then
    echo "✅ Netscape format to'g'ri"
else
    echo "⚠️  Netscape format bo'lmasligi mumkin (first line check)"
fi

# sessionid tekshiruvi
if grep -q "sessionid" "$COOKIES_PATH"; then
    echo "✅ sessionid cookie mavjud"
    SESSIONID_COUNT=$(grep -c "sessionid" "$COOKIES_PATH")
    echo "   sessionid qatorlari: $SESSIONID_COUNT"
else
    echo "❌ sessionid cookie topilmadi!"
fi

# csrftoken tekshiruvi
if grep -q "csrftoken" "$COOKIES_PATH"; then
    echo "✅ csrftoken cookie mavjud"
else
    echo "⚠️  csrftoken cookie topilmadi"
fi

# instagram.com domain cookie'lari
INSTAGRAM_COUNT=$(grep -i "instagram.com" "$COOKIES_PATH" | grep -v "^#" | wc -l)
echo "📊 Instagram.com cookie'lari: $INSTAGRAM_COUNT ta"

# Cookie'larni o'qish mumkinligi
if [ -r "$COOKIES_PATH" ]; then
    echo "✅ Cookie fayl o'qish mumkin"
else
    echo "❌ Cookie fayl o'qish mumkin emas!"
fi

echo ""
echo "🧪 yt-dlp bilan test qilish..."
echo ""

# Test URL
TEST_URL="https://www.instagram.com/reel/DTlK_pNDEeb/"

YT_DLP_PATH="/var/www/sardor/data/bin/yt-dlp"

echo "Buyruq:"
echo "$YT_DLP_PATH --cookies \"$COOKIES_PATH\" --dump-json \"$TEST_URL\" 2>&1 | head -30"
echo ""

$YT_DLP_PATH --cookies "$COOKIES_PATH" --dump-json "$TEST_URL" 2>&1 | head -30

echo ""
echo "✅ Tekshirish tugadi!"
