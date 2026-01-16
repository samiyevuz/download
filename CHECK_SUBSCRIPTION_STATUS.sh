#!/bin/bash

echo "🔍 Majburiy a'zolik holatini tekshirish..."
echo ""

cd /var/www/sardor/data/www/download.e-qarz.uz

# 1. .env faylini tekshirish
echo "1️⃣ .env faylini tekshirish..."
if grep -q "TELEGRAM_REQUIRED_CHANNELS" .env 2>/dev/null; then
    echo "✅ TELEGRAM_REQUIRED_CHANNELS topildi:"
    grep "TELEGRAM_REQUIRED_CHANNELS" .env
else
    echo "❌ TELEGRAM_REQUIRED_CHANNELS topilmadi!"
    echo "   .env faylida quyidagilarni qo'shing:"
    echo "   TELEGRAM_REQUIRED_CHANNELS=TheUzSoft,samiyev_blog"
    exit 1
fi
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
    echo "   php artisan config:clear && php artisan config:cache qiling"
    exit 1
fi
echo ""

# 4. Bot va kanal ma'lumotlarini tekshirish
echo "4️⃣ Bot va kanal ma'lumotlarini tekshirish..."
chmod +x TEST_CHANNEL_SUBSCRIPTION.sh
./TEST_CHANNEL_SUBSCRIPTION.sh
echo ""

# 5. Loglarni tekshirish
echo "5️⃣ Oxirgi loglarni tekshirish..."
echo "   Oxirgi 20 qator log:"
tail -20 storage/logs/laravel.log | grep -i "subscription\|channel\|checking" || echo "   Hech qanday log topilmadi"
echo ""

# 6. Workerlarni tekshirish
echo "6️⃣ Workerlarni tekshirish..."
WORKERS=$(ps aux | grep "artisan queue:work redis" | grep -v grep | grep -v "datacollector" | wc -l)
if [ "$WORKERS" -ge 2 ]; then
    echo "✅ $WORKERS worker ishlayapti"
else
    echo "⚠️  Faqat $WORKERS worker ishlayapti"
    echo "   Workerlarni qayta ishga tushiring:"
    echo "   pkill -9 -f 'artisan queue:work' && sleep 2"
    echo "   nohup php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &"
    echo "   nohup php artisan queue:work redis --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &"
fi
echo ""

echo "===================================="
echo "✅ Tekshirish tugadi!"
echo ""
echo "📝 Keyingi qadamlar:"
echo "   1. Botni kanalga admin qiling (agar admin bo'lmasa)"
echo "   2. Botga /start yuborib test qiling"
echo "   3. Tilni tanlang"
echo "   4. Kanal a'zoligini tekshirish xabari ko'rinishi kerak"
echo ""
echo "🔍 Debug uchun:"
echo "   tail -f storage/logs/laravel.log | grep -i 'subscription\|channel'"
echo ""
