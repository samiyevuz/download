#!/bin/bash

echo "🔍 Workerlarni test qilish..."
echo ""

# 1. Redis tekshirish
echo "1️⃣ Redis tekshirish..."
if command -v redis-cli &> /dev/null; then
    REDIS_PING=$(redis-cli ping 2>&1)
    if [ "$REDIS_PING" = "PONG" ]; then
        echo "✅ Redis ishlayapti"
    else
        echo "❌ Redis ishlamayapti: $REDIS_PING"
    fi
else
    echo "⚠️  redis-cli topilmadi"
fi
echo ""

# 2. Config tekshirish
echo "2️⃣ Config tekshirish..."
php artisan config:show queue.default 2>&1 | head -5
echo ""

# 3. Queue connection'ni test qilish
echo "3️⃣ Queue connection test..."
php artisan tinker --execute="try { Redis::connection('default')->ping(); echo 'Redis connection OK'; } catch (Exception \$e) { echo 'Redis error: ' . \$e->getMessage(); }" 2>&1
echo ""
echo ""

# 4. Workerlarni foreground'da test qilish (5 soniya)
echo "4️⃣ Workerlarni test qilish (5 soniya)..."
echo "Agar xato bo'lsa, ko'rinadi:"
timeout 5 php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 2>&1 || echo "Worker xato bilan to'xtadi"
echo ""
