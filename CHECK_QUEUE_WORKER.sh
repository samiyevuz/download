#!/bin/bash

echo "🔍 Queue Worker Tekshirish"
echo "=========================="
echo ""

cd ~/www/download.e-qarz.uz

# 1. Queue worker'lar ishlayaptimi?
echo "1️⃣ Queue worker'lar tekshirish..."
WORKERS=$(ps aux | grep "artisan queue:work" | grep -v grep | grep -v "datacollector" | wc -l)
if [ "$WORKERS" -ge 1 ]; then
    echo "   ✅ $WORKERS worker ishlayapti"
    ps aux | grep "artisan queue:work" | grep -v grep | grep -v "datacollector" | awk '{print "      PID:", $2, "Queue:", $NF}'
else
    echo "   ❌ Hech qanday worker ishlamayapti!"
    echo "   💡 Worker'ni ishga tushiring:"
    echo "      nohup php artisan queue:work database --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &"
fi
echo ""

# 2. Jobs jadvalida job'lar bormi?
echo "2️⃣ Jobs jadvalida job'lar tekshirish..."
JOBS_COUNT=$(php artisan tinker --execute="echo DB::table('jobs')->count();" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')
if [ ! -z "$JOBS_COUNT" ] && [ "$JOBS_COUNT" != "null" ]; then
    echo "   📊 Jobs jadvalida: $JOBS_COUNT job"
    
    if [ "$JOBS_COUNT" -gt 0 ]; then
        echo "   ⚠️  Job'lar kutayapti - worker ularni qayta ishlay olmayapti"
        echo "   💡 Worker'ni qayta ishga tushiring"
    else
        echo "   ✅ Jobs jadvalida job yo'q"
    fi
else
    echo "   ⚠️  Jobs jadvalini o'qib bo'lmadi"
fi
echo ""

# 3. Queue loglarini tekshirish
echo "3️⃣ Queue loglarini tekshirish..."
if [ -f "storage/logs/queue-telegram.log" ]; then
    echo "   📄 queue-telegram.log (oxirgi 10 qator):"
    tail -10 storage/logs/queue-telegram.log | sed 's/^/      /'
else
    echo "   ⚠️  queue-telegram.log topilmadi"
fi
echo ""

# 4. Laravel loglarini tekshirish
echo "4️⃣ Laravel loglarini tekshirish (oxirgi 20 qator)..."
if [ -f "storage/logs/laravel.log" ]; then
    echo "   📄 laravel.log (oxirgi 20 qator):"
    tail -20 storage/logs/laravel.log | grep -E "language selection|SendTelegramLanguageSelectionJob|dispatch|queue" | tail -10 | sed 's/^/      /'
else
    echo "   ⚠️  laravel.log topilmadi"
fi
echo ""

# 5. Test: Job dispatch qilish
echo "5️⃣ Test: Job dispatch qilish..."
php artisan tinker --execute="
\$chatId = 7730989535;
try {
    \App\Jobs\SendTelegramLanguageSelectionJob::dispatch(\$chatId)->onQueue('telegram');
    echo 'SUCCESS: Job dispatched';
} catch (\Exception \$e) {
    echo 'ERROR: ' . \$e->getMessage();
}
" 2>&1 | grep -v "Psy\|tinker" | tail -5
echo ""

echo "===================================="
echo "✅ Tekshirish tugadi!"
echo ""
echo "💡 Agar worker ishlamasa:"
echo "   nohup php artisan queue:work database --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &"
echo ""
