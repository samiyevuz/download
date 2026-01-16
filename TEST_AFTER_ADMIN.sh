#!/bin/bash

echo "✅ Bot admin qilingan - Endi tekshirish..."
echo ""

cd /var/www/sardor/data/www/download.e-qarz.uz

# 1. Bot va kanal holatini tekshirish
echo "1️⃣ Bot va kanal holatini tekshirish..."
chmod +x QUICK_FIX_ADMIN.sh
./QUICK_FIX_ADMIN.sh
echo ""

# 2. Config cache'ni yangilash
echo "2️⃣ Config cache'ni yangilash..."
php artisan config:clear
php artisan config:cache
echo "✅ Config yangilandi"
echo ""

# 3. Config'da tekshirish
echo "3️⃣ Config'da tekshirish..."
REQUIRED_CHANNELS=$(php artisan tinker --execute="echo config('telegram.required_channels');" 2>&1 | grep -v "Psy\|tinker" | tail -1)
echo "   Required channels: $REQUIRED_CHANNELS"

if [ -n "$REQUIRED_CHANNELS" ] && [ "$REQUIRED_CHANNELS" != "null" ]; then
    echo "✅ Config'da kanallar topildi"
else
    echo "❌ Config'da kanallar topilmadi!"
    echo "   .env faylida TELEGRAM_REQUIRED_CHANNELS=TheUzSoft,samiyev_blog ni tekshiring"
fi
echo ""

# 4. PHP syntax tekshirish
echo "4️⃣ PHP syntax tekshirish..."
php -l app/Services/TelegramService.php
php -l app/Http/Controllers/TelegramWebhookController.php
echo ""

# 5. Workerlarni qayta ishga tushirish
echo "5️⃣ Workerlarni qayta ishga tushirish..."
pkill -9 -f "artisan queue:work" 2>/dev/null
sleep 2

nohup php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
DOWNLOAD_PID=$!

nohup php artisan queue:work redis --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &
TELEGRAM_PID=$!

sleep 3
echo "✅ Workerlarni ishga tushirdim (PIDs: $DOWNLOAD_PID, $TELEGRAM_PID)"
echo ""

# 6. Tekshirish
echo "6️⃣ Tekshirish..."
WORKERS=$(ps aux | grep "artisan queue:work redis" | grep -v grep | grep -v "datacollector" | wc -l)
if [ "$WORKERS" -ge 2 ]; then
    echo "✅ $WORKERS worker ishlayapti"
    ps aux | grep "artisan queue:work redis" | grep -v grep | grep -v "datacollector" | awk '{print "   PID:", $2, "Queue:", $NF}'
else
    echo "⚠️  Faqat $WORKERS worker ishlayapti"
fi
echo ""

# 7. Oxirgi loglarni ko'rish
echo "7️⃣ Oxirgi subscription loglarni ko'rish..."
echo "   Oxirgi 10 qator:"
tail -50 storage/logs/laravel.log | grep -i "subscription\|channel\|membership\|getChatMember" | tail -10 || echo "   Hech qanday log topilmadi"
echo ""

echo "===================================="
echo "✅ Tugadi!"
echo ""
echo "🎉 Bot endi kanalni tekshira oladi!"
echo ""
echo "📱 Test qiling:"
echo "   1. Botga /start yuboring"
echo "   2. Tilni tanlang"
echo "   3. Kanalga a'zo bo'ling (agar a'zo bo'lmasangiz)"
echo "   4. '✅ Tekshirish' tugmasini bosing"
echo "   5. Agar a'zo bo'lsangiz, '✅ Muvaffaqiyatli!' xabari ko'rinishi kerak"
echo ""
echo "🔍 Agar muammo bo'lsa, loglarni ko'ring:"
echo "   tail -f storage/logs/laravel.log | grep -i 'getChatMember\|membership\|status'"
echo ""
