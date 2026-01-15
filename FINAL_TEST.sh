#!/bin/bash

echo "🎉 Bot to'liq tayyor! Tekshirish..."
echo ""

# 1. yt-dlp tekshirish
echo "1️⃣ yt-dlp tekshirish..."
if [ -f ~/bin/yt-dlp ]; then
    ~/bin/yt-dlp --version
    echo "✅ yt-dlp ishlayapti"
else
    echo "❌ yt-dlp topilmadi"
fi
echo ""

# 2. Config tekshirish
echo "2️⃣ Config tekshirish..."
YT_DLP_PATH=$(grep "^YT_DLP_PATH=" .env | cut -d '=' -f2)
echo "   YT_DLP_PATH: $YT_DLP_PATH"
php artisan tinker --execute="echo 'Config path: ' . config('telegram.yt_dlp_path');" 2>&1 | grep -v "Psy\|tinker" || echo "Config test"
echo ""

# 3. Workerlar holati
echo "3️⃣ Workerlar holati..."
WORKERS=$(ps aux | grep "download.e-qarz.uz.*queue:work" | grep -v grep | wc -l)
echo "   Ishlayotgan workerlar: $WORKERS"
if [ "$WORKERS" -ge 2 ]; then
    echo "✅ Workerlarni ishlayapti"
else
    echo "⚠️  Workerlarni qayta ishga tushirish kerak"
    pkill -f "download.e-qarz.uz.*queue:work" 2>/dev/null
    sleep 1
    nohup php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
    nohup php artisan queue:work redis --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &
    sleep 2
    echo "✅ Workerlarni qayta ishga tushirdim"
fi
echo ""

# 4. Redis tekshirish
echo "4️⃣ Redis tekshirish..."
if redis-cli ping > /dev/null 2>&1; then
    echo "✅ Redis ishlayapti"
else
    echo "❌ Redis ishlamayapti"
fi
echo ""

# 5. Webhook tekshirish
echo "5️⃣ Webhook tekshirish..."
BOT_TOKEN=$(grep "^TELEGRAM_BOT_TOKEN=" .env | cut -d '=' -f2 | tr -d '"' | tr -d "'" | xargs)
if [ ! -z "$BOT_TOKEN" ]; then
    WEBHOOK_INFO=$(curl -s "https://api.telegram.org/bot${BOT_TOKEN}/getWebhookInfo")
    if echo "$WEBHOOK_INFO" | grep -q '"ok":true'; then
        echo "✅ Webhook sozlangan"
        echo "$WEBHOOK_INFO" | python3 -m json.tool 2>/dev/null | grep -E '"url"|"pending_update_count"' || echo "$WEBHOOK_INFO"
    else
        echo "❌ Webhook sozlanmagan"
    fi
else
    echo "⚠️  Bot token topilmadi"
fi
echo ""

echo "✅ Tekshirish tugadi!"
echo ""
echo "🎯 Endi botga test yuboring:"
echo "   1. Telegram'da botga /start yuboring"
echo "   2. Yoki Instagram/TikTok link yuboring"
echo ""
echo "📊 Loglarni real-time ko'rish:"
echo "   tail -f storage/logs/laravel.log"
echo "   tail -f storage/logs/queue-downloads.log"
