#!/bin/bash

echo "📢 Majburiy kanallarni sozlash..."
echo ""

cd /var/www/sardor/data/www/download.e-qarz.uz

# 1. .env faylini ko'rsatish
echo "1️⃣ .env faylini oching va quyidagilarni qo'shing:"
echo ""
echo "   # Ikkita kanal uchun (vergul bilan ajratilgan):"
echo "   TELEGRAM_REQUIRED_CHANNELS=TheUzSoft,samiyev_blog"
echo ""
echo "   yoki @ belgisi bilan:"
echo "   TELEGRAM_REQUIRED_CHANNELS=@TheUzSoft,@samiyev_blog"
echo ""
echo "   # Yoki bitta kanal uchun:"
echo "   TELEGRAM_REQUIRED_CHANNEL_USERNAME=TheUzSoft"
echo ""

# 2. PHP syntax tekshirish
echo "2️⃣ PHP syntax tekshirish..."
php -l app/Services/TelegramService.php
php -l app/Http/Controllers/TelegramWebhookController.php
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
echo "📝 Keyingi qadamlar:"
echo "   1. .env faylida TELEGRAM_REQUIRED_CHANNELS=TheUzSoft,samiyev_blog ni qo'shing"
echo "   2. php artisan config:cache buyrug'ini ishga tushiring"
echo "   3. Botga /start yuborib test qiling"
echo ""
echo "💡 Eslatma:"
echo "   - Ikkita kanal uchun: TELEGRAM_REQUIRED_CHANNELS=TheUzSoft,samiyev_blog"
echo "   - Tugmalarda kanal nomlari ko'rinadi: '📢 TheUzSoft' va '📢 Samiyev_blog'"
echo "   - Foydalanuvchi ikkala kanalga ham a'zo bo'lishi kerak"
echo ""
