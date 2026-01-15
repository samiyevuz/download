#!/bin/bash

echo "🧹 Eski workerlarni tozalash..."
echo ""

# Barcha workerlarni to'xtatish
pkill -f "download.e-qarz.uz.*queue:work" 2>/dev/null
sleep 2

echo "✅ Eski workerlar to'xtatildi"
echo ""

# Faqat 2 ta yangi worker ishga tushirish
echo "🚀 Yangi workerlarni ishga tushirish..."
nohup php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
nohup php artisan queue:work redis --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &

sleep 2

echo "✅ Yangi workerlar ishga tushdi"
echo ""

# Tekshirish
echo "📊 Workerlarni holati:"
ps aux | grep "download.e-qarz.uz.*queue:work" | grep -v grep | wc -l | xargs echo "Ishlayotgan workerlar soni:"

echo ""
echo "✅ Tugadi! Endi botga /start yuborib test qiling!"
