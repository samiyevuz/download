#!/bin/bash

echo "🚀 Queue workerlarni ishga tushirish..."
echo ""

# Joriy loyiha workerlarini to'xtatish (agar bor bo'lsa)
pkill -f "download.e-qarz.uz.*queue:work" 2>/dev/null
sleep 1

# Workerlarni background'da ishga tushirish
echo "📥 Downloads worker ishga tushyapti..."
nohup php artisan queue:work redis-downloads --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
DOWNLOADS_PID=$!

echo "📤 Telegram worker ishga tushyapti..."
nohup php artisan queue:work redis-telegram --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &
TELEGRAM_PID=$!

sleep 2

# Tekshirish
if ps -p $DOWNLOADS_PID > /dev/null 2>&1; then
    echo "✅ Downloads worker ishga tushdi (PID: $DOWNLOADS_PID)"
else
    echo "❌ Downloads worker ishga tushmadi!"
fi

if ps -p $TELEGRAM_PID > /dev/null 2>&1; then
    echo "✅ Telegram worker ishga tushdi (PID: $TELEGRAM_PID)"
else
    echo "❌ Telegram worker ishga tushmadi!"
fi

echo ""
echo "📊 Workerlar holati:"
ps aux | grep "download.e-qarz.uz.*queue:work" | grep -v grep

echo ""
echo "📝 Loglarni ko'rish:"
echo "  tail -f storage/logs/queue-downloads.log"
echo "  tail -f storage/logs/queue-telegram.log"
echo ""
echo "🛑 Workerlarni to'xtatish:"
echo "  pkill -f 'download.e-qarz.uz.*queue:work'"
