#!/bin/bash

echo "🔍 Kanal a'zoligini tekshirish testi..."
echo ""

cd /var/www/sardor/data/www/download.e-qarz.uz

# 1. Config'ni tekshirish
echo "1️⃣ Config'ni tekshirish..."
REQUIRED_CHANNELS=$(php artisan tinker --execute="echo config('telegram.required_channels');" 2>&1 | grep -v "Psy\|tinker" | tail -1)
echo "   Required channels: $REQUIRED_CHANNELS"

if [ -z "$REQUIRED_CHANNELS" ] || [ "$REQUIRED_CHANNELS" = "null" ]; then
    echo "❌ Config'da kanallar topilmadi!"
    echo "   .env faylida TELEGRAM_REQUIRED_CHANNELS=TheUzSoft,samiyev_blog ni qo'shing"
    exit 1
fi
echo ""

# 2. Bot token'ni tekshirish
echo "2️⃣ Bot token'ni tekshirish..."
BOT_TOKEN=$(php artisan tinker --execute="echo config('telegram.bot_token');" 2>&1 | grep -v "Psy\|tinker" | tail -1)
if [ -z "$BOT_TOKEN" ] || [ "$BOT_TOKEN" = "null" ]; then
    echo "❌ Bot token topilmadi!"
    exit 1
fi
echo "✅ Bot token mavjud"
echo ""

# 3. Bot ma'lumotlarini olish
echo "3️⃣ Bot ma'lumotlarini olish..."
BOT_INFO=$(curl -s "https://api.telegram.org/bot${BOT_TOKEN}/getMe")
BOT_USERNAME=$(echo $BOT_INFO | grep -o '"username":"[^"]*"' | cut -d'"' -f4)
echo "   Bot username: @$BOT_USERNAME"
echo ""

# 4. Kanal ma'lumotlarini tekshirish
echo "4️⃣ Kanal ma'lumotlarini tekshirish..."
IFS=',' read -ra CHANNELS <<< "$REQUIRED_CHANNELS"
for channel in "${CHANNELS[@]}"; do
    channel=$(echo "$channel" | xargs) # trim whitespace
    channel=$(echo "$channel" | sed 's/^@//') # remove @
    
    echo "   Kanal: @$channel"
    
    # Get channel info
    CHANNEL_INFO=$(curl -s "https://api.telegram.org/bot${BOT_TOKEN}/getChat?chat_id=@${channel}")
    
    if echo "$CHANNEL_INFO" | grep -q '"ok":true'; then
        echo "   ✅ Kanal topildi"
        
        # Check if bot is admin
        CHAT_MEMBER=$(curl -s "https://api.telegram.org/bot${BOT_TOKEN}/getChatMember?chat_id=@${channel}&user_id=$(echo $BOT_INFO | grep -o '"id":[0-9]*' | cut -d':' -f2)")
        
        if echo "$CHAT_MEMBER" | grep -q '"status":"administrator"'; then
            echo "   ✅ Bot kanalga admin"
        elif echo "$CHAT_MEMBER" | grep -q '"status":"member"'; then
            echo "   ⚠️  Bot kanalga a'zo, lekin admin emas"
            echo "   ⚠️  Bot kanalni tekshirish uchun admin bo'lishi kerak!"
        elif echo "$CHAT_MEMBER" | grep -q '"status":"left"'; then
            echo "   ❌ Bot kanalga a'zo emas"
            echo "   ⚠️  Botni kanalga qo'shing va admin qiling!"
        else
            echo "   ⚠️  Bot holati: $(echo $CHAT_MEMBER | grep -o '"status":"[^"]*"' | cut -d'"' -f4)"
            echo "   ⚠️  Botni kanalga admin qiling!"
        fi
    else
        echo "   ❌ Kanal topilmadi yoki bot unga kirish huquqiga ega emas"
        ERROR_MSG=$(echo "$CHANNEL_INFO" | grep -o '"description":"[^"]*"' | cut -d'"' -f4)
        echo "   Xato: $ERROR_MSG"
    fi
    echo ""
done

echo "===================================="
echo "📝 Xulosa:"
echo ""
echo "✅ Bot kanalni tekshirish uchun:"
echo "   1. Botni kanalga qo'shing"
echo "   2. Botni kanalga admin qiling"
echo "   3. Botga 'Members' ni ko'rish huquqini bering"
echo ""
echo "💡 Qo'shimcha:"
echo "   - Kanal public bo'lishi kerak (username bo'lishi kerak)"
echo "   - Yoki kanal ID ishlatilsa, bot admin bo'lishi shart"
echo ""
