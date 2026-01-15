#!/bin/bash

echo "🔧 Webhook xatosini hal qilish..."
echo ""

# 1. Workerlarni background'da ishga tushirish
echo "1️⃣ Workerlarni ishga tushirish..."
pkill -f "download.e-qarz.uz.*queue:work" 2>/dev/null
sleep 1

nohup php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
nohup php artisan queue:work redis --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &

sleep 2
echo "✅ Workerlarni ishga tushirdim"
echo ""

# 2. Webhook'ni test qilish
echo "2️⃣ Webhook'ni test qilish..."
RESPONSE=$(curl -s -X POST https://download.e-qarz.uz/api/telegram/webhook \
  -H "Content-Type: application/json" \
  -d '{"update_id": 1, "message": {"message_id": 1, "chat": {"id": 123}, "text": "/start"}}')

echo "$RESPONSE" | python3 -m json.tool 2>/dev/null || echo "$RESPONSE"
echo ""

# 3. Agar xato bo'lsa, loglarni ko'rish
if echo "$RESPONSE" | grep -q "error\|false"; then
    echo "3️⃣ Oxirgi xatolarni ko'rish..."
    tail -n 10 storage/logs/laravel.log | grep -i "error\|exception" | tail -3
fi

echo ""
echo "✅ Tugadi!"
echo ""
echo "🔍 Tekshirish:"
echo "  1. ps aux | grep 'queue:work' - 2 ta worker ko'rinishi kerak"
echo "  2. Botga /start yuborish - javob kelishi kerak"
