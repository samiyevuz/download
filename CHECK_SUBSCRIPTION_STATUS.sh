#!/bin/bash

echo "🔍 Subscription Status Tekshirish"
echo "=================================="
echo ""

cd ~/www/download.e-qarz.uz

# 1. Cache'dagi membership natijalarini ko'rish
echo "1️⃣ Membership Cache Tekshirish..."
USER_ID="7730989535"  # Test user ID (o'zgartiring)
REQUIRED_CHANNELS=$(php artisan tinker --execute="echo config('telegram.required_channels');" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')

if [ -n "$REQUIRED_CHANNELS" ] && [ "$REQUIRED_CHANNELS" != "null" ]; then
    CHANNELS_HASH=$(echo -n "$REQUIRED_CHANNELS" | md5sum | cut -d' ' -f1)
    CACHE_KEY="channel_membership_${USER_ID}_${CHANNELS_HASH}"
    
    echo "   Cache key: $CACHE_KEY"
    echo "   Required channels: $REQUIRED_CHANNELS"
    
    CACHED_VALUE=$(php artisan tinker --execute="echo json_encode(Cache::get('$CACHE_KEY'));" 2>&1 | grep -v "Psy\|tinker" | tail -1)
    
    if [ -n "$CACHED_VALUE" ] && [ "$CACHED_VALUE" != "null" ]; then
        echo "   ⚠️  Cache'da natija bor: $CACHED_VALUE"
        echo "   💡 Cache'ni tozalash: php artisan tinker --execute=\"Cache::forget('$CACHE_KEY');\""
    else
        echo "   ✅ Cache bo'sh (fresh check qilinadi)"
    fi
else
    echo "   ⚠️  Required channels sozlanmagan"
fi
echo ""

# 2. Required channels
echo "2️⃣ Required Channels..."
REQUIRED_CHANNELS=$(php artisan tinker --execute="echo config('telegram.required_channels');" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')
CHANNEL_ID=$(php artisan tinker --execute="echo config('telegram.required_channel_id');" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')
CHANNEL_USERNAME=$(php artisan tinker --execute="echo config('telegram.required_channel_username');" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')

if [ -n "$REQUIRED_CHANNELS" ] && [ "$REQUIRED_CHANNELS" != "null" ]; then
    echo "   ✅ Required channels: $REQUIRED_CHANNELS"
elif [ -n "$CHANNEL_ID" ] && [ "$CHANNEL_ID" != "null" ]; then
    echo "   ✅ Channel ID: $CHANNEL_ID"
elif [ -n "$CHANNEL_USERNAME" ] && [ "$CHANNEL_USERNAME" != "null" ]; then
    echo "   ✅ Channel username: $CHANNEL_USERNAME"
else
    echo "   ⚠️  Hech qanday kanal sozlanmagan"
fi
echo ""

# 3. Oxirgi loglar
echo "3️⃣ Oxirgi Subscription Check Loglari..."
tail -20 storage/logs/laravel.log | grep -E "Checking subscription|Channel membership check|is_member|missing_channels|Using cached" | tail -10 | sed 's/^/   /'
echo ""

# 4. Cache tozalash
echo "4️⃣ Cache Tozalash (test uchun)..."
read -p "   Cache'ni tozalashni xohlaysizmi? (y/n): " -n 1 -r
echo ""
if [[ $REPLY =~ ^[Yy]$ ]]; then
    if [ -n "$CACHE_KEY" ]; then
        php artisan tinker --execute="Cache::forget('$CACHE_KEY'); echo 'Cache cleared';" 2>&1 | grep -v "Psy\|tinker" | tail -1
        echo "   ✅ Cache tozalandi"
    else
        echo "   ⚠️  Cache key topilmadi"
    fi
fi
echo ""

echo "===================================="
echo "✅ Tekshirish tugadi!"
echo ""
echo "💡 Subscription test qiling:"
echo "   1. Bot'ga /start yuboring"
echo "   2. Til tanlang"
echo "   3. Agar kanallarga a'zo bo'lmagan bo'lsangiz, subscription message ko'rinishi kerak"
echo "   4. Kanallarga a'zo bo'ling"
echo "   5. ✅ Tekshirish tugmasini bosing"
echo "   6. Cache tozalanishi kerak va yangi tekshiruv qilinishi kerak"
echo ""
