#!/bin/bash

echo "🧪 Video Yuklash va Yuborish Test"
echo "=================================="
echo ""

cd ~/www/download.e-qarz.uz

# 1. Queue workers
echo "1️⃣ Queue Workers..."
WORKERS=$(ps aux | grep "artisan queue:work" | grep -v grep | grep -v "datacollector" | wc -l)
if [ "$WORKERS" -ge 1 ]; then
    echo "   ✅ $WORKERS worker ishlayapti"
else
    echo "   ❌ Worker ishlamayapti!"
fi
echo ""

# 2. Test video link (Instagram reel)
echo "2️⃣ Test Video Link..."
TEST_URL="https://www.instagram.com/reel/CrQ4w_NN3_5/"
echo "   Test URL: $TEST_URL"
echo ""

# 3. yt-dlp tekshirish
echo "3️⃣ yt-dlp Tekshirish..."
YT_DLP_PATH=$(php artisan tinker --execute="echo config('telegram.yt_dlp_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')

if [ -f "$YT_DLP_PATH" ] && [ -x "$YT_DLP_PATH" ]; then
    VERSION=$("$YT_DLP_PATH" --version 2>/dev/null || echo "unknown")
    echo "   ✅ yt-dlp mavjud: $YT_DLP_PATH (versiya: $VERSION)"
else
    echo "   ❌ yt-dlp topilmadi: $YT_DLP_PATH"
    exit 1
fi
echo ""

# 4. Test papkasini yaratish
echo "4️⃣ Test Papkasini Yaratish..."
TEST_DIR="storage/app/test_video_$(date +%s)"
mkdir -p "$TEST_DIR"
echo "   ✅ Test papkasi: $TEST_DIR"
echo ""

# 5. Media info olish
echo "5️⃣ Media Info Olish..."
echo "   Command: $YT_DLP_PATH --dump-json --no-warnings \"$TEST_URL\""
echo ""

JSON_OUTPUT=$("$YT_DLP_PATH" --dump-json --no-warnings "$TEST_URL" 2>&1)
EXIT_CODE=$?

if [ $EXIT_CODE -eq 0 ] || echo "$JSON_OUTPUT" | grep -q "{"; then
    echo "   ✅ Media info olish muvaffaqiyatli"
    
    # JSON'dan media type'ni aniqlash
    EXT=$(echo "$JSON_OUTPUT" | grep -o '"ext":"[^"]*"' | head -1 | cut -d'"' -f4)
    HAS_VIDEO=$(echo "$JSON_OUTPUT" | grep -q '"vcodec"' && echo "yes" || echo "no")
    
    echo "   📋 Extension: $EXT"
    echo "   📋 Has video: $HAS_VIDEO"
    
    if [ -n "$EXT" ] && [[ "$EXT" =~ ^(mp4|webm|mkv|mov)$ ]]; then
        echo "   ✅ Video format aniqlangan"
    else
        echo "   ⚠️  Video format aniqlanmadi yoki rasm format"
    fi
else
    echo "   ❌ Media info olish muvaffaqiyatsiz (exit code: $EXIT_CODE)"
    echo "   Output: ${JSON_OUTPUT:0:300}..."
fi
echo ""

# 6. Video yuklash testi
echo "6️⃣ Video Yuklash Testi..."
echo "   Command: $YT_DLP_PATH --no-playlist --no-warnings --quiet --format 'bestvideo+bestaudio/best' --output \"$TEST_DIR/%(title)s.%(ext)s\" \"$TEST_URL\""
echo ""

DOWNLOAD_OUTPUT=$("$YT_DLP_PATH" --no-playlist --no-warnings --quiet --format 'bestvideo+bestaudio/best' --output "$TEST_DIR/%(title)s.%(ext)s" "$TEST_URL" 2>&1)
DOWNLOAD_EXIT=$?

if [ $DOWNLOAD_EXIT -eq 0 ]; then
    DOWNLOADED_FILES=$(find "$TEST_DIR" -type f 2>/dev/null)
    if [ -n "$DOWNLOADED_FILES" ]; then
        FILE_COUNT=$(echo "$DOWNLOADED_FILES" | wc -l)
        echo "   ✅ Video yuklash muvaffaqiyatli (exit code: $DOWNLOAD_EXIT)"
        echo "   📁 Yuklangan fayllar ($FILE_COUNT ta):"
        echo "$DOWNLOADED_FILES" | while read -r file; do
            if [ -n "$file" ]; then
                SIZE=$(stat -f%z "$file" 2>/dev/null || stat -c%s "$file" 2>/dev/null || echo "0")
                SIZE_MB=$(echo "scale=2; $SIZE / 1024 / 1024" | bc 2>/dev/null || echo "0")
                EXT=$(echo "$file" | grep -o '\.[^.]*$' | tr -d '.')
                echo "      - $(basename "$file") (${SIZE_MB} MB, .${EXT})"
            fi
        done
        
        # Video fayllarini tekshirish
        VIDEO_FILES=$(echo "$DOWNLOADED_FILES" | grep -E '\.(mp4|webm|mkv|mov|avi|flv)$')
        if [ -n "$VIDEO_FILES" ]; then
            echo "   ✅ Video fayllar topildi:"
            echo "$VIDEO_FILES" | while read -r file; do
                if [ -n "$file" ]; then
                    echo "      - $(basename "$file")"
                fi
            done
        else
            echo "   ⚠️  Video fayllar topilmadi (faqat rasm fayllar bor)"
        fi
    else
        echo "   ⚠️  Video yuklash muvaffaqiyatli, lekin hech qanday fayl topilmadi"
    fi
else
    echo "   ❌ Video yuklash muvaffaqiyatsiz (exit code: $DOWNLOAD_EXIT)"
    echo "   Error: ${DOWNLOAD_OUTPUT:0:300}..."
fi
echo ""

# 7. Oxirgi loglar
echo "7️⃣ Oxirgi Video Download Loglari..."
tail -30 storage/logs/laravel.log | grep -E "DownloadMediaJob|ytDlpService->download|Sending videos|sendVideo|videoPath|is_video" | tail -10 | sed 's/^/   /'
echo ""

# 8. Tozalash
echo "8️⃣ Tozalash..."
rm -rf "$TEST_DIR"
echo "   ✅ Test papkasi o'chirildi"
echo ""

echo "===================================="
echo "✅ Test tugadi!"
echo ""
echo "💡 Agar video yuklanmayapti:"
echo "   1. Queue worker'lar ishlayaptimi? Tekshiring: ps aux | grep 'queue:work'"
echo "   2. Loglarni ko'ring: tail -f storage/logs/laravel.log | grep -E 'DownloadMediaJob|sendVideo'"
echo "   3. Video format to'g'rimi? Tekshiring: yt-dlp --dump-json URL"
echo ""
