#!/bin/bash

echo "🔍 Webhook xatosini tekshirish..."
echo ""

# 1. Oxirgi xatolarni ko'rish
echo "=== Oxirgi Laravel Xatolari ==="
tail -n 200 storage/logs/laravel.log | grep -A 10 "Error\|Exception" | tail -50
echo ""

# 2. Webhook'ni test qilish va xatoni ko'rish
echo "=== Webhook Test ==="
curl -v -X POST https://download.e-qarz.uz/api/telegram/webhook \
  -H "Content-Type: application/json" \
  -d '{"update_id": 1, "message": {"message_id": 1, "chat": {"id": 123}, "text": "/start"}}' 2>&1 | tail -20
echo ""

# 3. PHP xatolarini ko'rish
echo "=== PHP Error Log (agar mavjud bo'lsa) ==="
if [ -f /var/log/php-fpm/error.log ]; then
    tail -n 20 /var/log/php-fpm/error.log
elif [ -f /var/log/php8.2-fpm.log ]; then
    tail -n 20 /var/log/php8.2-fpm.log
else
    echo "PHP error log topilmadi"
fi
echo ""

# 4. Nginx xatolarini ko'rish
echo "=== Nginx Error Log (agar mavjud bo'lsa) ==="
if [ -f /var/log/nginx/error.log ]; then
    tail -n 20 /var/log/nginx/error.log | grep -i "download.e-qarz.uz"
else
    echo "Nginx error log topilmadi"
fi
