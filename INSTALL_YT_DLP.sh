#!/bin/bash

echo "📦 yt-dlp o'rnatish..."
echo ""

cd ~/www/download.e-qarz.uz

# 1. yt-dlp'ni topish yoki o'rnatish
echo "1️⃣ yt-dlp'ni tekshirish va o'rnatish..."

# Avval mavjud yt-dlp'ni topish
YT_DLP_PATH=""

# Check common paths
if [ -f /usr/local/bin/yt-dlp ] && [ -x /usr/local/bin/yt-dlp ]; then
    YT_DLP_PATH="/usr/local/bin/yt-dlp"
    echo "   ✅ yt-dlp topildi: $YT_DLP_PATH"
elif [ -f /usr/bin/yt-dlp ] && [ -x /usr/bin/yt-dlp ]; then
    YT_DLP_PATH="/usr/bin/yt-dlp"
    echo "   ✅ yt-dlp topildi: $YT_DLP_PATH"
elif [ -f /snap/bin/yt-dlp ] && [ -x /snap/bin/yt-dlp ]; then
    YT_DLP_PATH="/snap/bin/yt-dlp"
    echo "   ✅ yt-dlp topildi: $YT_DLP_PATH"
elif [ -f ~/bin/yt-dlp ] && [ -x ~/bin/yt-dlp ]; then
    YT_DLP_PATH="$HOME/bin/yt-dlp"
    echo "   ✅ yt-dlp topildi: $YT_DLP_PATH"
elif [ -f /var/www/sardor/data/bin/yt-dlp ] && [ -x /var/www/sardor/data/bin/yt-dlp ]; then
    YT_DLP_PATH="/var/www/sardor/data/bin/yt-dlp"
    echo "   ✅ yt-dlp topildi: $YT_DLP_PATH"
elif command -v yt-dlp &> /dev/null; then
    YT_DLP_PATH=$(which yt-dlp)
    echo "   ✅ yt-dlp topildi: $YT_DLP_PATH"
else
    echo "   ⚠️  yt-dlp topilmadi, o'rnatilmoqda..."
    
    # Method 1: Try pip3 (user install)
    if command -v pip3 &> /dev/null; then
        echo "   📦 pip3 orqali o'rnatilmoqda..."
        pip3 install --user yt-dlp 2>&1 | grep -v "WARNING\|DEPRECATION" || true
        
        if [ -f ~/.local/bin/yt-dlp ]; then
            YT_DLP_PATH="$HOME/.local/bin/yt-dlp"
            chmod +x "$YT_DLP_PATH"
            echo "   ✅ yt-dlp pip3 orqali o'rnatildi: $YT_DLP_PATH"
        elif python3 -m yt_dlp --version &> /dev/null; then
            YT_DLP_PATH="python3 -m yt_dlp"
            echo "   ✅ yt-dlp python3 modul sifatida o'rnatildi"
        fi
    fi
    
    # Method 2: Download binary if pip3 failed
    if [ -z "$YT_DLP_PATH" ] || [ "$YT_DLP_PATH" = "" ]; then
        echo "   📦 Binary yuklab olinmoqda..."
        mkdir -p ~/bin
        cd ~/bin
        
        if wget -q --timeout=10 https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -O yt-dlp 2>/dev/null; then
            chmod +x yt-dlp
            YT_DLP_PATH="$HOME/bin/yt-dlp"
            echo "   ✅ yt-dlp binary yuklab olindi: $YT_DLP_PATH"
        else
            echo "   ❌ yt-dlp yuklab olinmadi"
            echo "   💡 Qo'lda o'rnatish uchun:"
            echo "      sudo apt install yt-dlp"
            echo "      yoki"
            echo "      sudo snap install yt-dlp"
            exit 1
        fi
    fi
fi

cd ~/www/download.e-qarz.uz

# 2. Test qilish
echo ""
echo "2️⃣ yt-dlp'ni test qilish..."
if [ "$YT_DLP_PATH" = "python3 -m yt_dlp" ]; then
    python3 -m yt_dlp --version
else
    "$YT_DLP_PATH" --version
fi

if [ $? -eq 0 ]; then
    echo "   ✅ yt-dlp ishlayapti"
else
    echo "   ❌ yt-dlp ishlamayapti"
    exit 1
fi

# 3. .env faylida path'ni sozlash
echo ""
echo "3️⃣ .env faylida YT_DLP_PATH ni sozlash..."
if [ -f .env ]; then
    if grep -q "^YT_DLP_PATH=" .env; then
        sed -i "s|^YT_DLP_PATH=.*|YT_DLP_PATH=$YT_DLP_PATH|" .env
        echo "   ✅ YT_DLP_PATH yangilandi: $YT_DLP_PATH"
    else
        echo "YT_DLP_PATH=$YT_DLP_PATH" >> .env
        echo "   ✅ YT_DLP_PATH qo'shildi: $YT_DLP_PATH"
    fi
else
    echo "YT_DLP_PATH=$YT_DLP_PATH" > .env
    echo "   ✅ .env fayli yaratildi va YT_DLP_PATH qo'shildi: $YT_DLP_PATH"
fi

# 4. Config'ni yangilash
echo ""
echo "4️⃣ Config'ni yangilash..."
php artisan config:clear
php artisan config:cache
echo "   ✅ Config yangilandi"
echo ""

# 5. Tekshirish
echo "5️⃣ Tekshirish..."
echo "   .env faylida:"
grep YT_DLP_PATH .env
echo ""
echo "   Config'da:"
php artisan tinker --execute="echo config('telegram.yt_dlp_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1
echo ""

echo "===================================="
echo "✅ yt-dlp muvaffaqiyatli o'rnatildi va sozlandi!"
echo ""
echo "📝 Path: $YT_DLP_PATH"
echo ""
echo "🎉 Endi botga Instagram link yuborib test qiling!"
echo ""
