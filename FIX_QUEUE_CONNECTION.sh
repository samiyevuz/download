#!/bin/bash

echo "🔧 Queue connection'ni Redis ga o'zgartirish..."
echo ""

# 1. .env faylida QUEUE_CONNECTION ni tekshirish va o'zgartirish
echo "1️⃣ .env faylida QUEUE_CONNECTION ni tekshirish..."
if grep -q "^QUEUE_CONNECTION=" .env; then
    CURRENT=$(grep "^QUEUE_CONNECTION=" .env | cut -d '=' -f2)
    echo "   Hozirgi qiymat: $CURRENT"
    
    if [ "$CURRENT" != "redis" ]; then
        sed -i 's/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=redis/' .env
        echo "✅ QUEUE_CONNECTION redis ga o'zgartirildi"
    else
        echo "ℹ️  QUEUE_CONNECTION allaqachon redis"
    fi
else
    echo "   QUEUE_CONNECTION topilmadi, qo'shilyapti..."
    echo "QUEUE_CONNECTION=redis" >> .env
    echo "✅ QUEUE_CONNECTION qo'shildi"
fi
echo ""

# 2. Config cache'ni tozalash
echo "2️⃣ Config cache'ni tozalash..."
php artisan config:clear
php artisan cache:clear
php artisan config:cache
echo "✅ Config cache yangilandi"
echo ""

# 3. Tekshirish
echo "3️⃣ Queue connection'ni tekshirish..."
php artisan tinker --execute="echo 'Queue: ' . config('queue.default');" 2>&1 | grep -i queue || echo "Config test"
echo ""

# 4. Workerlarni qayta ishga tushirish
echo "4️⃣ Workerlarni qayta ishga tushirish..."
pkill -f "download.e-qarz.uz.*queue:work" 2>/dev/null
sleep 2

nohup php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
nohup php artisan queue:work redis --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &

sleep 2

echo "✅ Workerlarni qayta ishga tushirdim"
echo ""

# 5. Tekshirish
echo "5️⃣ Workerlar holati:"
ps aux | grep "download.e-qarz.uz.*queue:work" | grep -v grep
echo ""

echo "✅ Barcha o'zgarishlar amalga oshirildi!"
echo ""
echo "🔍 Endi botga /start yuborib test qiling!"
