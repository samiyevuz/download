#!/bin/bash

echo "📢 Majburiy kanal a'zoligini sozlash..."
echo ""

cd /var/www/sardor/data/www/download.e-qarz.uz

# 1. .env faylini ko'rsatish
echo "1️⃣ .env faylini oching va quyidagilarni qo'shing:"
echo ""
echo "   TELEGRAM_REQUIRED_CHANNEL_ID=your_channel_id"
echo "   yoki"
echo "   TELEGRAM_REQUIRED_CHANNEL_USERNAME=your_channel_username"
echo ""
echo "   Misol:"
echo "   TELEGRAM_REQUIRED_CHANNEL_USERNAME=my_channel"
echo "   (yoki @ belgisiz: my_channel)"
echo ""

# 2. Kanal ID ni topish
echo "2️⃣ Kanal ID ni topish uchun:"
echo "   Botni kanalga admin qiling va quyidagi buyruqni yuboring:"
echo "   https://api.telegram.org/bot<BOT_TOKEN>/getUpdates"
echo "   yoki @userinfobot dan foydalaning"
echo ""

# 3. PHP syntax tekshirish
echo "3️⃣ PHP syntax tekshirish..."
php -l app/Services/TelegramService.php
php -l app/Http/Controllers/TelegramWebhookController.php
echo ""

# 4. Config yangilash
echo "4️⃣ Config yangilash..."
php artisan config:clear
php artisan config:cache
echo "✅ Config yangilandi"
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

echo "===================================="
echo "✅ Tugadi!"
echo ""
echo "📝 Keyingi qadamlar:"
echo "   1. .env faylida TELEGRAM_REQUIRED_CHANNEL_USERNAME yoki TELEGRAM_REQUIRED_CHANNEL_ID ni sozlang"
echo "   2. php artisan config:cache buyrug'ini ishga tushiring"
echo "   3. Botga /start yuborib test qiling"
echo ""
echo "💡 Eslatma:"
echo "   - Kanal username: @ belgisiz (masalan: my_channel)"
echo "   - Kanal ID: raqam (masalan: -1001234567890)"
echo "   - Botni kanalga admin qilish kerak (agar ID ishlatilsa)"
echo ""
