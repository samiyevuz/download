#!/bin/bash

echo "🔧 yt-dlp path'ni to'g'rilash..."
echo ""

# 1. To'g'ri path'ni aniqlash
echo "1️⃣ To'g'ri path'ni aniqlash..."
if [ -f ~/bin/yt-dlp ]; then
    CORRECT_PATH="$HOME/bin/yt-dlp"
elif [ -f /var/www/sardor/data/bin/yt-dlp ]; then
    CORRECT_PATH="/var/www/sardor/data/bin/yt-dlp"
else
    echo "❌ yt-dlp topilmadi!"
    exit 1
fi

echo "   To'g'ri path: $CORRECT_PATH"
echo ""

# 2. .env faylidan noto'g'ri path'larni olib tashlash
echo "2️⃣ .env faylida YT_DLP_PATH ni to'g'rilash..."
# Barcha YT_DLP_PATH qatorlarini olib tashlash
sed -i '/^YT_DLP_PATH=/d' .env

# To'g'ri path'ni qo'shish
echo "YT_DLP_PATH=$CORRECT_PATH" >> .env
echo "✅ YT_DLP_PATH to'g'rilandi: $CORRECT_PATH"
echo ""

# 3. Config'ni yangilash
echo "3️⃣ Config'ni yangilash..."
php artisan config:clear
php artisan config:cache
echo "✅ Config yangilandi"
echo ""

# 4. Tekshirish
echo "4️⃣ Tekshirish..."
echo "   .env faylida:"
grep YT_DLP_PATH .env
echo ""
echo "   Config'da:"
php artisan tinker --execute="echo config('telegram.yt_dlp_path');" 2>&1 | grep -v "Psy\|tinker" || echo "Config test"
echo ""

# 5. yt-dlp'ni test qilish
echo "5️⃣ yt-dlp'ni test qilish..."
$CORRECT_PATH --version
if [ $? -eq 0 ]; then
    echo "✅ yt-dlp ishlayapti"
else
    echo "❌ yt-dlp ishlamayapti"
fi
echo ""

echo "✅ Barcha o'zgarishlar amalga oshirildi!"
echo ""
echo "🎉 Endi botga Instagram link yuborib test qiling!"
