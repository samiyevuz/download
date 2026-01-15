#!/bin/bash

echo "🔍 Download muammosini tekshirish..."
echo ""

# 1. yt-dlp o'rnatilganligini tekshirish
echo "1️⃣ yt-dlp o'rnatilganligini tekshirish..."
if command -v yt-dlp &> /dev/null; then
    YT_DLP_VERSION=$(yt-dlp --version 2>&1)
    echo "✅ yt-dlp o'rnatilgan: $YT_DLP_VERSION"
else
    echo "❌ yt-dlp topilmadi!"
    echo "   O'rnatish: sudo pip3 install yt-dlp"
fi
echo ""

# 2. yt-dlp'ni test qilish
echo "2️⃣ yt-dlp'ni test qilish..."
if command -v yt-dlp &> /dev/null; then
    echo "   Test qilish (dry-run)..."
    yt-dlp --version 2>&1 | head -1
else
    echo "   yt-dlp topilmadi, test qilib bo'lmaydi"
fi
echo ""

# 3. Download job loglarini ko'rish
echo "3️⃣ Download job loglarini ko'rish..."
echo "=== Oxirgi Laravel loglar (download bilan bog'liq) ==="
tail -n 100 storage/logs/laravel.log | grep -i "download\|yt-dlp\|media" | tail -20
echo ""

# 4. Queue job loglarini ko'rish
echo "4️⃣ Queue job loglarini ko'rish..."
echo "=== Downloads Worker Log ==="
tail -n 30 storage/logs/queue-downloads.log 2>/dev/null || echo "Log topilmadi"
echo ""

# 5. Workerlar holatini tekshirish
echo "5️⃣ Workerlar holatini tekshirish..."
ps aux | grep "download.e-qarz.uz.*queue:work" | grep -v grep
echo ""

# 6. Queue'da joblar borligini tekshirish
echo "6️⃣ Queue'da joblar borligini tekshirish..."
if command -v redis-cli &> /dev/null; then
    DOWNLOADS_JOBS=$(redis-cli LLEN queues:downloads 2>/dev/null || echo "0")
    TELEGRAM_JOBS=$(redis-cli LLEN queues:telegram 2>/dev/null || echo "0")
    echo "   Downloads queue: $DOWNLOADS_JOBS job(s)"
    echo "   Telegram queue: $TELEGRAM_JOBS job(s)"
else
    echo "   redis-cli topilmadi"
fi
echo ""

echo "✅ Tekshirish tugadi!"
echo ""
echo "💡 Agar yt-dlp o'rnatilmagan bo'lsa:"
echo "   sudo pip3 install yt-dlp"
echo ""
echo "💡 Agar yt-dlp o'rnatilgan bo'lsa, lekin download ishlamasa:"
echo "   tail -f storage/logs/laravel.log"
echo "   (Yangi link yuborib, real-time loglarni ko'ring)"
