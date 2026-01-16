#!/bin/bash

echo "🔧 Instagram Image Sending Fix"
echo "=============================="
echo ""

cd ~/www/download.e-qarz.uz

# 1. PHP syntax tekshirish
echo "1️⃣ PHP syntax tekshirish..."
php -l app/Services/TelegramService.php
if [ $? -ne 0 ]; then
    echo "❌ PHP syntax xatosi bor!"
    exit 1
fi
echo "✅ PHP syntax to'g'ri"
echo ""

# 2. GD extension tekshirish
echo "2️⃣ GD extension tekshirish..."
php -r "if (extension_loaded('gd')) { echo '✅ GD extension mavjud\n'; if (function_exists('imagecreatefromwebp')) { echo '✅ WebP support mavjud\n'; } else { echo '⚠️  WebP support yo\'q\n'; } } else { echo '❌ GD extension yo\'q - WebP conversion ishlamaydi!\n'; }"
echo ""

# 3. Config yangilash
echo "3️⃣ Config yangilash..."
php artisan config:clear
php artisan config:cache
echo "✅ Config yangilandi"
echo ""

# 4. Workerlarni qayta ishga tushirish
echo "4️⃣ Workerlarni qayta ishga tushirish..."
pkill -9 -f "artisan queue:work" 2>/dev/null
sleep 2

nohup php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
DOWNLOAD_PID=$!

nohup php artisan queue:work redis --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &
TELEGRAM_PID=$!

sleep 3
echo "✅ Workerlarni ishga tushirdim (PIDs: $DOWNLOAD_PID, $TELEGRAM_PID)"
echo ""

# 5. Tekshirish
echo "5️⃣ Workerlarni tekshirish..."
WORKERS=$(ps aux | grep "artisan queue:work redis" | grep -v grep | grep -v "datacollector" | wc -l)
if [ "$WORKERS" -ge 2 ]; then
    echo "✅ $WORKERS worker ishlayapti"
    ps aux | grep "artisan queue:work redis" | grep -v grep | grep -v "datacollector" | awk '{print "   PID:", $2, "Queue:", $NF}'
else
    echo "⚠️  Faqat $WORKERS worker ishlayapti"
fi
echo ""

echo "===================================="
echo "✅ Tugadi!"
echo ""
echo "🔧 Tuzatilgan muammolar:"
echo "   ✨ WebP to JPG conversion qo'shildi"
echo "   ✨ File handle ishlatiladi (memory efficient)"
echo "   ✨ MIME type verification qo'shildi"
echo "   ✨ File size validation qo'shildi (10MB limit)"
echo "   ✨ sendMediaGroup uchun WebP conversion qo'shildi"
echo ""
echo "🧪 Test qiling:"
echo "   Botga Instagram rasm linki yuboring"
echo "   Loglarni kuzatib turing:"
echo "   tail -f storage/logs/laravel.log | grep -E 'WebP converted|sendPhoto|sendMediaGroup'"
echo ""
echo "⚠️  Eslatma:"
echo "   Agar GD extension yo'q bo'lsa, WebP conversion ishlamaydi"
echo "   Lekin original WebP fayl yuborilishga harakat qilinadi"
echo ""
