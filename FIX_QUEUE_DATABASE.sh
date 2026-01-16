#!/bin/bash

echo "🔧 Queue Connection'ni Database ga O'zgartirish"
echo "================================================"
echo ""

cd ~/www/download.e-qarz.uz

# 1. .env faylida QUEUE_CONNECTION ni tekshirish va o'zgartirish
echo "1️⃣ .env faylida QUEUE_CONNECTION ni tekshirish..."
if grep -q "^QUEUE_CONNECTION=" .env 2>/dev/null; then
    CURRENT=$(grep "^QUEUE_CONNECTION=" .env | cut -d '=' -f2)
    echo "   Hozirgi qiymat: $CURRENT"
    
    if [ "$CURRENT" != "database" ]; then
        sed -i 's/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=database/' .env
        echo "   ✅ QUEUE_CONNECTION database ga o'zgartirildi"
    else
        echo "   ℹ️  QUEUE_CONNECTION allaqachon database"
    fi
else
    echo "   QUEUE_CONNECTION topilmadi, qo'shilyapti..."
    echo "QUEUE_CONNECTION=database" >> .env
    echo "   ✅ QUEUE_CONNECTION qo'shildi"
fi
echo ""

# 2. Config cache'ni tozalash
echo "2️⃣ Config cache'ni tozalash..."
php artisan config:clear
php artisan cache:clear
php artisan config:cache
echo "✅ Config cache yangilandi"
echo ""

# 3. Queue connection tekshirish
echo "3️⃣ Queue Connection Tekshirish..."
QUEUE_CONNECTION=$(php artisan tinker --execute="echo config('queue.default');" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')
echo "   📋 Queue connection: $QUEUE_CONNECTION"

if [ "$QUEUE_CONNECTION" = "database" ]; then
    echo "   ✅ Database queue ishlatilmoqda"
else
    echo "   ❌ Queue connection hali ham $QUEUE_CONNECTION!"
fi
echo ""

# 4. Queue table tekshirish
echo "4️⃣ Queue Table Tekshirish..."
php artisan tinker --execute="DB::table('jobs')->count();" > /dev/null 2>&1
if [ $? -eq 0 ]; then
    echo "   ✅ Queue table mavjud"
else
    echo "   ⚠️  Queue table topilmadi, yaratilmoqda..."
    php artisan queue:table 2>/dev/null
    php artisan migrate --force 2>/dev/null
    echo "   ✅ Queue table yaratildi"
fi
echo ""

# 5. Eski worker'larni o'chirish (barcha queue typelari uchun)
echo "5️⃣ Eski Worker'larni O'chirish..."
pkill -9 -f "artisan queue:work" 2>/dev/null
sleep 2
echo "   ✅ Eski worker'lar o'chirildi"
echo ""

# 6. Yangi worker'larni ishga tushirish (database queue)
echo "6️⃣ Yangi Worker'larni Ishga Tushirish (database queue)..."
nohup php artisan queue:work database --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
DOWNLOAD_PID=$!

nohup php artisan queue:work database --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &
TELEGRAM_PID=$!

sleep 3
echo "   ✅ Workerlarni ishga tushirdim (PIDs: $DOWNLOAD_PID, $TELEGRAM_PID)"
echo ""

# 7. Worker'larni tekshirish
echo "7️⃣ Worker'larni Tekshirish..."
WORKERS=$(ps aux | grep "artisan queue:work database" | grep -v grep | grep -v "datacollector" | wc -l)

if [ "$WORKERS" -ge 2 ]; then
    echo "   ✅ $WORKERS worker ishlayapti (database queue):"
    ps aux | grep "artisan queue:work database" | grep -v grep | grep -v "datacollector" | awk '{print "      PID:", $2, "Queue:", $NF}'
else
    echo "   ⚠️  Faqat $WORKERS worker ishlayapti (2 ta bo'lishi kerak)"
fi
echo ""

# 8. Failed jobs'larni tozalash (ixtiyoriy)
echo "8️⃣ Failed Jobs (33 ta bor)..."
read -p "   Failed jobs'larni tozalashni xohlaysizmi? (y/n): " -n 1 -r
echo ""
if [[ $REPLY =~ ^[Yy]$ ]]; then
    php artisan queue:flush
    echo "   ✅ Failed jobs tozalandi"
else
    echo "   ℹ️  Failed jobs saqlanib qoldi"
fi
echo ""

echo "===================================="
echo "✅ Tuzatish tugadi!"
echo ""
echo "🔧 Tuzatilgan muammolar:"
echo "   ✨ QUEUE_CONNECTION database ga o'zgartirildi"
echo "   ✨ Worker'lar database queue'da ishlayapti"
echo "   ✨ Config yangilandi"
echo ""
echo "🧪 Test qiling:"
echo "   1. Bot'ga Instagram video linki yuboring"
echo "   2. Loglarni kuzating:"
echo "      tail -f storage/logs/laravel.log | grep -E 'Download job dispatched|DownloadMediaJob|Starting media download'"
echo "      tail -f storage/logs/queue-downloads.log"
echo ""
