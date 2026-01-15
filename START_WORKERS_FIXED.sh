#!/bin/bash

echo "🚀 Workerlarni ishga tushirish..."
echo ""

# Eski workerlarni to'xtatish
pkill -f "download.e-qarz.uz.*queue:work" 2>/dev/null
sleep 1

# Config tozalash
php artisan config:clear
php artisan config:cache

# Workerlarni default redis connection bilan ishga tushirish
echo "📥 Downloads worker ishga tushyapti..."
nohup php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
DOWNLOADS_PID=$!

echo "📤 Telegram worker ishga tushyapti..."
nohup php artisan queue:work redis --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &
TELEGRAM_PID=$!

sleep 3

# Tekshirish
echo ""
echo "📊 Workerlar holati:"
if ps -p $DOWNLOADS_PID > /dev/null 2>&1; then
    echo "✅ Downloads worker ishga tushdi (PID: $DOWNLOADS_PID)"
else
    echo "❌ Downloads worker ishga tushmadi!"
    echo "Log:"
    tail -n 10 storage/logs/queue-downloads.log
fi

if ps -p $TELEGRAM_PID > /dev/null 2>&1; then
    echo "✅ Telegram worker ishga tushdi (PID: $TELEGRAM_PID)"
else
    echo "❌ Telegram worker ishga tushmadi!"
    echo "Log:"
    tail -n 10 storage/logs/queue-telegram.log
fi

echo ""
echo "📋 Barcha workerlar:"
ps aux | grep "download.e-qarz.uz.*queue:work" | grep -v grep

echo ""
echo "📝 Loglarni ko'rish:"
echo "  tail -f storage/logs/queue-downloads.log"
echo "  tail -f storage/logs/queue-telegram.log"
