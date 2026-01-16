#!/bin/bash

echo "🔍 Log Fayllarni Tekshirish"
echo "============================"
echo ""

cd ~/www/download.e-qarz.uz || exit 1

# 1. Storage/logs papkasini tekshirish
echo "1️⃣ Storage/logs papkasini tekshirish..."
if [ ! -d "storage/logs" ]; then
    echo "   ❌ storage/logs papkasi topilmadi, yaratilmoqda..."
    mkdir -p storage/logs
    chmod -R 775 storage/logs
    echo "   ✅ Papka yaratildi"
else
    echo "   ✅ storage/logs papkasi mavjud"
fi
echo ""

# 2. Log fayllarni ko'rish
echo "2️⃣ Log Fayllar..."
ls -lh storage/logs/*.log 2>/dev/null | awk '{print "   📄", $9, "(" $5 ")"}' || echo "   ⚠️  Hech qanday .log fayl topilmadi"
echo ""

# 3. Laravel.log ni tekshirish
echo "3️⃣ Laravel.log Tekshirish..."
if [ -f "storage/logs/laravel.log" ]; then
    FILE_SIZE=$(du -h storage/logs/laravel.log | cut -f1)
    FILE_LINES=$(wc -l < storage/logs/laravel.log)
    echo "   ✅ laravel.log mavjud"
    echo "   📊 Fayl hajmi: $FILE_SIZE"
    echo "   📊 Qatorlar soni: $FILE_LINES"
    echo ""
    echo "   📋 Oxirgi 10 qator:"
    tail -10 storage/logs/laravel.log | sed 's/^/      /'
else
    echo "   ❌ laravel.log topilmadi"
    echo "   💡 Log fayl yaratilishi kerak"
    echo ""
    echo "   🔧 Yaratilmoqda..."
    touch storage/logs/laravel.log
    chmod 664 storage/logs/laravel.log
    echo "   ✅ laravel.log yaratildi"
fi
echo ""

# 4. Laravel log permissions
echo "4️⃣ Log Permissions..."
if [ -f "storage/logs/laravel.log" ]; then
    PERMS=$(stat -c "%a" storage/logs/laravel.log 2>/dev/null || stat -f "%OLp" storage/logs/laravel.log 2>/dev/null || echo "unknown")
    OWNER=$(stat -c "%U:%G" storage/logs/laravel.log 2>/dev/null || stat -f "%Su:%Sg" storage/logs/laravel.log 2>/dev/null || echo "unknown")
    echo "   📝 Permissions: $PERMS"
    echo "   👤 Owner: $OWNER"
    
    # Check if we can write
    if [ -w "storage/logs/laravel.log" ]; then
        echo "   ✅ Yozish mumkin"
    else
        echo "   ❌ Yozish mumkin emas!"
        echo "   💡 Permissions ni tuzatish: chmod 664 storage/logs/laravel.log"
    fi
else
    echo "   ⚠️  Laravel.log mavjud emas"
fi
echo ""

# 5. Test log yozish
echo "5️⃣ Test Log Yozish..."
if php artisan tinker --execute="Log::info('Test log message', ['test' => true]);" > /dev/null 2>&1; then
    echo "   ✅ Log yozish ishlayapti"
    sleep 1
    
    # Check if test message appeared in log
    if grep -q "Test log message" storage/logs/laravel.log 2>/dev/null; then
        echo "   ✅ Test log message topildi"
    else
        echo "   ⚠️  Test log message topilmadi (log fayl boshqa joyda bo'lishi mumkin)"
    fi
else
    echo "   ❌ Log yozish ishlamayapti"
fi
echo ""

# 6. Log config
echo "6️⃣ Log Config..."
LOG_CHANNEL=$(php artisan tinker --execute="echo config('logging.default');" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')
LOG_PATH=$(php artisan tinker --execute="echo config('logging.channels.single.path');" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')

echo "   📋 Log channel: $LOG_CHANNEL"
echo "   📋 Log path: $LOG_PATH"
echo ""

# 7. Oxirgi loglar (agar mavjud bo'lsa)
if [ -f "storage/logs/laravel.log" ] && [ -s "storage/logs/laravel.log" ]; then
    echo "7️⃣ Oxirgi Loglar (Instagram rasm bilan bog'liq)..."
    tail -50 storage/logs/laravel.log | grep -E "Instagram|image|JSON|download|No video formats" | tail -15 | sed 's/^/   /' || echo "   ℹ️  Tegishli loglar topilmadi"
    echo ""
fi

echo "===================================="
echo "✅ Tekshirish tugadi!"
echo ""
echo "💡 Keyingi qadamlar:"
echo "   # Loglarni kuzatish"
echo "   tail -f storage/logs/laravel.log"
echo ""
echo "   # Faqat Instagram rasm loglarini kuzatish"
echo "   tail -f storage/logs/laravel.log | grep -E 'Instagram|image|JSON|download|No video formats'"
echo ""
echo "   # Agar log fayl yozilmayotgan bo'lsa:"
echo "   chmod -R 775 storage/logs"
echo "   chown -R www-data:www-data storage/logs  # yoki boshqa web server user"
echo ""
