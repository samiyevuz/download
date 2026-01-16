#!/bin/bash

echo "🔧 A'zolik tekshirish - Final tuzatish..."
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

# 3. Bot admin holatini tekshirish
echo "3️⃣ Bot admin holatini tekshirish..."
chmod +x QUICK_FIX_ADMIN.sh
./QUICK_FIX_ADMIN.sh 2>&1 | grep -A 5 "Kanal:"
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
echo "📝 Qanday ishlaydi:"
echo "   ✅ Agar foydalanuvchi kanallarga A'ZO bo'lsa:"
echo "      - Subscription xabari CHIQMAYDI"
echo "      - Darhol welcome xabar yuboriladi"
echo ""
echo "   ❌ Agar foydalanuvchi kanallarga A'ZO bo'lmasa:"
echo "      - Subscription xabari chiqadi"
echo "      - Kanal tugmalari ko'rinadi"
echo "      - '✅ Tekshirish' tugmasi ko'rinadi"
echo ""
echo "📱 Test qiling:"
echo "   1. Botga /start yuboring"
echo "   2. Tilni tanlang"
echo "   3. Agar a'zo bo'lsangiz → Welcome xabar ko'rinadi"
echo "   4. Agar a'zo bo'lmasangiz → Subscription xabari ko'rinadi"
echo ""
echo "🔍 Debug uchun:"
echo "   tail -f storage/logs/laravel.log | grep -i 'membership\|status\|is_member'"
echo ""
