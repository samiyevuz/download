#!/bin/bash

echo "🔧 Guruhlarda Ishlashni Sozlash"
echo "================================"
echo ""

cd ~/www/download.e-qarz.uz

# 1. PHP syntax tekshirish
echo "1️⃣ PHP syntax tekshirish..."
php -l app/Http/Controllers/TelegramWebhookController.php
php -l app/Services/TelegramService.php
if [ $? -ne 0 ]; then
    echo "❌ PHP syntax xatosi bor!"
    exit 1
fi
echo "✅ PHP syntax to'g'ri"
echo ""

# 2. Config yangilash
echo "2️⃣ Config yangilash..."
php artisan config:clear
php artisan config:cache
echo "✅ Config yangilandi"
echo ""

# 3. Workerlarni qayta ishga tushirish
echo "3️⃣ Workerlarni qayta ishga tushirish..."
pkill -9 -f "artisan queue:work" 2>/dev/null
sleep 2

nohup php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
DOWNLOAD_PID=$!

nohup php artisan queue:work redis --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &
TELEGRAM_PID=$!

sleep 3
echo "✅ Workerlarni ishga tushirdim (PIDs: $DOWNLOAD_PID, $TELEGRAM_PID)"
echo ""

# 4. Tekshirish
echo "4️⃣ Workerlarni tekshirish..."
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
echo "🔧 Guruhlarda Ishlash:"
echo "   ✨ Guruh va superguruhlarda subscription check skip qilinadi"
echo "   ✨ Bot admin bo'lmagan holda ham ishlaydi"
echo "   ✨ Chat type aniqlash qo'shildi (private, group, supergroup)"
echo "   ✨ Guruhlarda media yuklash ishlaydi"
echo ""
echo "📝 Qanday ishlaydi:"
echo "   1. Private chat: Subscription check qilinadi"
echo "   2. Group/Supergroup: Subscription check skip qilinadi"
echo "   3. Bot admin bo'lmagan holda ham ishlaydi"
echo "   4. Guruhlarda media yuklash ishlaydi"
echo ""
echo "🧪 Test qiling:"
echo "   1. Botni guruhga qo'shing (admin bo'lmagan holda)"
echo "   2. Guruhda Instagram link yuboring"
echo "   3. Bot media'ni yuklab olishi kerak"
echo ""
echo "⚠️  Eslatma:"
echo "   - Guruhlarda subscription check skip qilinadi"
echo "   - Bot admin bo'lmagan holda ham ishlaydi"
echo "   - Private chat'larda subscription check qilinadi"
echo ""
