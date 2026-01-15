#!/bin/bash

echo "📦 yt-dlp o'rnatish..."
echo ""

# 1. Snap orqali o'rnatish (eng oson, sudo parol kerak emas)
echo "1️⃣ Snap orqali o'rnatish (tavsiya etiladi)..."
if command -v snap &> /dev/null; then
    echo "   Snap mavjud, yt-dlp o'rnatilmoqda..."
    snap install yt-dlp
    if [ $? -eq 0 ]; then
        echo "✅ yt-dlp snap orqali o'rnatildi"
        echo "   Path: /snap/bin/yt-dlp"
        echo ""
        echo "⚠️  .env faylida YT_DLP_PATH ni o'zgartirish kerak:"
        echo "   YT_DLP_PATH=/snap/bin/yt-dlp"
        exit 0
    fi
else
    echo "   Snap topilmadi"
fi
echo ""

# 2. Apt orqali o'rnatish
echo "2️⃣ Apt orqali o'rnatish..."
if command -v apt &> /dev/null; then
    echo "   Apt mavjud, yt-dlp o'rnatilmoqda..."
    sudo apt update
    sudo apt install -y yt-dlp
    if [ $? -eq 0 ]; then
        echo "✅ yt-dlp apt orqali o'rnatildi"
        yt-dlp --version
        exit 0
    fi
else
    echo "   Apt topilmadi"
fi
echo ""

# 3. pip3 orqali user space'da o'rnatish (sudo parol kerak emas)
echo "3️⃣ pip3 orqali user space'da o'rnatish..."
if command -v pip3 &> /dev/null; then
    echo "   pip3 mavjud, yt-dlp o'rnatilmoqda..."
    pip3 install --user yt-dlp
    if [ $? -eq 0 ]; then
        echo "✅ yt-dlp pip3 orqali o'rnatildi"
        ~/.local/bin/yt-dlp --version 2>/dev/null || python3 -m yt_dlp --version
        echo ""
        echo "⚠️  .env faylida YT_DLP_PATH ni o'zgartirish kerak:"
        if [ -f ~/.local/bin/yt-dlp ]; then
            echo "   YT_DLP_PATH=$HOME/.local/bin/yt-dlp"
        else
            echo "   YT_DLP_PATH=python3 -m yt_dlp"
        fi
        exit 0
    fi
else
    echo "   pip3 topilmadi"
fi
echo ""

# 4. Binary orqali o'rnatish
echo "4️⃣ Binary orqali o'rnatish..."
mkdir -p ~/bin
cd ~/bin
wget https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -O yt-dlp
chmod +x yt-dlp
if [ -f ~/bin/yt-dlp ]; then
    echo "✅ yt-dlp binary orqali o'rnatildi"
    ~/bin/yt-dlp --version
    echo ""
    echo "⚠️  .env faylida YT_DLP_PATH ni o'zgartirish kerak:"
    echo "   YT_DLP_PATH=$HOME/bin/yt-dlp"
    echo ""
    echo "⚠️  PATH ga qo'shish:"
    echo "   export PATH=\$PATH:\$HOME/bin"
    echo "   (Bu qatorni ~/.bashrc yoki ~/.profile ga qo'shing)"
else
    echo "❌ Binary yuklab olinmadi"
fi

echo ""
echo "✅ Tugadi!"
