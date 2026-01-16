#!/bin/bash

echo "🔍 A'zolik muammosini debug qilish..."
echo ""

cd /var/www/sardor/data/www/download.e-qarz.uz

# 1. Config'ni tekshirish
echo "1️⃣ Config'ni tekshirish..."
php artisan config:clear
php artisan config:cache
REQUIRED_CHANNELS=$(php artisan tinker --execute="echo config('telegram.required_channels');" 2>&1 | grep -v "Psy\|tinker" | tail -1)
echo "   Required channels: $REQUIRED_CHANNELS"
echo ""

# 2. Bot va kanal holatini tekshirish
echo "2️⃣ Bot va kanal holatini tekshirish..."
chmod +x TEST_CHANNEL_SUBSCRIPTION.sh
./TEST_CHANNEL_SUBSCRIPTION.sh
echo ""

# 3. Oxirgi loglarni ko'rish
echo "3️⃣ Oxirgi subscription loglarni ko'rish..."
echo "   Oxirgi 50 qator:"
tail -50 storage/logs/laravel.log | grep -i "subscription\|channel\|membership\|checking" | tail -20
echo ""

# 4. API test qilish
echo "4️⃣ API test qilish..."
BOT_TOKEN=$(php artisan tinker --execute="echo config('telegram.bot_token');" 2>&1 | grep -v "Psy\|tinker" | tail -1)
BOT_INFO=$(curl -s "https://api.telegram.org/bot${BOT_TOKEN}/getMe")
BOT_ID=$(echo $BOT_INFO | grep -o '"id":[0-9]*' | cut -d':' -f2)

IFS=',' read -ra CHANNELS <<< "$REQUIRED_CHANNELS"
for channel in "${CHANNELS[@]}"; do
    channel=$(echo "$channel" | xargs | sed 's/^@//')
    echo "   Kanal: @$channel"
    
    # Test getChatMember with bot's own ID (to see if bot can check)
    echo "   Botning o'zini tekshirish (bot admin bo'lishi kerak):"
    curl -s "https://api.telegram.org/bot${BOT_TOKEN}/getChatMember?chat_id=@${channel}&user_id=${BOT_ID}" | python3 -m json.tool 2>/dev/null || echo "   JSON parse xatosi"
    echo ""
done

echo "===================================="
echo "📝 Xulosa va yechimlar:"
echo ""
echo "⚠️  Agar bot admin bo'lmasa:"
echo "   1. Kanal sozlamalariga kiring"
echo "   2. Administrators → Add Administrator"
echo "   3. Botni qo'shing"
echo "   4. 'Members' ni ko'rish huquqini bering"
echo ""
echo "⚠️  Agar bot admin bo'lsa, lekin hali ham ishlamasa:"
echo "   1. Loglarni to'liq ko'ring: tail -100 storage/logs/laravel.log"
echo "   2. Botni qayta ishga tushiring"
echo "   3. Config cache'ni yangilang: php artisan config:cache"
echo ""
echo "🔍 Real-time debug:"
echo "   tail -f storage/logs/laravel.log | grep -i 'subscription\|channel\|membership'"
echo ""
