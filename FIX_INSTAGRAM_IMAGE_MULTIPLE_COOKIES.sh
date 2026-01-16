#!/bin/bash

echo "🔧 Instagram Rasm Yuklashni Bir Nechta Cookie bilan Tuzatish"
echo "=============================================================="
echo ""

cd ~/www/download.e-qarz.uz

# 1. PHP syntax tekshirish
echo "1️⃣ PHP syntax tekshirish..."
php -l app/Services/YtDlpService.php
php -l config/telegram.php
if [ $? -ne 0 ]; then
    echo "❌ PHP syntax xatosi bor!"
    exit 1
fi
echo "✅ PHP syntax to'g'ri"
echo ""

# 2. Config yangilash
echo "2️⃣ Config yangilash..."
php artisan config:clear
php artisan config:cache
echo "✅ Config yangilandi"
echo ""

# 3. Cookie setup scriptini ishga tushirish
echo "3️⃣ Cookie fayllarini sozlash..."
chmod +x SETUP_MULTIPLE_COOKIES.sh
./SETUP_MULTIPLE_COOKIES.sh
echo ""

echo "===================================="
echo "✅ Tugadi!"
echo ""
echo "🔧 Tuzatilgan muammolar:"
echo "   ✨ Bir nechta cookie fayllarini qo'llab-quvvatlash"
echo "   ✨ Avtomatik cookie rotatsiyasi"
echo "   ✨ Har bir cookie'ni alohida sinab ko'rish"
echo "   ✨ Batafsil logging (qaysi cookie ishlatilgani)"
echo ""
echo "📝 Qanday ishlaydi:"
echo "   1. Bot birinchi cookie'ni sinaydi"
echo "   2. Agar ishlamasa, ikkinchi cookie'ni sinaydi"
echo "   3. Agar hammasi ishlamasa, cookiesiz metodlarni sinaydi"
echo ""
echo "💡 Eng ishonchli yechim:"
echo "   - Bir nechta cookie fayllarini qo'shing"
echo "   - Har bir cookie turli account'dan olingan bo'lishi yaxshi"
echo "   - Cookie fayllarini muntazam yangilang"
echo ""
echo "🧪 Test qiling:"
echo "   Botga Instagram rasm linki yuboring"
echo "   Loglarni kuzatib turing:"
echo "   tail -f storage/logs/laravel.log | grep -E 'cookie|Instagram image'"
echo ""
