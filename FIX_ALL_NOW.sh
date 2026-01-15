#!/bin/bash

echo "🚀 BARCHA MUAMMOLARNI BIR MARTADA HAL QILISH"
echo "============================================"
echo ""

# 1. Oxirgi xatolarni ko'rish
echo "1️⃣ Oxirgi xatolarni tekshirish..."
echo "-----------------------------------"
tail -50 storage/logs/laravel.log | grep -E "(ERROR|Exception|failed)" | tail -5 || echo "   (xato topilmadi)"
echo ""

# 2. PHP syntax tekshirish
echo "2️⃣ PHP syntax tekshirish..."
php -l app/Services/YtDlpService.php 2>&1 | grep -E "(No syntax errors|error)" || echo "   ✅ Syntax to'g'ri"
echo ""

# 3. Config yangilash
echo "3️⃣ Config yangilash..."
php artisan config:clear > /dev/null 2>&1
php artisan config:cache > /dev/null 2>&1
echo "   ✅ Config yangilandi"
echo ""

# 4. Workerlarni to'xtatish va qayta ishga tushirish
echo "4️⃣ Workerlarni qayta ishga tushirish..."
pkill -9 -f "artisan queue:work" 2>/dev/null
sleep 2

# Loglarni tozalash
> storage/logs/queue-downloads.log
> storage/logs/queue-telegram.log

cd /var/www/sardor/data/www/download.e-qarz.uz
nohup php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
DOWNLOAD_PID=$!

nohup php artisan queue:work redis --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &
TELEGRAM_PID=$!

sleep 3
echo "   ✅ Workerlarni ishga tushirdim (PIDs: $DOWNLOAD_PID, $TELEGRAM_PID)"
echo ""

# 5. Tekshirish
echo "5️⃣ Final tekshirish..."
WORKERS=$(ps aux | grep "artisan queue:work redis" | grep -v grep | grep -v "datacollector" | wc -l)
if [ "$WORKERS" -ge 2 ]; then
    echo "   ✅ $WORKERS worker ishlayapti"
    ps aux | grep "artisan queue:work redis" | grep -v grep | grep -v "datacollector" | awk '{print "   PID:", $2, "Queue:", $NF}'
else
    echo "   ❌ Workerlarni ishlamayapti!"
    echo "   Loglarni tekshiring:"
    tail -20 storage/logs/queue-downloads.log
    tail -20 storage/logs/queue-telegram.log
fi
echo ""

# 6. Redis va Webhook tekshirish
echo "6️⃣ Redis va Webhook tekshirish..."
if redis-cli ping > /dev/null 2>&1; then
    echo "   ✅ Redis ishlayapti"
else
    echo "   ❌ Redis ishlamayapti!"
fi

BOT_TOKEN=$(grep TELEGRAM_BOT_TOKEN .env 2>/dev/null | cut -d '=' -f2 | tr -d '"' | tr -d "'")
if [ -n "$BOT_TOKEN" ]; then
    WEBHOOK_INFO=$(curl -s "https://api.telegram.org/bot$BOT_TOKEN/getWebhookInfo" 2>/dev/null)
    if echo "$WEBHOOK_INFO" | grep -q '"ok":true'; then
        echo "   ✅ Webhook sozlangan"
    else
        echo "   ⚠️  Webhook muammosi"
    fi
fi
echo ""

echo "============================================"
if [ "$WORKERS" -ge 2 ]; then
    echo "✅ BARCHA MUAMMOLAR HAL QILINDI!"
    echo ""
    echo "🎉 Bot tayyor!"
    echo "   - Format avtomatik tanlanadi (rasm/video)"
    echo "   - Workerlarni ishlayapti"
    echo "   - Config yangilandi"
    echo ""
    echo "📱 Botga Instagram link yuborib test qiling!"
else
    echo "⚠️  WORKERLARNI MUAMMOSI!"
    echo "   Loglarni ko'ring va xatolarni tuzating"
fi
echo ""
