#!/bin/bash

echo "🔄 yt-dlp Versiyasini Yangilash"
echo "================================="
echo ""

cd ~/www/download.e-qarz.uz || exit 1

# 1. Hozirgi yt-dlp path'ni aniqlash
echo "1️⃣ Hozirgi yt-dlp path'ni aniqlash..."
YT_DLP_PATH=$(php artisan tinker --execute="echo config('telegram.yt_dlp_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')

if [ -z "$YT_DLP_PATH" ] || [ "$YT_DLP_PATH" = "null" ]; then
    # Try common paths
    if [ -f "/var/www/sardor/data/bin/yt-dlp" ]; then
        YT_DLP_PATH="/var/www/sardor/data/bin/yt-dlp"
    elif [ -f "$HOME/bin/yt-dlp" ]; then
        YT_DLP_PATH="$HOME/bin/yt-dlp"
    else
        YT_DLP_PATH="yt-dlp"
    fi
fi

echo "   📋 Hozirgi path: $YT_DLP_PATH"

# Check if path is executable
if [ -f "$YT_DLP_PATH" ] && [ -x "$YT_DLP_PATH" ]; then
    CURRENT_VERSION=$("$YT_DLP_PATH" --version 2>/dev/null || echo "unknown")
    echo "   📊 Hozirgi versiya: $CURRENT_VERSION"
else
    echo "   ⚠️  yt-dlp topilmadi yoki executable emas"
    
    # Try to find yt-dlp
    if command -v yt-dlp &> /dev/null; then
        YT_DLP_PATH=$(which yt-dlp)
        echo "   ✅ PATH'dan topildi: $YT_DLP_PATH"
    elif [ -f "/var/www/sardor/data/bin/yt-dlp" ]; then
        YT_DLP_PATH="/var/www/sardor/data/bin/yt-dlp"
        chmod +x "$YT_DLP_PATH"
        echo "   ✅ To'g'ri path topildi: $YT_DLP_PATH"
    else
        echo "   ❌ yt-dlp topilmadi!"
        echo ""
        echo "   💡 O'rnatish uchun:"
        echo "      ./INSTALL_YT_DLP.sh"
        exit 1
    fi
fi
echo ""

# 2. Yangi versiyani yuklab olish
echo "2️⃣ Yangi versiyani yuklab olish..."
YT_DLP_DIR=$(dirname "$YT_DLP_PATH")
YT_DLP_FILE=$(basename "$YT_DLP_PATH")

# Backup old version
if [ -f "$YT_DLP_PATH" ]; then
    BACKUP_PATH="${YT_DLP_PATH}.backup.$(date +%Y%m%d_%H%M%S)"
    cp "$YT_DLP_PATH" "$BACKUP_PATH"
    echo "   💾 Eski versiya backup qilindi: $BACKUP_PATH"
fi

# Download latest version
cd "$YT_DLP_DIR" || exit 1
echo "   📥 Yangi versiya yuklanmoqda..."
wget -q --show-progress https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -O "$YT_DLP_FILE" 2>&1

if [ $? -eq 0 ] && [ -f "$YT_DLP_FILE" ]; then
    chmod +x "$YT_DLP_FILE"
    echo "   ✅ Yangi versiya yuklab olindi"
else
    echo "   ❌ Yangilashda xato!"
    
    # Restore backup if exists
    if [ -f "$BACKUP_PATH" ]; then
        cp "$BACKUP_PATH" "$YT_DLP_PATH"
        echo "   🔄 Eski versiya tiklandi"
    fi
    exit 1
fi
echo ""

# 3. Yangi versiyani tekshirish
echo "3️⃣ Yangi versiyani tekshirish..."
NEW_VERSION=$("$YT_DLP_PATH" --version 2>/dev/null || echo "unknown")

if [ "$NEW_VERSION" != "unknown" ]; then
    echo "   ✅ Yangi versiya: $NEW_VERSION"
    
    if [ "$CURRENT_VERSION" != "$NEW_VERSION" ]; then
        echo "   ✨ Versiya yangilandi: $CURRENT_VERSION → $NEW_VERSION"
    else
        echo "   ℹ️  Versiya allaqachon eng yangi"
    fi
else
    echo "   ❌ Versiya tekshirishda xato!"
    exit 1
fi
echo ""

# 4. .env faylida path'ni yangilash
echo "4️⃣ .env faylida path'ni yangilash..."
cd ~/www/download.e-qarz.uz || exit 1

if grep -q "^YT_DLP_PATH=" .env 2>/dev/null; then
    sed -i "s|^YT_DLP_PATH=.*|YT_DLP_PATH=$YT_DLP_PATH|" .env
    echo "   ✅ YT_DLP_PATH yangilandi: $YT_DLP_PATH"
else
    echo "YT_DLP_PATH=$YT_DLP_PATH" >> .env
    echo "   ✅ YT_DLP_PATH qo'shildi: $YT_DLP_PATH"
fi
echo ""

# 5. Config'ni yangilash
echo "5️⃣ Config'ni yangilash..."
php artisan config:clear
php artisan cache:clear
php artisan config:cache
echo "   ✅ Config yangilandi"
echo ""

# 6. Test qilish
echo "6️⃣ yt-dlp test qilish..."
if "$YT_DLP_PATH" --version > /dev/null 2>&1; then
    echo "   ✅ yt-dlp ishlayapti"
else
    echo "   ❌ yt-dlp ishlamayapti!"
    exit 1
fi
echo ""

echo "===================================="
echo "✅ yt-dlp versiyasi yangilandi!"
echo ""
echo "📊 Ma'lumot:"
echo "   Path: $YT_DLP_PATH"
echo "   Versiya: $NEW_VERSION"
echo ""
echo "🧪 Test qiling:"
echo "   $YT_DLP_PATH --version"
echo "   $YT_DLP_PATH --dump-json 'https://www.instagram.com/p/DTfskYniP0g/'"
echo ""
echo "📝 Instagram rasm test:"
echo "   Bot'ga Instagram rasm linkini yuboring va loglarni kuzating:"
echo "   tail -f storage/logs/laravel.log | grep -E 'Instagram|image|JSON|download|No video formats'"
echo ""
