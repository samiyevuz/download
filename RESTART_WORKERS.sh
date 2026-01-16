#!/bin/bash

echo "🔄 Queue Worker'larni Qayta Ishga Tushirish"
echo "==========================================="
echo ""

cd ~/www/download.e-qarz.uz || exit 1

# 1. Mavjud worker'larni topish
echo "1️⃣ Mavjud worker'larni topish..."
WORKERS=$(ps aux | grep "queue:work redis" | grep -v grep | awk '{print $2}')

if [ -z "$WORKERS" ]; then
    echo "   ⚠️  Hech qanday worker topilmadi"
else
    echo "   📋 Topilgan worker'lar:"
    ps aux | grep "queue:work redis" | grep -v grep | awk '{print "   PID:", $2, "-", $11, $12, $13, $14, $15, $16, $17}'
    echo ""
    
    # 2. Worker'larni to'xtatish
    echo "2️⃣ Worker'larni to'xtatish..."
    for PID in $WORKERS; do
        echo "   ⏹️  Worker'ni to'xtatish: PID $PID"
        kill $PID 2>/dev/null
        sleep 1
    done
    
    # Wait a bit for processes to stop
    sleep 2
    
    # Check if any are still running
    REMAINING=$(ps aux | grep "queue:work redis" | grep -v grep | awk '{print $2}')
    if [ ! -z "$REMAINING" ]; then
        echo "   ⚠️  Ba'zi worker'lar hali ham ishlayapti, force kill qilinmoqda..."
        for PID in $REMAINING; do
            kill -9 $PID 2>/dev/null
        done
        sleep 1
    fi
    echo "   ✅ Worker'lar to'xtatildi"
fi

echo ""

# 3. Worker'larni qayta ishga tushirish
echo "3️⃣ Worker'larni qayta ishga tushirish..."
echo "   🚀 Downloads queue worker ishga tushirilmoqda..."
nohup php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 > /dev/null 2>&1 &

sleep 1

echo "   🚀 Telegram queue worker ishga tushirilmoqda..."
nohup php artisan queue:work redis --queue=telegram --tries=3 --timeout=10 > /dev/null 2>&1 &

sleep 2

echo "   ✅ Worker'lar ishga tushirildi"
echo ""

# 4. Yangi worker'larni tekshirish
echo "4️⃣ Yangi worker'lar holati..."
NEW_WORKERS=$(ps aux | grep "queue:work redis" | grep -v grep | awk '{print $2}')

if [ -z "$NEW_WORKERS" ]; then
    echo "   ❌ Worker'lar ishga tushirilmadi!"
    exit 1
else
    echo "   ✅ Yangi worker'lar ishlayapti:"
    ps aux | grep "queue:work redis" | grep -v grep | awk '{print "   PID:", $2, "-", $11, $12, $13, $14, $15, $16, $17}'
fi

echo ""
echo "===================================="
echo "✅ Worker'lar qayta ishga tushirildi!"
echo ""
echo "📝 Keyingi qadamlar:"
echo "   1. Bot'ga Instagram rasm linkini yuboring"
echo "   2. Loglarni kuzating:"
echo "      tail -f storage/logs/laravel.log | grep -E 'Method 6|HTML parsing|Attempting HTML'"
echo ""
echo "💡 Worker'larni boshqa terminal'da qayta ishga tushirish:"
echo "   nohup php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 > /dev/null 2>&1 &"
echo "   nohup php artisan queue:work redis --queue=telegram --tries=3 --timeout=10 > /dev/null 2>&1 &"
echo ""
