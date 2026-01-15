#!/bin/bash

echo "📦 yt-dlp binary yuklab olish..."
echo ""

# 1. Bin directory yaratish
echo "1️⃣ Bin directory yaratish..."
mkdir -p ~/bin
cd ~/bin

# 2. yt-dlp yuklab olish
echo "2️⃣ yt-dlp yuklab olinmoqda..."
if [ -f yt-dlp ]; then
    echo "   yt-dlp allaqachon mavjud, yangilash..."
    rm -f yt-dlp
fi

wget -q https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -O yt-dlp
if [ $? -eq 0 ]; then
    chmod +x yt-dlp
    echo "✅ yt-dlp yuklab olindi"
else
    echo "❌ yt-dlp yuklab olinmadi"
    exit 1
fi

# 3. Tekshirish
echo "3️⃣ yt-dlp'ni test qilish..."
~/bin/yt-dlp --version
if [ $? -eq 0 ]; then
    echo "✅ yt-dlp ishlayapti"
    YT_DLP_PATH="$HOME/bin/yt-dlp"
else
    echo "❌ yt-dlp ishlamayapti"
    exit 1
fi

# 4. .env faylida path'ni sozlash
echo ""
echo "4️⃣ .env faylida YT_DLP_PATH ni sozlash..."
cd ~/www/download.e-qarz.uz

if grep -q "^YT_DLP_PATH=" .env; then
    sed -i "s|^YT_DLP_PATH=.*|YT_DLP_PATH=$YT_DLP_PATH|" .env
    echo "✅ YT_DLP_PATH yangilandi: $YT_DLP_PATH"
else
    echo "YT_DLP_PATH=$YT_DLP_PATH" >> .env
    echo "✅ YT_DLP_PATH qo'shildi: $YT_DLP_PATH"
fi

# 5. Config'ni yangilash
echo ""
echo "5️⃣ Config'ni yangilash..."
php artisan config:clear
php artisan config:cache
echo "✅ Config yangilandi"

# 6. PATH ga qo'shish (hozirgi sessiya uchun)
echo ""
echo "6️⃣ PATH ga qo'shish..."
export PATH=$PATH:$HOME/bin
echo "✅ PATH yangilandi (hozirgi sessiya uchun)"

echo ""
echo "✅ yt-dlp muvaffaqiyatli o'rnatildi!"
echo ""
echo "📝 Tekshirish:"
echo "   ~/bin/yt-dlp --version"
echo ""
echo "⚠️  PATH'ni doimiy qilish uchun ~/.bashrc ga qo'shing:"
echo "   echo 'export PATH=\$PATH:\$HOME/bin' >> ~/.bashrc"
echo ""
echo "🎉 Endi botga Instagram link yuborib test qiling!"
