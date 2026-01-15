#!/bin/bash

# Cache muammosini hal qilish uchun script

echo "🔧 Cache muammosini hal qilish..."
echo ""

# 1. .env faylini tekshirish
if [ ! -f .env ]; then
    echo "❌ .env fayli topilmadi!"
    exit 1
fi

# 2. CACHE_STORE ni redis ga o'zgartirish
echo "📝 .env faylida CACHE_STORE ni redis ga o'zgartiramiz..."
sed -i 's/CACHE_STORE=database/CACHE_STORE=redis/' .env

# 3. Tekshirish
if grep -q "CACHE_STORE=redis" .env; then
    echo "✅ CACHE_STORE redis ga o'zgartirildi"
else
    echo "⚠️  CACHE_STORE allaqachon redis yoki boshqa qiymat"
fi

# 4. Config cache'ni tozalash
echo ""
echo "🧹 Config cache'ni tozalaymiz..."
php artisan config:clear
php artisan config:cache

# 5. Queue restart
echo ""
echo "🔄 Queue workerlarni qayta ishga tushiramiz..."
php artisan queue:restart

echo ""
echo "✅ Barcha o'zgarishlar amalga oshirildi!"
echo ""
echo "Endi queue workerlarni ishga tushiring:"
echo "  php artisan queue:work redis-downloads --queue=downloads --tries=2 --timeout=60"
echo "  php artisan queue:work redis-telegram --queue=telegram --tries=3 --timeout=10"
