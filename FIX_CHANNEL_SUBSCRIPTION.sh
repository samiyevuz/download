#!/bin/bash

echo "🔧 Majburiy kanal a'zoligini tuzatish..."
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
echo "4️⃣ Tekshirish..."
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
echo "   ✨ Til tanlagandan keyin kanal a'zoligini tekshirish qo'shildi"
echo "   ✨ Xabar matni yangilandi: 'kanallarga a'zo bo'ling va tekshirish tugmasini bosing'"
echo "   ✨ Ikkala kanalga ham a'zo bo'lganini tekshirish"
echo "   ✨ A'zo bo'lgan bo'lsa, welcome xabar yuboriladi"
echo ""
echo "📝 .env faylida quyidagilarni tekshiring:"
echo "   TELEGRAM_REQUIRED_CHANNELS=TheUzSoft,samiyev_blog"
echo ""
echo "📱 Botga /start yuborib test qiling!"
echo ""
