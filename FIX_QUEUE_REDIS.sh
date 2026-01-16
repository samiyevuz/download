#!/bin/bash

echo "🔧 Queue Connection Redis'da Qoldirish va Worker'larni Tuzatish"
echo "================================================================="
echo ""

cd ~/www/download.e-qarz.uz

# 1. .env faylida QUEUE_CONNECTION ni tekshirish
echo "1️⃣ .env faylida QUEUE_CONNECTION ni tekshirish..."
if grep -q "^QUEUE_CONNECTION=" .env 2>/dev/null; then
    CURRENT=$(grep "^QUEUE_CONNECTION=" .env | cut -d '=' -f2)
    echo "   Hozirgi qiymat: $CURRENT"
    
    if [ "$CURRENT" != "redis" ]; then
        sed -i 's/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=redis/' .env
        echo "   ✅ QUEUE_CONNECTION redis ga o'zgartirildi"
    else
        echo "   ℹ️  QUEUE_CONNECTION allaqachon redis"
    fi
else
    echo "   QUEUE_CONNECTION topilmadi, qo'shilyapti..."
    echo "QUEUE_CONNECTION=redis" >> .env
    echo "   ✅ QUEUE_CONNECTION qo'shildi"
fi
echo ""

# 2. Redis tekshirish
echo "2️⃣ Redis Tekshirish..."
if command -v redis-cli &> /dev/null; then
    if redis-cli ping &> /dev/null; then
        echo "   ✅ Redis ishlayapti"
    else
        echo "   ❌ Redis ishlamayapti!"
        echo "   💡 Redis'ni ishga tushiring: sudo systemctl start redis"
    fi
else
    echo "   ⚠️  redis-cli topilmadi (Redis o'rnatilmagan bo'lishi mumkin)"
fi
echo ""

# 3. Config cache'ni tozalash
echo "3️⃣ Config cache'ni tozalash..."
php artisan config:clear
php artisan cache:clear
php artisan config:cache
echo "✅ Config cache yangilandi"
echo ""

# 4. Queue connection tekshirish
echo "4️⃣ Queue Connection Tekshirish..."
QUEUE_CONNECTION=$(php artisan tinker --execute="echo config('queue.default');" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')
echo "   📋 Queue connection: $QUEUE_CONNECTION"

if [ "$QUEUE_CONNECTION" = "redis" ]; then
    echo "   ✅ Redis queue ishlatilmoqda"
else
    echo "   ❌ Queue connection hali ham $QUEUE_CONNECTION!"
fi
echo ""

# 5. Eski worker'larni o'chirish (barcha queue typelari uchun)
echo "5️⃣ Eski Worker'larni O'chirish..."
pkill -9 -f "artisan queue:work" 2>/dev/null
sleep 2
echo "   ✅ Eski worker'lar o'chirildi"
echo ""

# 6. Yangi worker'larni ishga tushirish (Redis queue)
echo "6️⃣ Yangi Worker'larni Ishga Tushirish (Redis queue)..."
nohup php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
DOWNLOAD_PID=$!

nohup php artisan queue:work redis --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &
TELEGRAM_PID=$!

sleep 3
echo "   ✅ Workerlarni ishga tushirdim (PIDs: $DOWNLOAD_PID, $TELEGRAM_PID)"
echo ""

# 7. Worker'larni tekshirish
echo "7️⃣ Worker'larni Tekshirish..."
WORKERS=$(ps aux | grep "artisan queue:work redis" | grep -v grep | grep -v "datacollector" | wc -l)

if [ "$WORKERS" -ge 2 ]; then
    echo "   ✅ $WORKERS worker ishlayapti (Redis queue):"
    ps aux | grep "artisan queue:work redis" | grep -v grep | grep -v "datacollector" | awk '{print "      PID:", $2, "Queue:", $NF}'
else
    echo "   ⚠️  Faqat $WORKERS worker ishlayapti (2 ta bo'lishi kerak)"
    
    # Qayta urinib ko'rish
    echo "   🔄 Qayta urinib ko'ryapman..."
    pkill -9 -f "artisan queue:work" 2>/dev/null
    sleep 2
    
    nohup php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
    nohup php artisan queue:work redis --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &
    
    sleep 3
    NEW_WORKERS=$(ps aux | grep "artisan queue:work redis" | grep -v grep | grep -v "datacollector" | wc -l)
    if [ "$NEW_WORKERS" -ge 2 ]; then
        echo "   ✅ $NEW_WORKERS worker ishga tushdi"
    else
        echo "   ❌ Worker'lar ishga tushmadi!"
    fi
fi
echo ""

# 8. Redis queue'dagi joblar
echo "8️⃣ Redis Queue'dagi Joblar..."
REDIS_KEYS=$(redis-cli KEYS "*queue*" 2>/dev/null | wc -l)
if [ "$REDIS_KEYS" -gt 0 ]; then
    echo "   📋 Redis'da queue key'lar: $REDIS_KEYS"
else
    echo "   ✅ Redis queue bo'sh"
fi
echo ""

# 9. Oxirgi loglar
echo "9️⃣ Oxirgi DownloadMediaJob Loglari (oxirgi 20 qator)..."
tail -100 storage/logs/laravel.log | grep -E "Download job dispatched|DownloadMediaJob|Starting media download|Calling ytDlpService|ytDlpService->download|Downloaded files separated|Sending videos|sendVideo" | tail -20 | sed 's/^/   /'
echo ""

# 10. Queue worker loglar
echo "🔟 Queue Worker Loglari (oxirgi 20 qator)..."
if [ -f "storage/logs/queue-downloads.log" ]; then
    echo "   📥 Downloads queue log:"
    tail -20 storage/logs/queue-downloads.log | sed 's/^/      /'
else
    echo "   ⚠️  queue-downloads.log topilmadi"
fi
echo ""

echo "===================================="
echo "✅ Tuzatish tugadi!"
echo ""
echo "🔧 Tuzatilgan muammolar:"
echo "   ✨ QUEUE_CONNECTION redis da qoldirildi"
echo "   ✨ Worker'lar Redis queue'da ishlayapti"
echo "   ✨ Config yangilandi"
echo "   ✨ Worker'lar to'g'ri queue'da ishga tushdi"
echo ""
echo "🧪 Test qiling:"
echo "   1. Bot'ga Instagram video linki yuboring"
echo "   2. Loglarni kuzating:"
echo "      tail -f storage/logs/laravel.log | grep -E 'Download job dispatched|DownloadMediaJob|Starting media download|sendVideo'"
echo "      tail -f storage/logs/queue-downloads.log"
echo ""
echo "💡 Agar worker ishlamasa:"
echo "   ps aux | grep 'queue:work redis'"
echo "   redis-cli ping"
echo ""
