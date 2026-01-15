#!/bin/bash

echo "🔍 Workerlarni tekshirish..."
echo ""

# 1. Workerlarni ko'rish
echo "1️⃣ Ishlayotgan workerlar:"
ps aux | grep "artisan queue:work" | grep -v grep
WORKER_COUNT=$(ps aux | grep "artisan queue:work" | grep -v grep | wc -l)
echo "   Jami: $WORKER_COUNT worker"
echo ""

# 2. Redis tekshirish
echo "2️⃣ Redis tekshirish:"
redis-cli ping 2>/dev/null && echo "✅ Redis ishlayapti" || echo "❌ Redis ishlamayapti"
echo ""

# 3. Queue holatini tekshirish
echo "3️⃣ Queue holati:"
php artisan tinker <<'EOF'
try {
    $downloads = Redis::llen('queues:downloads');
    $telegram = Redis::llen('queues:telegram');
    echo "Downloads queue: $downloads job\n";
    echo "Telegram queue: $telegram job\n";
} catch (Exception $e) {
    echo "❌ Xato: " . $e->getMessage() . "\n";
}
EOF
echo ""

# 4. Loglarni tekshirish
echo "4️⃣ Loglar (oxirgi 20 qator):"
echo "Downloads queue:"
tail -20 storage/logs/queue-downloads.log 2>/dev/null | tail -10 || echo "   (log bo'sh yoki topilmadi)"
echo ""
echo "Telegram queue:"
tail -20 storage/logs/queue-telegram.log 2>/dev/null | tail -10 || echo "   (log bo'sh yoki topilmadi)"
echo ""

# 5. Asosiy log
echo "5️⃣ Asosiy log (oxirgi xatolar):"
tail -30 storage/logs/laravel.log | grep -E "(ERROR|Exception|Failed)" | tail -10 || echo "   (xato topilmadi)"
echo ""

echo "✅ Tekshiruv tugadi!"
