#!/bin/bash

echo "🔄 Laravel Cache va Worker'larni Yangilash"
echo "=========================================="
echo ""

cd ~/www/download.e-qarz.uz || exit 1

# 1. Config va cache'larni tozalash
echo "1️⃣ Config va cache'larni tozalash..."
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear
echo "   ✅ Cache tozalandi"
echo ""

# 2. Config'ni qaytadan cache qilish
echo "2️⃣ Config'ni qaytadan cache qilish..."
php artisan config:cache
echo "   ✅ Config cache qilindi"
echo ""

# 3. OPcache tozalash (agar mavjud bo'lsa)
echo "3️⃣ OPcache tozalash..."
if command -v php > /dev/null 2>&1; then
    php -r "if (function_exists('opcache_reset')) { opcache_reset(); echo 'OPcache tozalandi'; } else { echo 'OPcache mavjud emas'; }"
fi
echo ""

# 4. Worker'larni qayta ishga tushirish
echo "4️⃣ Queue worker'larni to'xtatish va qayta ishga tushirish..."
echo "   ⚠️  Avval barcha worker'larni to'xtating:"
echo "      supervisorctl stop telegram-bot-*-worker:*"
echo ""
echo "   Keyin qayta ishga tushiring:"
echo "      supervisorctl start telegram-bot-*-worker:*"
echo ""

echo "===================================="
echo "✅ Laravel cache tozalandi!"
echo ""
echo "📝 Keyingi qadamlar:"
echo "   1. Worker'larni qayta ishga tushiring:"
echo "      supervisorctl restart telegram-bot-*-worker:*"
echo ""
echo "   2. Worker holatini tekshiring:"
echo "      supervisorctl status"
echo ""
echo "   3. Bot'ni test qiling va loglarni kuzating:"
echo "      tail -f storage/logs/laravel.log | grep -E 'Method 6|HTML parsing'"
echo ""
