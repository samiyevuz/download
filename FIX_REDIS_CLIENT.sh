#!/bin/bash

echo "🔧 Redis Client'ni Predis ga o'zgartirish..."
echo ""

# 1. .env faylida REDIS_CLIENT ni predis ga o'zgartirish
echo "1️⃣ .env faylida REDIS_CLIENT ni o'zgartirish..."
if grep -q "^REDIS_CLIENT=" .env; then
    sed -i 's/^REDIS_CLIENT=.*/REDIS_CLIENT=predis/' .env
    echo "✅ REDIS_CLIENT predis ga o'zgartirildi"
else
    echo "REDIS_CLIENT=predis" >> .env
    echo "✅ REDIS_CLIENT qo'shildi"
fi
echo ""

# 2. Config'ni yangilash
echo "2️⃣ Config'ni yangilash..."
php artisan config:clear
php artisan cache:clear
php artisan config:cache
echo "✅ Config yangilandi"
echo ""

# 3. Redis connection'ni test qilish
echo "3️⃣ Redis connection'ni test qilish..."
php artisan tinker --execute="try { \$redis = Illuminate\Support\Facades\Redis::connection('default'); \$redis->ping(); echo '✅ Redis connection: OK'; } catch (Exception \$e) { echo '❌ Redis error: ' . \$e->getMessage(); }" 2>&1 | grep -v "Psy\|tinker" || echo "Test bajarildi"
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
ps aux | grep "download.e-qarz.uz.*queue:work" | grep -v grep | wc -l | xargs echo "Ishlayotgan workerlar soni:"
echo ""

echo "✅ Barcha o'zgarishlar amalga oshirildi!"
echo ""
echo "🎉 Endi botga /start yuborib test qiling!"
echo "   Yoki Instagram/TikTok link yuborib test qiling!"
