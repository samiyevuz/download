#!/bin/bash

echo "🧪 JSON Extraction Test"
echo "======================"
echo ""

cd ~/www/download.e-qarz.uz

# 1. Cookie faylini tekshirish
echo "1️⃣ Cookie faylini tekshirish..."
COOKIE_FILE="storage/app/cookies/instagram_cookies.txt"
if [ -f "$COOKIE_FILE" ]; then
    ABSOLUTE_PATH=$(realpath "$COOKIE_FILE")
    echo "✅ Cookie fayli mavjud"
    echo "   Absolute path: $ABSOLUTE_PATH"
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
else
    echo "❌ yt-dlp topilmadi yoki executable emas: $YTDLP_PATH"
    exit 1
fi
echo ""

# 3. Test URL
TEST_URL="${1:-https://www.instagram.com/p/DThRA3DDLSd/}"
echo "3️⃣ Test URL: $TEST_URL"
echo ""

# 4. Test: --dump-json with --ignore-errors
echo "4️⃣ Test: --dump-json with --ignore-errors"
echo "----------------------------------------"

"$YTDLP_PATH" \
    --dump-json \
    --no-playlist \
    --no-warnings \
    --quiet \
    --ignore-errors \
    --cookies "$ABSOLUTE_PATH" \
    --user-agent "Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1" \
    --referer "https://www.instagram.com/" \
    --extractor-args "instagram:skip_auth=False" \
    "$TEST_URL" 2>&1 | head -50

EXIT_CODE=$?
echo ""
echo "Exit code: $EXIT_CODE"
echo ""

# 5. JSON output'ni alohida olish
echo "5️⃣ JSON output'ni alohida olish (stdout)"
echo "----------------------------------------"
JSON_OUTPUT=$("$YTDLP_PATH" \
    --dump-json \
    --no-playlist \
    --no-warnings \
    --quiet \
    --ignore-errors \
    --cookies "$ABSOLUTE_PATH" \
    --user-agent "Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1" \
    --referer "https://www.instagram.com/" \
    --extractor-args "instagram:skip_auth=False" \
    "$TEST_URL" 2>/dev/null)

if [ ! -z "$JSON_OUTPUT" ]; then
    echo "✅ JSON output mavjud (${#JSON_OUTPUT} bytes)"
    
    # JSON'ni parse qilish
    if echo "$JSON_OUTPUT" | python3 -m json.tool > /dev/null 2>&1; then
        echo "✅ JSON format to'g'ri"
        
        # Key'larni ko'rsatish
        echo ""
        echo "📋 JSON keys:"
        echo "$JSON_OUTPUT" | python3 -c "import sys, json; data = json.load(sys.stdin); print('\n'.join(data.keys()))" 2>/dev/null | head -20
        
        # Thumbnail tekshirish
        echo ""
        echo "🖼️  Thumbnail:"
        echo "$JSON_OUTPUT" | python3 -c "import sys, json; data = json.load(sys.stdin); print(data.get('thumbnail', 'N/A'))" 2>/dev/null
        
        # URL tekshirish
        echo ""
        echo "🔗 URL:"
        echo "$JSON_OUTPUT" | python3 -c "import sys, json; data = json.load(sys.stdin); print(data.get('url', 'N/A')[:100] if data.get('url') else 'N/A')" 2>/dev/null
        
        # Formats tekshirish
        echo ""
        echo "📦 Formats count:"
        echo "$JSON_OUTPUT" | python3 -c "import sys, json; data = json.load(sys.stdin); print(len(data.get('formats', [])))" 2>/dev/null
    else
        echo "❌ JSON format noto'g'ri"
        echo "First 500 chars:"
        echo "$JSON_OUTPUT" | head -c 500
    fi
else
    echo "❌ JSON output bo'sh"
fi
echo ""

# 6. Error output'ni ko'rsatish
echo "6️⃣ Error output (stderr)"
echo "----------------------------------------"
ERROR_OUTPUT=$("$YTDLP_PATH" \
    --dump-json \
    --no-playlist \
    --no-warnings \
    --quiet \
    --ignore-errors \
    --cookies "$ABSOLUTE_PATH" \
    --user-agent "Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1" \
    --referer "https://www.instagram.com/" \
    --extractor-args "instagram:skip_auth=False" \
    "$TEST_URL" 2>&1 >/dev/null)

if [ ! -z "$ERROR_OUTPUT" ]; then
    echo "$ERROR_OUTPUT" | head -20
else
    echo "✅ Hech qanday xato yo'q"
fi
echo ""

echo "===================================="
echo "✅ Test tugadi!"
echo ""
