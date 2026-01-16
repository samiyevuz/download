#!/bin/bash

echo "🔧 Rate-limit retry va qo'shimcha headers qo'shish..."
echo ""

# 1. Config yangilash
echo "1️⃣ Config yangilash..."
php artisan config:clear
php artisan config:cache
echo "✅ Config yangilandi"
echo ""

# 2. Workerlarni qayta ishga tushirish
echo "2️⃣ Workerlarni qayta ishga tushirish..."
pkill -9 -f "artisan queue:work" 2>/dev/null
sleep 2

cd /var/www/sardor/data/www/download.e-qarz.uz
nohup php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
nohup php artisan queue:work redis --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &

sleep 3
echo "✅ Workerlarni qayta ishga tushirdim"
echo ""

# 3. Tekshirish
echo "3️⃣ Tekshirish..."
WORKERS=$(ps aux | grep "artisan queue:work redis" | grep -v grep | grep -v "datacollector" | wc -l)
if [ "$WORKERS" -ge 2 ]; then
    echo "✅ $WORKERS worker ishlayapti"
    ps aux | grep "artisan queue:work redis" | grep -v grep | grep -v "datacollector" | awk '{print "   PID:", $2, "Queue:", $NF}'
else
    echo "⚠️  Faqat $WORKERS worker ishlayapti"
fi
echo ""

echo "✅ Tugadi!"
echo ""
echo "🎉 Qo'shimcha yaxshilanishlar:"
echo "   - Rate-limit uchun avtomatik retry qo'shildi"
echo "   - Qo'shimcha headers qo'shildi (Accept-Language, Accept)"
echo ""
echo "📱 Botga Instagram link yuborib test qiling!"
echo ""
echo "⚠️  Eslatma:"
echo "   - Agar rate-limit bo'lsa, bot avtomatik qayta urinib ko'radi"
echo "   - Agar hali ham ishlamasa, bir necha daqiqadan keyin qayta urinib ko'ring"
echo "   - Instagram ba'zan qattiq cheklovlarni qo'yadi (bu normal)"
