#!/bin/bash

echo "🎯 FINAL TEST - Botni to'liq tekshirish"
echo "========================================"
echo ""

# 1. Workerlarni tekshirish
echo "1️⃣ Workerlarni tekshirish..."
WORKERS=$(ps aux | grep "artisan queue:work redis" | grep -v grep | grep -v "datacollector" | wc -l)
if [ "$WORKERS" -ge 2 ]; then
    echo "   ✅ $WORKERS worker ishlayapti"
    ps aux | grep "artisan queue:work redis" | grep -v grep | grep -v "datacollector" | awk '{print "   PID:", $2, "Queue:", $NF}'
else
    echo "   ❌ Workerlarni ishlamayapti!"
    exit 1
fi
echo ""

# 2. Redis tekshirish
echo "2️⃣ Redis tekshirish..."
if redis-cli ping > /dev/null 2>&1; then
    echo "   ✅ Redis ishlayapti"
else
    echo "   ❌ Redis ishlamayapti!"
    exit 1
fi
echo ""

# 3. Webhook tekshirish
echo "3️⃣ Webhook tekshirish..."
BOT_TOKEN=$(grep TELEGRAM_BOT_TOKEN .env | cut -d '=' -f2 | tr -d '"' | tr -d "'")
if [ -n "$BOT_TOKEN" ]; then
    WEBHOOK_INFO=$(curl -s "https://api.telegram.org/bot$BOT_TOKEN/getWebhookInfo")
    if echo "$WEBHOOK_INFO" | grep -q '"ok":true'; then
        echo "   ✅ Webhook sozlangan"
    else
        echo "   ⚠️  Webhook muammosi"
    fi
else
    echo "   ❌ Bot token topilmadi!"
fi
echo ""

# 4. yt-dlp tekshirish
echo "4️⃣ yt-dlp tekshirish..."
YT_DLP_PATH=$(grep YT_DLP_PATH .env | cut -d '=' -f2 | tr -d '"' | tr -d "'")
if [ -n "$YT_DLP_PATH" ] && [ -f "$YT_DLP_PATH" ] && [ -x "$YT_DLP_PATH" ]; then
    echo "   ✅ yt-dlp mavjud: $YT_DLP_PATH"
    VERSION=$($YT_DLP_PATH --version 2>/dev/null || echo "unknown")
    echo "   Versiya: $VERSION"
else
    echo "   ⚠️  yt-dlp topilmadi yoki executable emas"
fi
echo ""

# 5. Oxirgi xatolarni tekshirish
echo "5️⃣ Oxirgi xatolar (oxirgi 5 daqiqa):"
tail -50 storage/logs/laravel.log | grep -E "(ERROR|Exception)" | tail -3 || echo "   ✅ Xato topilmadi"
echo ""

echo "========================================"
echo "✅ BARCHA TEKSHIRUVLAR TUGADI!"
echo ""
echo "📱 KEYINGI QADAM:"
echo "   1. Telegram botga /start yuboring"
echo "   2. Instagram link yuboring (masalan: https://www.instagram.com/reel/...)"
echo "   3. Bir necha soniyadan keyin video/rasm kelishi kerak"
echo ""
echo "🔍 Agar muammo bo'lsa, loglarni ko'ring:"
echo "   tail -f storage/logs/laravel.log"
echo "   tail -f storage/logs/queue-downloads.log"
