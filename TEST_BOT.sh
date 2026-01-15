#!/bin/bash

echo "🧪 BOTNI TEST QILISH"
echo "===================="
echo ""

# 1. Workerlarni tekshirish
echo "1️⃣ Workerlarni tekshirish..."
WORKERS=$(ps aux | grep "download.e-qarz.uz.*queue:work\|artisan queue:work redis" | grep -v grep | grep -v "datacollector" | wc -l)
if [ "$WORKERS" -ge 2 ]; then
    echo "   ✅ $WORKERS worker ishlayapti"
    ps aux | grep "artisan queue:work redis" | grep -v grep | grep -v "datacollector" | awk '{print "   PID:", $2, "Queue:", $NF}'
else
    echo "   ⚠️  Faqat $WORKERS worker ishlayapti (2 ta bo'lishi kerak)"
fi
echo ""

# 2. Redis queue holatini tekshirish
echo "2️⃣ Redis queue holati..."
php artisan tinker <<'EOF' 2>&1 | grep -v "Psy\|tinker"
try {
    $redis = app('redis');
    $downloads = $redis->llen('queues:downloads');
    $telegram = $redis->llen('queues:telegram');
    echo "   Downloads queue: $downloads job\n";
    echo "   Telegram queue: $telegram job\n";
} catch (Exception $e) {
    echo "   ⚠️  " . $e->getMessage() . "\n";
}
EOF
echo ""

# 3. Webhook tekshirish
echo "3️⃣ Webhook tekshirish..."
BOT_TOKEN=$(grep TELEGRAM_BOT_TOKEN .env | cut -d '=' -f2 | tr -d '"' | tr -d "'")
if [ -n "$BOT_TOKEN" ]; then
    echo "   Bot token: ${BOT_TOKEN:0:10}... (mavjud)"
    WEBHOOK_INFO=$(curl -s "https://api.telegram.org/bot$BOT_TOKEN/getWebhookInfo")
    if echo "$WEBHOOK_INFO" | grep -q '"ok":true'; then
        echo "   ✅ Webhook sozlangan"
        echo "$WEBHOOK_INFO" | grep -o '"url":"[^"]*"' | head -1
    else
        echo "   ⚠️  Webhook muammosi"
    fi
else
    echo "   ❌ Bot token topilmadi!"
fi
echo ""

# 4. Loglarni tekshirish
echo "4️⃣ Oxirgi loglar (xatolar):"
tail -20 storage/logs/laravel.log | grep -E "(ERROR|Exception|Failed)" | tail -5 || echo "   (xato topilmadi - yaxshi!)"
echo ""

echo "===================="
echo "✅ TEST TUGADI!"
echo ""
echo "📱 KEYINGI QADAMLAR:"
echo "   1. Telegram botga /start yuboring"
echo "   2. Instagram link yuboring"
echo "   3. Agar muammo bo'lsa, loglarni ko'ring:"
echo "      tail -f storage/logs/laravel.log"
echo "      tail -f storage/logs/queue-downloads.log"
