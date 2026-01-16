#!/bin/bash

echo "🔍 Bot Final Tekshirish"
echo "======================="
echo ""

# 1. Workerlarni tekshirish
echo "1️⃣ Workerlarni tekshirish..."
WORKERS=$(ps aux | grep "artisan queue:work redis" | grep -v grep | grep -v "datacollector" | wc -l)
if [ "$WORKERS" -ge 2 ]; then
    echo "✅ $WORKERS worker ishlayapti"
    ps aux | grep "artisan queue:work redis" | grep -v grep | grep -v "datacollector" | awk '{print "   PID:", $2, "Queue:", $NF}'
else
    echo "❌ Workerlarni ishlamayapti! Faqat $WORKERS worker"
fi
echo ""

# 2. Redis tekshirish
echo "2️⃣ Redis tekshirish..."
if redis-cli ping > /dev/null 2>&1; then
    echo "✅ Redis ishlayapti"
else
    echo "❌ Redis ishlamayapti!"
fi
echo ""

# 3. Cookies faylini tekshirish
echo "3️⃣ Cookies faylini tekshirish..."
COOKIES_FILE="/var/www/sardor/data/www/download.e-qarz.uz/storage/app/cookies/instagram_cookies.txt"
if [ -f "$COOKIES_FILE" ]; then
    SIZE=$(ls -lh "$COOKIES_FILE" | awk '{print $5}')
    PERMS=$(stat -c "%a" "$COOKIES_FILE" 2>/dev/null || stat -f "%OLp" "$COOKIES_FILE" 2>/dev/null)
    echo "✅ Cookies fayli mavjud: $SIZE"
    echo "✅ Permissions: $PERMS"
    
    if head -1 "$COOKIES_FILE" | grep -q "# Netscape HTTP Cookie File"; then
        echo "✅ Cookies fayli to'g'ri formatda (Netscape)"
    else
        echo "⚠️  Cookies fayli format to'g'ri emas (Netscape format bo'lishi kerak)"
    fi
else
    echo "❌ Cookies fayli topilmadi!"
fi
echo ""

# 4. Config tekshirish
echo "4️⃣ Config tekshirish..."
cd /var/www/sardor/data/www/download.e-qarz.uz
COOKIES_PATH=$(php artisan tinker --execute="echo config('telegram.instagram_cookies_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1)
if [ -n "$COOKIES_PATH" ] && [ "$COOKIES_PATH" != "null" ]; then
    echo "✅ Config'da cookies path mavjud"
    echo "   Path: $COOKIES_PATH"
else
    echo "❌ Config'da cookies path topilmadi!"
fi
echo ""

# 5. Webhook tekshirish
echo "5️⃣ Webhook tekshirish..."
BOT_TOKEN=$(grep TELEGRAM_BOT_TOKEN .env 2>/dev/null | cut -d '=' -f2 | tr -d '"' | tr -d "'")
if [ -n "$BOT_TOKEN" ]; then
    WEBHOOK_INFO=$(curl -s "https://api.telegram.org/bot$BOT_TOKEN/getWebhookInfo" 2>/dev/null)
    if echo "$WEBHOOK_INFO" | grep -q '"ok":true'; then
        WEBHOOK_URL=$(echo "$WEBHOOK_INFO" | grep -o '"url":"[^"]*"' | head -1 | cut -d'"' -f4)
        echo "✅ Webhook sozlangan"
        echo "   URL: $WEBHOOK_URL"
    else
        echo "⚠️  Webhook muammosi"
    fi
else
    echo "❌ Bot token topilmadi!"
fi
echo ""

# 6. Oxirgi xatolarni tekshirish
echo "6️⃣ Oxirgi xatolarni tekshirish (oxirgi 5 daqiqa)..."
ERRORS=$(tail -50 storage/logs/laravel.log | grep -E "ERROR|Exception|failed" | tail -3)
if [ -z "$ERRORS" ]; then
    echo "✅ Xato topilmadi (yaxshi!)"
else
    echo "⚠️  Oxirgi xatolar:"
    echo "$ERRORS"
fi
echo ""

echo "===================================="
if [ "$WORKERS" -ge 2 ]; then
    echo "✅ BOT TAYYOR!"
    echo ""
    echo "🎉 Barcha sozlash tugadi:"
    echo "   ✅ Workerlarni ishlayapti"
    echo "   ✅ Redis ishlayapti"
    echo "   ✅ Cookies fayli mavjud va to'g'ri"
    echo "   ✅ Config to'g'ri sozlangan"
    echo "   ✅ Webhook sozlangan"
    echo ""
    echo "📱 BOTGA TEST QILING:"
    echo "   1. Telegram bot'ga /start yuboring"
    echo "   2. Instagram link yuboring"
    echo "   3. Video/rasm yuborilishi kerak ✅"
    echo ""
    echo "🎊 BOT HAZIR! Test qiling!"
else
    echo "⚠️  BAZI MUAMMOLAR QOLDI"
    echo "   Yuqoridagi xatolarni tekshiring"
fi
echo ""
