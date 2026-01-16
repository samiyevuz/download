#!/bin/bash

echo "🔧 A'zolik aniqlash muammosini tuzatish..."
echo ""

cd /var/www/sardor/data/www/download.e-qarz.uz

# 1. PHP syntax tekshirish
echo "1️⃣ PHP syntax tekshirish..."
php -l app/Services/TelegramService.php
php -l app/Http/Controllers/TelegramWebhookController.php
echo ""

# 2. Config yangilash
echo "2️⃣ Config yangilash..."
php artisan config:clear
php artisan config:cache
echo "✅ Config yangilandi"
echo ""

# 3. Bot va kanal holatini tekshirish
echo "3️⃣ Bot va kanal holatini tekshirish..."
chmod +x TEST_CHANNEL_SUBSCRIPTION.sh
./TEST_CHANNEL_SUBSCRIPTION.sh
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
echo "5️⃣ Tekshirish..."
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
echo "   ✨ Batafsil debug loglar qo'shildi"
echo "   ✨ API javobini to'liq log qilish"
echo "   ✨ Status tekshiruvi yaxshilandi"
echo ""
echo "📝 Keyingi qadamlar:"
echo "   1. Botni kanalga admin qiling (agar admin bo'lmasa)"
echo "   2. Botga /start yuboring"
echo "   3. Tilni tanlang"
echo "   4. Kanalga a'zo bo'ling"
echo "   5. '✅ Tekshirish' tugmasini bosing"
echo ""
echo "🔍 Debug uchun loglarni ko'ring:"
echo "   tail -f storage/logs/laravel.log | grep -i 'getChatMember\|membership\|status'"
echo ""
echo "⚠️  MUHIM:"
echo "   - Bot kanalga ADMIN bo'lishi kerak"
echo "   - Botga 'Members' ni ko'rish huquqini bering"
echo "   - Agar hali ham ishlamasa, loglarni ko'ring va xatolarni yuboring"
echo ""
