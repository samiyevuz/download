#!/bin/bash

echo "📦 yt-dlp o'rnatish (sudo parolsiz)..."
echo ""

# 1. pip3 orqali user space'da o'rnatish
echo "1️⃣ pip3 orqali user space'da o'rnatish..."
if command -v pip3 &> /dev/null; then
    echo "   pip3 mavjud, yt-dlp o'rnatilmoqda..."
    pip3 install --user yt-dlp
    if [ $? -eq 0 ]; then
        echo "✅ yt-dlp pip3 orqali o'rnatildi"
        
        # Tekshirish
        if [ -f ~/.local/bin/yt-dlp ]; then
            ~/.local/bin/yt-dlp --version
            YT_DLP_PATH="$HOME/.local/bin/yt-dlp"
        else
            python3 -m yt_dlp --version
            YT_DLP_PATH="python3 -m yt_dlp"
        fi
        
        echo ""
        echo "2️⃣ .env faylida YT_DLP_PATH ni sozlash..."
        if grep -q "^YT_DLP_PATH=" .env; then
            sed -i "s|^YT_DLP_PATH=.*|YT_DLP_PATH=$YT_DLP_PATH|" .env
        else
            echo "YT_DLP_PATH=$YT_DLP_PATH" >> .env
        fi
        echo "✅ YT_DLP_PATH=$YT_DLP_PATH"
        
        echo ""
        echo "3️⃣ Config'ni yangilash..."
        php artisan config:clear
        php artisan config:cache
        echo "✅ Config yangilandi"
        
        exit 0
    else
        echo "❌ pip3 orqali o'rnatishda xato"
    fi
else
    echo "❌ pip3 topilmadi"
fi
echo ""

# 2. Binary orqali o'rnatish
echo "4️⃣ Binary orqali o'rnatish..."
mkdir -p ~/bin
cd ~/bin

if [ -f yt-dlp ]; then
    echo "   yt-dlp allaqachon mavjud"
    chmod +x yt-dlp
else
    echo "   yt-dlp yuklab olinmoqda..."
    wget -q https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -O yt-dlp
    if [ $? -eq 0 ]; then
        chmod +x yt-dlp
        echo "✅ yt-dlp yuklab olindi"
    else
        echo "❌ yt-dlp yuklab olinmadi"
        exit 1
    fi
fi

# Tekshirish
if [ -f ~/bin/yt-dlp ]; then
    ~/bin/yt-dlp --version
    YT_DLP_PATH="$HOME/bin/yt-dlp"
    
    echo ""
    echo "5️⃣ .env faylida YT_DLP_PATH ni sozlash..."
    if grep -q "^YT_DLP_PATH=" .env; then
        sed -i "s|^YT_DLP_PATH=.*|YT_DLP_PATH=$YT_DLP_PATH|" .env
    else
        echo "YT_DLP_PATH=$YT_DLP_PATH" >> .env
    fi
    echo "✅ YT_DLP_PATH=$YT_DLP_PATH"
    
    echo ""
    echo "6️⃣ PATH ga qo'shish (hozirgi sessiya uchun)..."
    export PATH=$PATH:$HOME/bin
    echo "✅ PATH yangilandi"
    
    echo ""
    echo "7️⃣ Config'ni yangilash..."
    cd ~/www/download.e-qarz.uz
    php artisan config:clear
    php artisan config:cache
    echo "✅ Config yangilandi"
    
    echo ""
    echo "✅ yt-dlp muvaffaqiyatli o'rnatildi!"
    echo ""
    echo "⚠️  PATH'ni doimiy qilish uchun ~/.bashrc ga qo'shing:"
    echo "   echo 'export PATH=\$PATH:\$HOME/bin' >> ~/.bashrc"
else
    echo "❌ yt-dlp o'rnatilmadi"
    exit 1
fi
