# Bot Nega Ishlamayapti - Tekshirish

## 🔍 Tekshirish Qadamlari

### 1. Queue Workerlar Ishlamayaptimi?

```bash
# Workerlar ishlayaptimi tekshirish
ps aux | grep "queue:work"

# Agar hech narsa ko'rinmasa, workerlar ishlamayapti!
```

**Yechim:**
```bash
# Cache muammosini hal qilish
nano .env
# CACHE_STORE=redis qilish

php artisan config:clear
php artisan config:cache

# Workerlarni ishga tushirish
php artisan queue:work redis-downloads --queue=downloads --tries=2 --timeout=60 &
php artisan queue:work redis-telegram --queue=telegram --tries=3 --timeout=10 &
```

### 2. Webhook Sozlanmaganmi?

```bash
# Webhook holatini tekshirish
curl "https://api.telegram.org/botYOUR_BOT_TOKEN/getWebhookInfo"
```

**Yechim:**
```bash
# Webhook'ni sozlash
curl -X POST "https://api.telegram.org/botYOUR_BOT_TOKEN/setWebhook" \
  -H "Content-Type: application/json" \
  -d '{"url": "https://YOUR_DOMAIN.com/api/telegram/webhook"}'
```

### 3. Bot Token To'g'rimi?

```bash
# .env faylida token borligini tekshirish
grep TELEGRAM_BOT_TOKEN .env
```

### 4. Loglarni Tekshirish

```bash
# Oxirgi xatolarni ko'rish
tail -n 50 storage/logs/laravel.log
```
