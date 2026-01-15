#!/bin/bash

echo "🔍 To'liq xatoni topish..."
echo ""

# 1. Oxirgi to'liq xatolarni ko'rish
echo "1️⃣ Oxirgi to'liq xatolarni ko'rish..."
tail -n 500 storage/logs/laravel.log | grep -B 3 "DownloadMediaJob\|yt-dlp\|download\|Exception\|Error" | tail -100
echo ""

# 2. yt-dlp'ni to'g'ridan-to'g'ri test qilish
echo "2️⃣ yt-dlp'ni to'g'ridan-to'g'ri test qilish..."
TEST_URL="https://www.instagram.com/p/DTIb5j2jDrm/"
YT_DLP_PATH="/var/www/sardor/data/bin/yt-dlp"

echo "   yt-dlp path: $YT_DLP_PATH"
echo "   Test URL: $TEST_URL"
echo ""

if [ -f "$YT_DLP_PATH" ]; then
    echo "   ✅ yt-dlp fayli mavjud"
    ls -la "$YT_DLP_PATH"
    echo ""
    echo "   yt-dlp versiyasi:"
    "$YT_DLP_PATH" --version
    echo ""
    echo "   Instagram link'ni test qilish (dry-run, 10 soniya)..."
    timeout 10 "$YT_DLP_PATH" --dry-run "$TEST_URL" 2>&1 | head -30
else
    echo "   ❌ yt-dlp topilmadi: $YT_DLP_PATH"
    echo "   Qidirilmoqda..."
    find /var/www/sardor -name "yt-dlp" -type f 2>/dev/null | head -5
fi
echo ""

# 3. Oxirgi failed job'ni ko'rish
echo "3️⃣ Oxirgi failed job'ni ko'rish..."
php artisan queue:failed --json 2>/dev/null | python3 -m json.tool 2>/dev/null | head -50 || php artisan queue:failed | head -30
echo ""

echo "✅ Tekshirish tugadi!"
