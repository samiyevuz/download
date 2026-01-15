#!/bin/bash

echo "🔧 Barcha muammolarni hal qilish..."
echo ""

# 1. Cache muammosini hal qilish
echo "1️⃣ Cache muammosini hal qilish..."
if grep -q "CACHE_STORE=database" .env; then
    sed -i 's/CACHE_STORE=database/CACHE_STORE=redis/' .env
    echo "✅ CACHE_STORE redis ga o'zgartirildi"
else
    echo "ℹ️  CACHE_STORE allaqachon sozlangan"
fi

php artisan config:clear
php artisan config:cache
echo "✅ Config cache yangilandi"
echo ""

# 2. Bot token va domain ni olish
echo "2️⃣ Bot token va domain ni olish..."
BOT_TOKEN=$(grep "^TELEGRAM_BOT_TOKEN=" .env | cut -d '=' -f2 | tr -d '"' | tr -d "'" | xargs)
DOMAIN=$(grep "^APP_URL=" .env | cut -d '=' -f2 | sed 's|https\?://||' | sed 's|/$||' | xargs)

if [ -z "$BOT_TOKEN" ]; then
    echo "❌ TELEGRAM_BOT_TOKEN topilmadi .env faylida!"
    echo "Iltimos, .env faylida TELEGRAM_BOT_TOKEN=your_token qo'shing"
    exit 1
fi

if [ -z "$DOMAIN" ]; then
    echo "❌ APP_URL topilmadi .env faylida!"
    echo "Iltimos, .env faylida APP_URL=https://yourdomain.com qo'shing"
    exit 1
fi

echo "✅ Bot token: ${BOT_TOKEN:0:10}..."
echo "✅ Domain: $DOMAIN"
echo ""

# 3. Webhook'ni sozlash
echo "3️⃣ Webhook'ni sozlash..."
WEBHOOK_URL="https://${DOMAIN}/api/telegram/webhook"
echo "Webhook URL: $WEBHOOK_URL"

RESPONSE=$(curl -s -X POST "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook" \
  -H "Content-Type: application/json" \
  -d "{\"url\": \"${WEBHOOK_URL}\"}")

if echo "$RESPONSE" | grep -q '"ok":true'; then
    echo "✅ Webhook muvaffaqiyatli sozlandi!"
else
    echo "❌ Webhook sozlashda xato:"
    echo "$RESPONSE"
fi
echo ""

# 4. Webhook holatini tekshirish
echo "4️⃣ Webhook holatini tekshirish..."
curl -s "https://api.telegram.org/bot${BOT_TOKEN}/getWebhookInfo" | python3 -m json.tool 2>/dev/null || curl -s "https://api.telegram.org/bot${BOT_TOKEN}/getWebhookInfo"
echo ""
echo ""

# 5. Queue workerlarni ishga tushirish
echo "5️⃣ Queue workerlarni ishga tushirish..."
echo "⚠️  Eslatma: Workerlarni background'da ishga tushirish uchun nohup yoki screen/tmux ishlating"
echo ""
echo "Quyidagi buyruqlarni alohida terminal yoki screen/tmux'da ishga tushiring:"
echo ""
echo "Terminal 1:"
echo "  php artisan queue:work redis-downloads --queue=downloads --tries=2 --timeout=60"
echo ""
echo "Terminal 2:"
echo "  php artisan queue:work redis-telegram --queue=telegram --tries=3 --timeout=10"
echo ""
echo "Yoki background'da:"
echo "  nohup php artisan queue:work redis-downloads --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &"
echo "  nohup php artisan queue:work redis-telegram --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &"
echo ""

echo "✅ Barcha sozlamalar tayyor!"
echo ""
echo "🔍 Tekshirish:"
echo "  1. ps aux | grep 'queue:work' - 2 ta worker ko'rinishi kerak"
echo "  2. Botga /start yuborish - javob kelishi kerak"
