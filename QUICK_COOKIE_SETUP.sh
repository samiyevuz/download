#!/bin/bash

echo "🍪 Instagram Cookie Fayllarini Tez Sozlash"
echo "==========================================="
echo ""

cd ~/www/download.e-qarz.uz

# Cookie papkasini yaratish
COOKIES_DIR="storage/app/cookies"
mkdir -p "$COOKIES_DIR"

echo "📋 Qadam 1: Cookie fayllarini tekshirish"
echo "----------------------------------------"
echo ""

# Hozirgi holatni ko'rsatish
if [ -f "$COOKIES_DIR/instagram_cookies.txt" ]; then
    SIZE=$(stat -f%z "$COOKIES_DIR/instagram_cookies.txt" 2>/dev/null || stat -c%s "$COOKIES_DIR/instagram_cookies.txt" 2>/dev/null)
    if [ "$SIZE" -gt 100 ]; then
        echo "✅ instagram_cookies.txt mavjud (hajm: ${SIZE} bytes)"
        
        if grep -q "sessionid" "$COOKIES_DIR/instagram_cookies.txt" 2>/dev/null; then
            echo "   ✅ sessionid topildi"
        else
            echo "   ⚠️  sessionid topilmadi - cookie noto'g'ri!"
        fi
    else
        echo "⚠️  instagram_cookies.txt mavjud, lekin juda kichik (${SIZE} bytes)"
    fi
else
    echo "❌ instagram_cookies.txt topilmadi"
    echo ""
    echo "📝 Qanday qo'shish:"
    echo "   1. Instagram.com'ga kirib, login qiling"
    echo "   2. EditThisCookie extension orqali cookie'larni export qiling"
    echo "   3. Quyidagi buyruqni bajaring:"
    echo ""
    echo "   nano $COOKIES_DIR/instagram_cookies.txt"
    echo ""
    echo "   4. Export qilingan cookie'larni yozing va saqlang (Ctrl+O, Enter, Ctrl+X)"
    echo ""
    exit 1
fi

echo ""

if [ -f "$COOKIES_DIR/instagram_cookies2.txt" ]; then
    SIZE=$(stat -f%z "$COOKIES_DIR/instagram_cookies2.txt" 2>/dev/null || stat -c%s "$COOKIES_DIR/instagram_cookies2.txt" 2>/dev/null)
    if [ "$SIZE" -gt 100 ]; then
        echo "✅ instagram_cookies2.txt mavjud (hajm: ${SIZE} bytes)"
        
        if grep -q "sessionid" "$COOKIES_DIR/instagram_cookies2.txt" 2>/dev/null; then
            echo "   ✅ sessionid topildi"
        else
            echo "   ⚠️  sessionid topilmadi - cookie noto'g'ri!"
        fi
    else
        echo "⚠️  instagram_cookies2.txt mavjud, lekin juda kichik (${SIZE} bytes)"
    fi
else
    echo "ℹ️  instagram_cookies2.txt mavjud emas (ixtiyoriy, lekin tavsiya etiladi)"
    echo ""
    echo "💡 Ikkinchi cookie qo'shish:"
    echo "   1. Boshqa Instagram account'dan cookie export qiling"
    echo "   2. Quyidagi buyruqni bajaring:"
    echo ""
    echo "   nano $COOKIES_DIR/instagram_cookies2.txt"
    echo ""
    echo "   3. Export qilingan cookie'larni yozing va saqlang"
    echo ""
fi

echo ""
echo "=========================================="
echo ""

# Agar barcha cookie fayllar mavjud bo'lsa, setup scriptini ishga tushirish
if [ -f "$COOKIES_DIR/instagram_cookies.txt" ]; then
    echo "📋 Qadam 2: Cookie fayllarini sozlash"
    echo "----------------------------------------"
    echo ""
    
    chmod +x SETUP_MULTIPLE_COOKIES.sh
    ./SETUP_MULTIPLE_COOKIES.sh
    
    echo ""
    echo "=========================================="
    echo "✅ Tugadi!"
    echo ""
    echo "🧪 Test qiling:"
    echo "   1. Botga Instagram rasm linki yuboring"
    echo "   2. Loglarni kuzatib turing:"
    echo "      tail -f storage/logs/laravel.log | grep -E 'cookie|Instagram image'"
    echo ""
else
    echo "⚠️  Avval cookie fayllarini qo'shing!"
    echo ""
    echo "📖 Batafsil yo'riqnoma:"
    echo "   cat COOKIE_SETUP_GUIDE.md"
    echo ""
fi
