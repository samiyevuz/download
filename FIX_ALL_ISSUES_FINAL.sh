#!/bin/bash

echo "🚀 BARCHA MUAMMOLARNI BIR MARTADA HAL QILISH"
echo "=========================================="
echo ""

# 1. PHP syntax tekshirish
echo "1️⃣ PHP syntax tekshirish..."
ERRORS=0
for file in app/Jobs/DownloadMediaJob.php app/Jobs/SendTelegramMessageJob.php app/Http/Controllers/TelegramWebhookController.php; do
    if php -l "$file" 2>&1 | grep -q "No syntax errors"; then
        echo "   ✅ $file"
    else
        echo "   ❌ $file - XATO!"
        ERRORS=$((ERRORS + 1))
    fi
done
echo ""

# 2. .env tekshirish
echo "2️⃣ .env faylini tekshirish..."
if [ -f .env ]; then
    echo "   ✅ .env mavjud"
    echo "   QUEUE_CONNECTION=$(grep QUEUE_CONNECTION .env | cut -d '=' -f2)"
    echo "   REDIS_CLIENT=$(grep REDIS_CLIENT .env | cut -d '=' -f2)"
    echo "   CACHE_STORE=$(grep CACHE_STORE .env | cut -d '=' -f2)"
else
    echo "   ❌ .env topilmadi!"
    ERRORS=$((ERRORS + 1))
fi
echo ""

# 3. Redis tekshirish
echo "3️⃣ Redis tekshirish..."
if redis-cli ping > /dev/null 2>&1; then
    echo "   ✅ Redis ishlayapti"
else
    echo "   ❌ Redis ishlamayapti!"
    ERRORS=$((ERRORS + 1))
fi
echo ""

# 4. Config tozalash va yangilash
echo "4️⃣ Config tozalash va yangilash..."
php artisan config:clear > /dev/null 2>&1
php artisan cache:clear > /dev/null 2>&1
php artisan config:cache > /dev/null 2>&1
echo "   ✅ Config yangilandi"
echo ""

# 5. Laravel config tekshirish
echo "5️⃣ Laravel config tekshirish..."
php artisan tinker <<'EOF' 2>&1 | grep -v "Psy\|tinker"
try {
    $queue = config('queue.default');
    $redisClient = config('database.redis.client');
    $telegramToken = config('telegram.bot_token');
    
    echo "   Queue connection: $queue\n";
    echo "   Redis client: $redisClient\n";
    echo "   Telegram token: " . ($telegramToken ? 'Mavjud' : 'Yo\'q') . "\n";
    
    // Redis test
    Redis::ping();
    echo "   ✅ Redis ulandi\n";
} catch (Exception $e) {
    echo "   ❌ Xato: " . $e->getMessage() . "\n";
}
EOF
echo ""

# 6. Eski workerlarni to'xtatish
echo "6️⃣ Eski workerlarni to'xtatish..."
pkill -9 -f "artisan queue:work" 2>/dev/null
sleep 2
echo "   ✅ To'xtatildi"
echo ""

# 7. Loglarni tozalash
echo "7️⃣ Loglarni tozalash..."
> storage/logs/queue-downloads.log
> storage/logs/queue-telegram.log
echo "   ✅ Loglar tozalandi"
echo ""

# 8. Workerlarni FOREGROUND'da test qilish (3 soniya)
echo "8️⃣ Workerlarni test qilish (3 soniya)..."
echo "   Agar xato bo'lsa, darhol ko'rinadi:"
timeout 3 php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 2>&1 | head -20 || true
echo ""

# 9. Workerlarni background'da ishga tushirish
echo "9️⃣ Workerlarni background'da ishga tushirish..."
cd /var/www/sardor/data/www/download.e-qarz.uz

nohup php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
DOWNLOAD_PID=$!

nohup php artisan queue:work redis --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &
TELEGRAM_PID=$!

sleep 3
echo "   ✅ Workerlarni ishga tushirdim (PIDs: $DOWNLOAD_PID, $TELEGRAM_PID)"
echo ""

# 10. Tekshirish
echo "🔟 Final tekshirish..."
WORKERS=$(ps aux | grep "artisan queue:work" | grep -v grep | wc -l)
echo "   Ishlayotgan workerlar: $WORKERS"

if [ "$WORKERS" -ge 2 ]; then
    echo "   ✅ Workerlarni ishlayapti!"
    ps aux | grep "artisan queue:work" | grep -v grep | awk '{print "   PID:", $2, "Queue:", $NF}'
else
    echo "   ⚠️  Workerlarni ishlamayapti!"
    echo "   Loglarni tekshiring:"
    echo "   tail -30 storage/logs/queue-downloads.log"
    echo "   tail -30 storage/logs/queue-telegram.log"
fi
echo ""

# 11. Loglarni ko'rsatish
if [ "$WORKERS" -lt 2 ]; then
    echo "📋 XATO LOGLARI:"
    echo "Downloads queue:"
    tail -20 storage/logs/queue-downloads.log 2>/dev/null || echo "   (log bo'sh)"
    echo ""
    echo "Telegram queue:"
    tail -20 storage/logs/queue-telegram.log 2>/dev/null || echo "   (log bo'sh)"
    echo ""
fi

echo "=========================================="
if [ "$WORKERS" -ge 2 ] && [ "$ERRORS" -eq 0 ]; then
    echo "✅ BARCHA MUAMMOLAR HAL QILINDI!"
    echo ""
    echo "🎉 Botga Instagram link yuborib test qiling!"
else
    echo "⚠️  BAZI MUAMMOLAR QOLDI"
    echo "   Yuqoridagi xatolarni tuzating"
fi
echo ""
