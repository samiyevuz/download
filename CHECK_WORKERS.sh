#!/bin/bash

echo "🔍 Workerlarni tekshirish..."
echo ""

# 1. Workerlarni tekshirish
echo "1️⃣ Workerlarni tekshirish..."
WORKERS=$(ps aux | grep "download.e-qarz.uz.*queue:work" | grep -v grep)
if [ -z "$WORKERS" ]; then
    echo "❌ Workerlarni topilmadi!"
    echo ""
    echo "2️⃣ Loglarni ko'rish..."
    echo "=== Downloads Worker Log ==="
    tail -n 20 storage/logs/queue-downloads.log 2>/dev/null || echo "Log fayli topilmadi"
    echo ""
    echo "=== Telegram Worker Log ==="
    tail -n 20 storage/logs/queue-telegram.log 2>/dev/null || echo "Log fayli topilmadi"
    echo ""
    echo "3️⃣ Workerlarni qayta ishga tushirish..."
    nohup php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
    DOWNLOADS_PID=$!
    nohup php artisan queue:work redis --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &
    TELEGRAM_PID=$!
    sleep 3
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
else
    echo "✅ Workerlarni ishlayapti:"
    echo "$WORKERS"
fi

echo ""
echo "4️⃣ Webhook'ni test qilish..."
curl -s -X POST https://download.e-qarz.uz/api/telegram/webhook \
  -H "Content-Type: application/json" \
  -d '{"update_id": 1, "message": {"message_id": 1, "chat": {"id": 123}, "text": "/start"}}' | python3 -m json.tool 2>/dev/null || \
curl -s -X POST https://download.e-qarz.uz/api/telegram/webhook \
  -H "Content-Type: application/json" \
  -d '{"update_id": 1, "message": {"message_id": 1, "chat": {"id": 123}, "text": "/start"}}'

echo ""
echo ""
echo "5️⃣ Oxirgi Laravel xatolari:"
tail -n 5 storage/logs/laravel.log | grep -i "error\|exception" | tail -3 || echo "Xatolar topilmadi"
