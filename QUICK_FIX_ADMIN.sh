#!/bin/bash

echo "🔧 Botni kanalga admin qilish - Tezkor yechim"
echo "============================================="
echo ""

cd /var/www/sardor/data/www/download.e-qarz.uz

# 1. Bot ma'lumotlarini olish
echo "1️⃣ Bot ma'lumotlarini olish..."
BOT_TOKEN=$(php artisan tinker --execute="echo config('telegram.bot_token');" 2>&1 | grep -v "Psy\|tinker" | tail -1)
BOT_INFO=$(curl -s "https://api.telegram.org/bot${BOT_TOKEN}/getMe")
BOT_USERNAME=$(echo $BOT_INFO | grep -o '"username":"[^"]*"' | cut -d'"' -f4)
BOT_ID=$(echo $BOT_INFO | grep -o '"id":[0-9]*' | cut -d':' -f2)

if [ -z "$BOT_USERNAME" ]; then
    echo "❌ Bot ma'lumotlari topilmadi!"
    exit 1
fi

echo "   Bot username: @$BOT_USERNAME"
echo "   Bot ID: $BOT_ID"
echo ""

# 2. Kanal ma'lumotlarini olish
echo "2️⃣ Kanal ma'lumotlarini olish..."
REQUIRED_CHANNELS=$(php artisan tinker --execute="echo config('telegram.required_channels');" 2>&1 | grep -v "Psy\|tinker" | tail -1)

if [ -z "$REQUIRED_CHANNELS" ] || [ "$REQUIRED_CHANNELS" = "null" ]; then
    echo "❌ Config'da kanallar topilmadi!"
    echo "   .env faylida TELEGRAM_REQUIRED_CHANNELS=TheUzSoft,samiyev_blog ni qo'shing"
    exit 1
fi

echo "   Required channels: $REQUIRED_CHANNELS"
echo ""

# 3. Har bir kanalni tekshirish
echo "3️⃣ Har bir kanalni tekshirish..."
IFS=',' read -ra CHANNELS <<< "$REQUIRED_CHANNELS"
ALL_ADMIN=true

for channel in "${CHANNELS[@]}"; do
    channel=$(echo "$channel" | xargs | sed 's/^@//')
    echo "   Kanal: @$channel"
    
    # Check bot's status in channel
    CHAT_MEMBER=$(curl -s "https://api.telegram.org/bot${BOT_TOKEN}/getChatMember?chat_id=@${channel}&user_id=${BOT_ID}")
    
    if echo "$CHAT_MEMBER" | grep -q '"status":"administrator"'; then
        echo "   ✅ Bot kanalga admin"
    else
        echo "   ❌ Bot kanalga admin emas!"
        ALL_ADMIN=false
        echo ""
        echo "   📝 Qanday qilish kerak:"
        echo "      1. Telegram'da @${channel} kanalini oching"
        echo "      2. Kanal sozlamalariga kiring"
        echo "      3. Administrators → Add Administrator"
        echo "      4. @${BOT_USERNAME} ni qo'shing"
        echo "      5. 'View Members' huquqini bering (MUHIM!)"
        echo "      6. Save qiling"
        echo ""
    fi
done

echo ""

if [ "$ALL_ADMIN" = true ]; then
    echo "===================================="
    echo "✅ Barcha kanallarda bot admin!"
    echo ""
    echo "📝 Keyingi qadamlar:"
    echo "   1. Config cache'ni yangilang: php artisan config:cache"
    echo "   2. Workerlarni qayta ishga tushiring"
    echo "   3. Botga /start yuborib test qiling"
    echo ""
else
    echo "===================================="
    echo "❌ Bot ba'zi kanallarda admin emas!"
    echo ""
    echo "⚠️  Botni kanalga admin qilguncha, a'zolikni tekshirish ishlamaydi!"
    echo ""
    echo "📖 Batafsil ko'rsatma: SETUP_BOT_AS_ADMIN.md faylini ko'ring"
    echo ""
fi
