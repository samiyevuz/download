#!/bin/bash

echo "🧪 Instagram Rasm Yuklash Test"
echo "=============================="
echo ""

cd ~/www/download.e-qarz.uz

# 1. Test URL
TEST_URL="https://www.instagram.com/p/DThRA3DDLSd/"

echo "📝 Test URL: $TEST_URL"
echo ""

# 2. yt-dlp tekshirish
echo "1️⃣ yt-dlp tekshirish..."
YT_DLP_PATH=$(php artisan tinker --execute="echo config('telegram.yt_dlp_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')

if [ -f "$YT_DLP_PATH" ] && [ -x "$YT_DLP_PATH" ]; then
    VERSION=$("$YT_DLP_PATH" --version 2>/dev/null || echo "unknown")
    echo "   ✅ yt-dlp mavjud: $YT_DLP_PATH (versiya: $VERSION)"
else
    echo "   ❌ yt-dlp topilmadi: $YT_DLP_PATH"
    exit 1
fi
echo ""

# 3. Cookies tekshirish
echo "2️⃣ Cookies tekshirish..."
COOKIE_PATH=$(php artisan tinker --execute="echo config('telegram.instagram_cookies_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')

if [ -f "$COOKIE_PATH" ]; then
    COOKIE_SIZE=$(stat -f%z "$COOKIE_PATH" 2>/dev/null || stat -c%s "$COOKIE_PATH" 2>/dev/null || echo "0")
    echo "   ✅ Cookie fayli mavjud: $COOKIE_PATH (hajm: ${COOKIE_SIZE}B)"
    
    if grep -q "sessionid" "$COOKIE_PATH" 2>/dev/null; then
        echo "   ✅ sessionid cookie topildi"
    else
        echo "   ⚠️  sessionid cookie topilmadi"
    fi
else
    echo "   ⚠️  Cookie fayli topilmadi: $COOKIE_PATH"
fi
echo ""

# 4. Test papkasini yaratish
echo "3️⃣ Test papkasini yaratish..."
TEST_DIR="storage/app/test_instagram_$(date +%s)"
mkdir -p "$TEST_DIR"
echo "   ✅ Test papkasi: $TEST_DIR"
echo ""

# 5. Media info olish
echo "4️⃣ Media info olish..."
echo "   Command: $YT_DLP_PATH --dump-json --no-warnings --cookies \"$COOKIE_PATH\" \"$TEST_URL\""
echo ""

JSON_OUTPUT=$("$YT_DLP_PATH" --dump-json --no-warnings --cookies "$COOKIE_PATH" "$TEST_URL" 2>&1)
EXIT_CODE=$?

if [ $EXIT_CODE -eq 0 ] || echo "$JSON_OUTPUT" | grep -q "{"; then
    echo "   ✅ Media info olish muvaffaqiyatli"
    
    # JSON'dan rasm URL'ini extract qilish
    IMAGE_URL=$(echo "$JSON_OUTPUT" | grep -o '"url":"[^"]*"' | head -1 | cut -d'"' -f4)
    THUMBNAIL=$(echo "$JSON_OUTPUT" | grep -o '"thumbnail":"[^"]*"' | head -1 | cut -d'"' -f4)
    
    if [ -n "$IMAGE_URL" ]; then
        echo "   ✅ Rasm URL topildi: ${IMAGE_URL:0:80}..."
    elif [ -n "$THUMBNAIL" ]; then
        echo "   ✅ Thumbnail URL topildi: ${THUMBNAIL:0:80}..."
    else
        echo "   ⚠️  Rasm URL topilmadi, lekin JSON mavjud"
    fi
else
    echo "   ⚠️  Media info olish muvaffaqiyatsiz (exit code: $EXIT_CODE)"
    echo "   Output: ${JSON_OUTPUT:0:200}..."
fi
echo ""

# 6. Media yuklash testi
echo "5️⃣ Media yuklash testi (cookies bilan, 'best' format)..."
echo "   Command: $YT_DLP_PATH --no-playlist --no-warnings --quiet --cookies \"$COOKIE_PATH\" --format 'best' --output \"$TEST_DIR/%(title)s.%(ext)s\" \"$TEST_URL\""
echo ""

DOWNLOAD_OUTPUT=$("$YT_DLP_PATH" --no-playlist --no-warnings --quiet --cookies "$COOKIE_PATH" --format 'best' --output "$TEST_DIR/%(title)s.%(ext)s" "$TEST_URL" 2>&1)
DOWNLOAD_EXIT=$?

if [ $DOWNLOAD_EXIT -eq 0 ]; then
    DOWNLOADED_FILES=$(find "$TEST_DIR" -type f 2>/dev/null)
    if [ -n "$DOWNLOADED_FILES" ]; then
        FILE_COUNT=$(echo "$DOWNLOADED_FILES" | wc -l)
        echo "   ✅ Media yuklash muvaffaqiyatli (exit code: $DOWNLOAD_EXIT)"
        echo "   📁 Yuklangan fayllar ($FILE_COUNT ta):"
        echo "$DOWNLOADED_FILES" | while read -r file; do
            if [ -n "$file" ]; then
                SIZE=$(stat -f%z "$file" 2>/dev/null || stat -c%s "$file" 2>/dev/null || echo "0")
                SIZE_MB=$(echo "scale=2; $SIZE / 1024 / 1024" | bc 2>/dev/null || echo "0")
                echo "      - $(basename "$file") (${SIZE_MB} MB)"
            fi
        done
    else
        echo "   ⚠️  Media yuklash muvaffaqiyatli, lekin hech qanday fayl topilmadi"
    fi
else
    echo "   ❌ Media yuklash muvaffaqiyatsiz (exit code: $DOWNLOAD_EXIT)"
    echo "   Error: ${DOWNLOAD_OUTPUT:0:300}..."
fi
echo ""

# 7. Tozalash
echo "6️⃣ Tozalash..."
rm -rf "$TEST_DIR"
echo "   ✅ Test papkasi o'chirildi"
echo ""

echo "===================================="
echo "✅ Test tugadi!"
echo ""
echo "💡 Agar rasm yuklanmasa:"
echo "   1. Cookie faylini yangilang"
echo "   2. yt-dlp'ni yangilang: $YT_DLP_PATH -U"
echo "   3. Loglarni tekshiring: tail -f storage/logs/laravel.log | grep -E 'Instagram image|downloadImageFromUrl'"
echo ""
