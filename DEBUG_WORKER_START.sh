#!/bin/bash

echo "🔍 Workerlar nima uchun ishga tushmayapti?"
echo ""

# 1. Loglarni ko'rish
echo "1️⃣ Worker loglarini ko'rish..."
echo "=== Downloads Worker Log ==="
tail -n 30 storage/logs/queue-downloads.log 2>/dev/null || echo "Log fayli topilmadi yoki bo'sh"
echo ""

echo "=== Telegram Worker Log ==="
tail -n 30 storage/logs/queue-telegram.log 2>/dev/null || echo "Log fayli topilmadi yoki bo'sh"
echo ""

# 2. Workerlarni foreground'da test (5 soniya)
echo "2️⃣ Worker'ni foreground'da test qilish (5 soniya)..."
echo "Agar xato bo'lsa, ko'rinadi:"
timeout 5 php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 2>&1 || echo "Worker xato bilan to'xtadi"
echo ""

# 3. Redis connection test
echo "3️⃣ Redis connection test..."
php artisan tinker --execute="try { \$redis = Illuminate\Support\Facades\Redis::connection('default'); echo 'Redis connection: OK'; } catch (Exception \$e) { echo 'Redis error: ' . \$e->getMessage(); }" 2>&1 | grep -v "Psy\|tinker" || echo "Tinker xato"
echo ""

# 4. Queue config test
echo "4️⃣ Queue config test..."
php artisan tinker --execute="echo 'Queue default: ' . config('queue.default'); echo PHP_EOL; echo 'Redis connection: ' . config('queue.connections.redis.driver');" 2>&1 | grep -v "Psy\|tinker" || echo "Config test xato"
echo ""

echo "✅ Tekshirish tugadi!"
