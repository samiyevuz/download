# Webhook 500 Xatosini Hal Qilish

## Muammo
Webhook sozlandi, lekin 500 Internal Server Error qaytaryapti.

## Tekshirish

### 1. Loglarni ko'rish
```bash
tail -n 50 storage/logs/laravel.log
```

### 2. Webhook endpoint'ni test qilish
```bash
curl -X POST https://download.e-qarz.uz/api/telegram/webhook \
  -H "Content-Type: application/json" \
  -d '{"update_id": 1, "message": {"message_id": 1, "chat": {"id": 123}, "text": "/start"}}'
```

### 3. PHP xatolarini ko'rish
```bash
tail -n 50 /var/log/php-fpm/error.log
# yoki
tail -n 50 /var/log/nginx/error.log
```

## Ehtimoliy Sabablar

1. **Cache muammosi hali ham bor**
   ```bash
   php artisan config:clear
   php artisan cache:clear
   ```

2. **Queue workerlar ishlamayapti**
   ```bash
   ps aux | grep "download.e-qarz.uz.*queue:work"
   ```

3. **Redis ishlamayapti**
   ```bash
   redis-cli ping
   ```

4. **Permission muammosi**
   ```bash
   ls -la storage/logs/
   chmod -R 775 storage
   ```
