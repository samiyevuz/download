#!/bin/bash

echo "🧪 Cookie va yt-dlp Test"
echo "========================"
echo ""

cd ~/www/download.e-qarz.uz

# 1. Cookie faylini tekshirish
echo "1️⃣ Cookie faylini tekshirish..."
COOKIE_FILE="storage/app/cookies/instagram_cookies.txt"
if [ -f "$COOKIE_FILE" ]; then
    ABSOLUTE_PATH=$(realpath "$COOKIE_FILE")
    echo "✅ Cookie fayli mavjud"
    echo "   Absolute path: $ABSOLUTE_PATH"
    echo "   Fayl hajmi: $(stat -f%z "$COOKIE_FILE" 2>/dev/null || stat -c%s "$COOKIE_FILE" 2>/dev/null) bytes"
    
    if grep -q "sessionid" "$COOKIE_FILE" 2>/dev/null; then
        SESSIONID=$(grep "sessionid" "$COOKIE_FILE" | head -1 | awk '{print $NF}' | cut -d'%' -f1)
        echo "   ✅ sessionid topildi: ${SESSIONID:0:20}..."
    else
        echo "   ❌ sessionid topilmadi!"
    fi
else
    echo "❌ Cookie fayli topilmadi: $COOKIE_FILE"
    exit 1
fi
echo ""

# 2. yt-dlp tekshirish
echo "2️⃣ yt-dlp tekshirish..."
YTDLP_PATH="/var/www/sardor/data/bin/yt-dlp"
if [ -f "$YTDLP_PATH" ] && [ -x "$YTDLP_PATH" ]; then
    echo "✅ yt-dlp mavjud: $YTDLP_PATH"
    VERSION=$("$YTDLP_PATH" --version 2>/dev/null || echo "unknown")
    echo "   Versiya: $VERSION"
else
    echo "❌ yt-dlp topilmadi yoki executable emas: $YTDLP_PATH"
    exit 1
fi
echo ""

# 3. Test URL
TEST_URL="${1:-https://www.instagram.com/p/DThRA3DDLSd/}"
echo "3️⃣ Test URL: $TEST_URL"
echo ""

# 4. Test 1: Cookie bilan --write-thumbnail
echo "4️⃣ Test 1: Cookie bilan --write-thumbnail"
echo "----------------------------------------"
TEMP_DIR=$(mktemp -d)
echo "   Temp dir: $TEMP_DIR"

"$YTDLP_PATH" \
    --no-playlist \
    --no-warnings \
    --quiet \
    --cookies "$ABSOLUTE_PATH" \
    --user-agent "Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1" \
    --referer "https://www.instagram.com/" \
    --extractor-args "instagram:skip_auth=False" \
    --output "$TEMP_DIR/%(title)s.%(ext)s" \
    --write-thumbnail \
    --skip-download \
    "$TEST_URL" 2>&1 | head -20

if [ $? -eq 0 ]; then
    FILES=$(find "$TEMP_DIR" -type f 2>/dev/null | wc -l)
    if [ "$FILES" -gt 0 ]; then
        echo "   ✅ Muvaffaqiyatli! $FILES fayl topildi"
        find "$TEMP_DIR" -type f -exec ls -lh {} \;
    else
        echo "   ⚠️  Hech qanday fayl topilmadi"
    fi
else
    echo "   ❌ Xato!"
fi

rm -rf "$TEMP_DIR"
echo ""

# 5. Test 2: Cookie bilan to'g'ridan-to'g'ri yuklash
echo "5️⃣ Test 2: Cookie bilan to'g'ridan-to'g'ri yuklash"
echo "----------------------------------------"
TEMP_DIR2=$(mktemp -d)
echo "   Temp dir: $TEMP_DIR2"

"$YTDLP_PATH" \
    --no-playlist \
    --no-warnings \
    --quiet \
    --cookies "$ABSOLUTE_PATH" \
    --user-agent "Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1" \
    --referer "https://www.instagram.com/" \
    --extractor-args "instagram:skip_auth=False" \
    --output "$TEMP_DIR2/%(title)s.%(ext)s" \
    "$TEST_URL" 2>&1 | head -20

if [ $? -eq 0 ]; then
    FILES=$(find "$TEMP_DIR2" -type f 2>/dev/null | wc -l)
    if [ "$FILES" -gt 0 ]; then
        echo "   ✅ Muvaffaqiyatli! $FILES fayl topildi"
        find "$TEMP_DIR2" -type f -exec ls -lh {} \;
    else
        echo "   ⚠️  Hech qanday fayl topilmadi"
    fi
else
    echo "   ❌ Xato!"
fi

rm -rf "$TEMP_DIR2"
echo ""

# 6. Test 3: Cookie'siz (fallback)
echo "6️⃣ Test 3: Cookie'siz (fallback)"
echo "----------------------------------------"
TEMP_DIR3=$(mktemp -d)
echo "   Temp dir: $TEMP_DIR3"

"$YTDLP_PATH" \
    --no-playlist \
    --no-warnings \
    --quiet \
    --user-agent "Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1" \
    --referer "https://www.instagram.com/" \
    --output "$TEMP_DIR3/%(title)s.%(ext)s" \
    "$TEST_URL" 2>&1 | head -20

if [ $? -eq 0 ]; then
    FILES=$(find "$TEMP_DIR3" -type f 2>/dev/null | wc -l)
    if [ "$FILES" -gt 0 ]; then
        echo "   ✅ Muvaffaqiyatli! $FILES fayl topildi"
        find "$TEMP_DIR3" -type f -exec ls -lh {} \;
    else
        echo "   ⚠️  Hech qanday fayl topilmadi"
    fi
else
    echo "   ❌ Xato!"
fi

rm -rf "$TEMP_DIR3"
echo ""

echo "===================================="
echo "✅ Test tugadi!"
echo ""
echo "💡 Agar cookie bilan test muvaffaqiyatsiz bo'lsa:"
echo "   1. Cookie eskirgan bo'lishi mumkin - yangi cookie export qiling"
echo "   2. Cookie format noto'g'ri bo'lishi mumkin - Netscape format tekshiring"
echo "   3. Instagram API o'zgargan bo'lishi mumkin - yt-dlp'ni yangilang: yt-dlp -U"
echo ""
