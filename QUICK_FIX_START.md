# /start Ishlayotganini Tekshirish va Tuzatish

## 🚨 Muammo: /start Ishlayapti

Quyidagi qadamlarni ketma-ket bajaring:

## ✅ Qadam 1: Queue Workerlarni Tekshirish

```bash
# Workerlar ishlayaptimi?
ps aux | grep "queue:work"
```

**Agar hech narsa ko'rinmasa**, workerlar ishlamayapti!

## ✅ Qadam 2: Cache Muammosini Hal Qilish

```bash
# .env faylini o'zgartirish
nano .env
```

Faylda quyidagini toping va o'zgartiring:
```
CACHE_STORE=database  →  CACHE_STORE=redis
```

Saqlash: `Ctrl+X`, keyin `Y`, keyin `Enter`

```bash
# Config cache'ni yangilash
php artisan config:clear
php artisan config:cache
```

## ✅ Qadam 3: Queue Workerlarni Ishga Tushirish

**Birinchi terminal yoki screen/tmux:**
```bash
php artisan queue:work redis-downloads --queue=downloads --tries=2 --timeout=60
```

**Ikkinchi terminal yoki screen/tmux:**
```bash
php artisan queue:work redis-telegram --queue=telegram --tries=3 --timeout=10
```

## ✅ Qadam 4: Webhook'ni Tekshirish

```bash
# Webhook holatini ko'rish
curl "https://api.telegram.org/bot$(grep TELEGRAM_BOT_TOKEN .env | cut -d '=' -f2)/getWebhookInfo"
```

**Agar webhook sozlanmagan bo'lsa:**
```bash
# Webhook'ni sozlash
curl -X POST "https://api.telegram.org/bot$(grep TELEGRAM_BOT_TOKEN .env | cut -d '=' -f2)/setWebhook" \
  -H "Content-Type: application/json" \
  -d "{\"url\": \"https://$(grep APP_URL .env | cut -d '=' -f2 | sed 's|https\?://||' | sed 's|/$||')/api/telegram/webhook\"}"
```

## ✅ Qadam 5: Loglarni Tekshirish

```bash
# Oxirgi xatolarni ko'rish
tail -f storage/logs/laravel.log
```

## 🎯 Tezkor Yechim (Barcha Qadamlarni Birga)

```bash
# 1. Cache'ni tuzatish
sed -i 's/CACHE_STORE=database/CACHE_STORE=redis/' .env
php artisan config:clear
php artisan config:cache

# 2. Workerlarni ishga tushirish (background'da)
nohup php artisan queue:work redis-downloads --queue=downloads --tries=2 --timeout=60 > /dev/null 2>&1 &
nohup php artisan queue:work redis-telegram --queue=telegram --tries=3 --timeout=10 > /dev/null 2>&1 &

# 3. Webhook'ni sozlash (YOUR_DOMAIN va YOUR_BOT_TOKEN ni o'zgartiring!)
curl -X POST "https://api.telegram.org/botYOUR_BOT_TOKEN/setWebhook" \
  -H "Content-Type: application/json" \
  -d '{"url": "https://YOUR_DOMAIN/api/telegram/webhook"}'
```

## 🔍 Tekshirish

1. **Workerlar ishlayaptimi?**
   ```bash
   ps aux | grep "queue:work"
   ```
   Ikki worker ko'rinishi kerak.

2. **Webhook sozlanmaganmi?**
   ```bash
   curl "https://api.telegram.org/botYOUR_BOT_TOKEN/getWebhookInfo"
   ```
   `"url"` bo'sh bo'lmasligi kerak.

3. **Botga /start yuborish**
   - Telegram'da botga `/start` yuboring
   - Agar javob kelsa, ishlayapti! ✅

## ⚠️ Agar Hali Ham Ishlamasa

```bash
# Barcha loglarni ko'rish
tail -n 100 storage/logs/laravel.log | grep -i error

# Queue holatini tekshirish
php artisan queue:failed
```
