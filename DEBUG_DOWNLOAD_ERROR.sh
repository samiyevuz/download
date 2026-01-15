#!/bin/bash

echo "🔍 Download xatosini topish..."
echo ""

# 1. Oxirgi to'liq xatolarni ko'rish
echo "1️⃣ Oxirgi to'liq xatolarni ko'rish..."
tail -n 500 storage/logs/laravel.log | grep -B 5 -A 15 "DownloadMediaJob\|yt-dlp\|download\|Exception" | tail -100
echo ""

# 2. yt-dlp'ni to'g'ridan-to'g'ri test qilish
echo "2️⃣ yt-dlp'ni to'g'ridan-to'g'ri test qilish..."
TEST_URL="https://www.instagram.com/p/DTIb5j2jDrm/"
YT_DLP_PATH=$(grep "^YT_DLP_PATH=" .env | tail -1 | cut -d '=' -f2)

echo "   yt-dlp path: $YT_DLP_PATH"
echo "   Test URL: $TEST_URL"
echo ""

if [ -f "$YT_DLP_PATH" ] || command -v "$YT_DLP_PATH" &> /dev/null; then
    echo "   yt-dlp'ni test qilish (dry-run)..."
    $YT_DLP_PATH --version
    echo ""
    echo "   Instagram link'ni test qilish..."
    timeout 10 $YT_DLP_PATH --dry-run "$TEST_URL" 2>&1 | head -20
else
    echo "   ❌ yt-dlp topilmadi: $YT_DLP_PATH"
fi
echo ""

# 3. Permission'larni tekshirish
echo "3️⃣ Permission'larni tekshirish..."
ls -la ~/bin/yt-dlp 2>/dev/null || ls -la /var/www/sardor/data/bin/yt-dlp 2>/dev/null || echo "yt-dlp topilmadi"
echo ""

# 4. Temp directory permission'lari
echo "4️⃣ Temp directory permission'lari..."
ls -la storage/app/temp/downloads/ 2>/dev/null | head -5 || echo "Temp directory topilmadi"
echo ""

echo "✅ Tekshirish tugadi!"
