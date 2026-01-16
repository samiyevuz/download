#!/bin/bash

echo "🔍 Majburiy Azolik Tekshiruvi"
echo "=============================="
echo ""

cd ~/www/download.e-qarz.uz

# 1. .env faylini tekshirish
echo "1️⃣ .env faylini tekshirish..."
if [ -f .env ]; then
    echo "✅ .env fayli mavjud"
    
    if grep -q "TELEGRAM_REQUIRED_CHANNELS" .env; then
        REQUIRED_CHANNELS=$(grep "TELEGRAM_REQUIRED_CHANNELS" .env | cut -d'=' -f2)
        echo "   TELEGRAM_REQUIRED_CHANNELS: $REQUIRED_CHANNELS"
    else
        echo "   ⚠️  TELEGRAM_REQUIRED_CHANNELS topilmadi"
    fi
    
    if grep -q "TELEGRAM_REQUIRED_CHANNEL_ID" .env; then
        CHANNEL_ID=$(grep "TELEGRAM_REQUIRED_CHANNEL_ID" .env | cut -d'=' -f2)
        echo "   TELEGRAM_REQUIRED_CHANNEL_ID: $CHANNEL_ID"
    fi
    
    if grep -q "TELEGRAM_REQUIRED_CHANNEL_USERNAME" .env; then
        CHANNEL_USERNAME=$(grep "TELEGRAM_REQUIRED_CHANNEL_USERNAME" .env | cut -d'=' -f2)
        echo "   TELEGRAM_REQUIRED_CHANNEL_USERNAME: $CHANNEL_USERNAME"
    fi
else
    echo "❌ .env fayli topilmadi"
fi
echo ""

# 2. Config'ni tekshirish
echo "2️⃣ Config'ni tekshirish..."
REQUIRED_CHANNELS_CONFIG=$(php artisan tinker --execute="echo config('telegram.required_channels');" 2>&1 | grep -v "Psy\|tinker" | tail -1)
CHANNEL_ID_CONFIG=$(php artisan tinker --execute="echo config('telegram.required_channel_id');" 2>&1 | grep -v "Psy\|tinker" | tail -1)
CHANNEL_USERNAME_CONFIG=$(php artisan tinker --execute="echo config('telegram.required_channel_username');" 2>&1 | grep -v "Psy\|tinker" | tail -1)

echo "   Config'dan olingan:"
echo "   required_channels: $REQUIRED_CHANNELS_CONFIG"
echo "   required_channel_id: $CHANNEL_ID_CONFIG"
echo "   required_channel_username: $CHANNEL_USERNAME_CONFIG"
echo ""

# 3. getRequiredChannels metodini test qilish
echo "3️⃣ getRequiredChannels metodini test qilish..."
php artisan tinker --execute="
\$service = app(\App\Services\TelegramService::class);
\$reflection = new ReflectionClass(\$service);
\$method = \$reflection->getMethod('getRequiredChannels');
\$method->setAccessible(true);
\$channels = \$method->invoke(\$service);
echo 'Channels: ' . json_encode(\$channels) . PHP_EOL;
" 2>&1 | grep -v "Psy\|tinker" | tail -5
echo ""

# 4. Bot token tekshirish
echo "4️⃣ Bot token tekshirish..."
BOT_TOKEN=$(php artisan tinker --execute="echo config('telegram.bot_token');" 2>&1 | grep -v "Psy\|tinker" | tail -1)

if [ -n "$BOT_TOKEN" ] && [ "$BOT_TOKEN" != "null" ]; then
    echo "✅ Bot token mavjud"
    BOT_INFO=$(curl -s "https://api.telegram.org/bot${BOT_TOKEN}/getMe" 2>/dev/null)
    if echo "$BOT_INFO" | grep -q '"ok":true'; then
        BOT_USERNAME=$(echo "$BOT_INFO" | grep -o '"username":"[^"]*"' | cut -d'"' -f4)
        echo "   Bot username: @$BOT_USERNAME"
    else
        echo "   ⚠️  Bot token noto'g'ri yoki bot topilmadi"
    fi
else
    echo "❌ Bot token topilmadi"
fi
echo ""

# 5. Xulosa
echo "===================================="
echo "📊 Xulosa:"
echo ""

if [ -z "$REQUIRED_CHANNELS_CONFIG" ] || [ "$REQUIRED_CHANNELS_CONFIG" = "null" ] || [ "$REQUIRED_CHANNELS_CONFIG" = "" ]; then
    echo "⚠️  Majburiy kanallar sozlanmagan"
    echo ""
    echo "📝 Qanday sozlash:"
    echo "   .env faylida qo'shing:"
    echo "   TELEGRAM_REQUIRED_CHANNELS=TheUzSoft,samiyev_blog"
    echo ""
    echo "   Keyin:"
    echo "   php artisan config:clear && php artisan config:cache"
else
    echo "✅ Majburiy kanallar sozlangan: $REQUIRED_CHANNELS_CONFIG"
    echo ""
    echo "⚠️  MUHIM: Bot kanallarga admin bo'lishi kerak!"
    echo "   - Botni har bir kanalga admin qiling"
    echo "   - 'View Members' permission berilishi kerak"
    echo ""
    echo "📝 Test qilish:"
    echo "   1. Botga /start yuboring"
    echo "   2. Til tanlang"
    echo "   3. Agar a'zo bo'lmagan bo'lsangiz, subscription message ko'rinishi kerak"
    echo "   4. Kanalga a'zo bo'ling"
    echo "   5. ✅ Tekshirish tugmasini bosing"
fi
echo ""
