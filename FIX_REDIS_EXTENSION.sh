#!/bin/bash

echo "🔧 PHP Redis Extension muammosini hal qilish..."
echo ""

# 1. PHP versiyasini aniqlash
echo "1️⃣ PHP versiyasini aniqlash..."
PHP_VERSION=$(php -v | head -n 1 | cut -d ' ' -f 2 | cut -d '.' -f 1,2)
echo "   PHP versiya: $PHP_VERSION"
echo ""

# 2. Redis extension o'rnatilganligini tekshirish
echo "2️⃣ Redis extension'ni tekshirish..."
if php -m | grep -q redis; then
    echo "✅ Redis extension allaqachon o'rnatilgan"
else
    echo "❌ Redis extension topilmadi"
    echo ""
    echo "3️⃣ Redis extension'ni o'rnatish..."
    echo "   Quyidagi buyruqlarni bajaring:"
    echo ""
    echo "   Ubuntu/Debian:"
    echo "   sudo apt-get update"
    echo "   sudo apt-get install php${PHP_VERSION}-redis"
    echo ""
    echo "   Yoki Predis package'ni o'rnatish (tavsiya etiladi):"
    echo "   composer require predis/predis"
    echo ""
fi
echo ""

# 3. Predis package'ni o'rnatish (alternativ yechim)
echo "4️⃣ Predis package'ni o'rnatish (agar phpredis ishlamasa)..."
if [ -f "composer.json" ]; then
    if grep -q "predis/predis" composer.json; then
        echo "✅ Predis allaqachon o'rnatilgan"
    else
        echo "📦 Predis package'ni o'rnatish..."
        composer require predis/predis
        echo "✅ Predis o'rnatildi"
    fi
else
    echo "⚠️  composer.json topilmadi"
fi
echo ""

# 4. Config'ni yangilash
echo "5️⃣ Config'ni yangilash..."
php artisan config:clear
php artisan config:cache
echo "✅ Config yangilandi"
echo ""

echo "✅ Tugadi!"
echo ""
echo "🔍 Keyingi qadamlar:"
echo "  1. Agar phpredis o'rnatilsa, PHP'ni qayta ishga tushiring"
echo "  2. Yoki Predis ishlatish uchun .env'da REDIS_CLIENT=predis qo'shing"
