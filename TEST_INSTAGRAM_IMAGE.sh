#!/bin/bash

echo "🧪 Instagram Rasm Yuklash Test"
echo "=============================="
echo ""

cd ~/www/download.e-qarz.uz

# Test URL
TEST_URL="https://www.instagram.com/p/DTjumuHiMr9/?utm_source=ig_web_copy_link&igsh=NTc4MTIwNjQ2YQ=="

echo "📝 Test URL: $TEST_URL"
echo ""

# 1. yt-dlp tekshirish
echo "1️⃣ yt-dlp tekshirish..."
YT_DLP_PATH=$(php artisan tinker --execute="echo config('telegram.yt_dlp_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1)

if [ ! -f "$YT_DLP_PATH" ] || [ ! -x "$YT_DLP_PATH" ]; then
    echo "❌ yt-dlp topilmadi yoki ishlamayapti: $YT_DLP_PATH"
    exit 1
fi

VERSION=$("$YT_DLP_PATH" --version 2>/dev/null || echo "unknown")
echo "✅ yt-dlp mavjud: $YT_DLP_PATH (versiya: $VERSION)"
echo ""

# 2. Cookies tekshirish
echo "2️⃣ Cookies tekshirish..."
COOKIES_PATH=$(php artisan tinker --execute="echo config('telegram.instagram_cookies_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1)

if [ -f "$COOKIES_PATH" ] && [ -s "$COOKIES_PATH" ]; then
    COOKIES_SIZE=$(du -h "$COOKIES_PATH" | cut -f1)
    echo "✅ Cookies fayli mavjud: $COOKIES_PATH (hajm: $COOKIES_SIZE)"
    
    if grep -q "sessionid" "$COOKIES_PATH" 2>/dev/null; then
        echo "✅ sessionid cookie topildi"
    else
        echo "⚠️  sessionid cookie topilmadi"
    fi
else
    echo "⚠️  Cookies fayli mavjud emas yoki bo'sh"
    COOKIES_PATH=""
fi
echo ""

# 3. Test papkasini yaratish
echo "3️⃣ Test papkasini yaratish..."
TEST_DIR="storage/app/test_instagram_$(date +%s)"
mkdir -p "$TEST_DIR"
echo "✅ Test papkasi: $TEST_DIR"
echo ""

# 4. Media info olish
echo "4️⃣ Media info olish..."
echo "   Command: $YT_DLP_PATH --dump-json --no-warnings $([ -n "$COOKIES_PATH" ] && echo "--cookies $COOKIES_PATH") \"$TEST_URL\""
echo ""

MEDIA_INFO=$("$YT_DLP_PATH" --dump-json --no-warnings $([ -n "$COOKIES_PATH" ] && echo "--cookies $COOKIES_PATH") "$TEST_URL" 2>&1)

if echo "$MEDIA_INFO" | grep -q '"id"\|"title"\|"ext"'; then
    echo "✅ Media info muvaffaqiyatli olingan"
    
    # Extract media type
    if echo "$MEDIA_INFO" | grep -q '"ext".*"jpg"\|"ext".*"jpeg"\|"ext".*"png"\|"ext".*"webp"'; then
        echo "   📸 Media turi: RASM"
    elif echo "$MEDIA_INFO" | grep -q '"ext".*"mp4"\|"ext".*"webm"'; then
        echo "   🎥 Media turi: VIDEO"
    else
        echo "   ❓ Media turi: NOMA'LUM"
    fi
    
    # Show first few lines
    echo "$MEDIA_INFO" | head -20 | sed 's/^/   /'
else
    echo "❌ Media info olish muvaffaqiyatsiz"
    echo "$MEDIA_INFO" | head -10 | sed 's/^/   /'
    echo ""
    echo "⚠️  Lekin bu rasm yuklashga to'sqinlik qilmaydi, davom etamiz..."
fi
echo ""

# 5. Rasm yuklash testi (cookies bilan)
if [ -n "$COOKIES_PATH" ]; then
    echo "5️⃣ Rasm yuklash testi (cookies bilan)..."
    echo "   Command: $YT_DLP_PATH --no-playlist --no-warnings --quiet --cookies \"$COOKIES_PATH\" --format 'best[ext=jpg]/best[ext=jpeg]/best[ext=png]/best[ext=webp]/best' --output \"$TEST_DIR/%(title)s.%(ext)s\" \"$TEST_URL\""
    echo ""
    
    DOWNLOAD_OUTPUT=$("$YT_DLP_PATH" --no-playlist --no-warnings --quiet \
        --cookies "$COOKIES_PATH" \
        --format 'best[ext=jpg]/best[ext=jpeg]/best[ext=png]/best[ext=webp]/best' \
        --output "$TEST_DIR/%(title)s.%(ext)s" \
        "$TEST_URL" 2>&1)
    
    EXIT_CODE=$?
    
    if [ $EXIT_CODE -eq 0 ]; then
        # Check if files were downloaded
        FILES=$(find "$TEST_DIR" -type f \( -name "*.jpg" -o -name "*.jpeg" -o -name "*.png" -o -name "*.webp" \) 2>/dev/null)
        
        if [ -n "$FILES" ]; then
            FILE_COUNT=$(echo "$FILES" | wc -l)
            echo "✅ Rasm muvaffaqiyatli yuklab olindi! ($FILE_COUNT fayl)"
            echo "$FILES" | while read -r file; do
                SIZE=$(du -h "$file" | cut -f1)
                echo "   📄 $(basename "$file") ($SIZE)"
            done
        else
            echo "⚠️  Yuklab olish muvaffaqiyatli, lekin fayllar topilmadi"
            echo "$DOWNLOAD_OUTPUT" | tail -5 | sed 's/^/   /'
        fi
    else
        echo "❌ Rasm yuklash muvaffaqiyatsiz (exit code: $EXIT_CODE)"
        echo "$DOWNLOAD_OUTPUT" | tail -10 | sed 's/^/   /'
    fi
    echo ""
fi

# 6. Rasm yuklash testi (cookies'siz)
echo "6️⃣ Rasm yuklash testi (cookies'siz)..."
echo "   Command: $YT_DLP_PATH --no-playlist --no-warnings --quiet --format 'best[ext=jpg]/best[ext=jpeg]/best[ext=png]/best[ext=webp]/best' --output \"$TEST_DIR/%(title)s_no_cookies.%(ext)s\" \"$TEST_URL\""
echo ""

DOWNLOAD_OUTPUT=$("$YT_DLP_PATH" --no-playlist --no-warnings --quiet \
    --format 'best[ext=jpg]/best[ext=jpeg]/best[ext=png]/best[ext=webp]/best' \
    --output "$TEST_DIR/%(title)s_no_cookies.%(ext)s" \
    "$TEST_URL" 2>&1)

EXIT_CODE=$?

if [ $EXIT_CODE -eq 0 ]; then
    # Check if files were downloaded
    FILES=$(find "$TEST_DIR" -type f \( -name "*_no_cookies.*" \) 2>/dev/null)
    
    if [ -n "$FILES" ]; then
        FILE_COUNT=$(echo "$FILES" | wc -l)
        echo "✅ Rasm muvaffaqiyatli yuklab olindi (cookies'siz)! ($FILE_COUNT fayl)"
        echo "$FILES" | while read -r file; do
            SIZE=$(du -h "$file" | cut -f1)
            echo "   📄 $(basename "$file") ($SIZE)"
        done
    else
        echo "⚠️  Yuklab olish muvaffaqiyatli, lekin fayllar topilmadi"
        echo "$DOWNLOAD_OUTPUT" | tail -5 | sed 's/^/   /'
    fi
else
    echo "❌ Rasm yuklash muvaffaqiyatsiz (exit code: $EXIT_CODE)"
    echo "$DOWNLOAD_OUTPUT" | tail -10 | sed 's/^/   /'
fi
echo ""

# 7. Xulosa
echo "===================================="
echo "📊 Test Xulosasi:"
echo ""

ALL_FILES=$(find "$TEST_DIR" -type f 2>/dev/null | wc -l)
if [ "$ALL_FILES" -gt 0 ]; then
    echo "✅ Test muvaffaqiyatli! $ALL_FILES fayl yuklab olindi"
    echo ""
    echo "📁 Test papkasi: $TEST_DIR"
    echo "   Testdan keyin o'chirish: rm -rf $TEST_DIR"
else
    echo "❌ Test muvaffaqiyatsiz! Hech qanday fayl yuklab olinmadi"
    echo ""
    echo "💡 Muammo sabablari:"
    echo "   1. Cookies eskirgan yoki noto'g'ri"
    echo "   2. Instagram API o'zgargan"
    echo "   3. Rate limiting"
    echo "   4. Kontent maxfiy yoki mavjud emas"
    echo ""
    echo "🔧 Yechim:"
    echo "   1. Cookies faylini yangilang"
    echo "   2. yt-dlp'ni yangilang: $YT_DLP_PATH -U"
    echo "   3. Bir necha daqiqa kutib, qayta urinib ko'ring"
fi
echo ""
