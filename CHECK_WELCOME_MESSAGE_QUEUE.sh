#!/bin/bash

echo "🔍 Welcome Message Queue Tekshirish"
echo "===================================="
echo ""

cd ~/www/download.e-qarz.uz

# 1. Worker status
echo "1️⃣ Queue worker status..."
WORKERS=$(ps aux | grep "artisan queue:work" | grep -v grep | grep -v "datacollector" | wc -l)
if [ "$WORKERS" -ge 1 ]; then
    echo "✅ $WORKERS worker ishlayapti:"
    ps aux | grep "artisan queue:work" | grep -v grep | grep -v "datacollector" | awk '{print "   PID:", $2, "Queue:", $NF}'
else
    echo "❌ Hech qanday worker ishlamayapti!"
    echo "   💡 Workerlarni ishga tushiring:"
    echo "      nohup php artisan queue:work database --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &"
fi
echo ""

# 2. Queue'dagi joblar
echo "2️⃣ Queue'dagi joblar..."
JOBS_COUNT=$(php artisan tinker --execute="echo DB::table('jobs')->count();" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')
if [ -n "$JOBS_COUNT" ] && [ "$JOBS_COUNT" != "null" ]; then
    echo "   📊 Queue'da $JOBS_COUNT ta job bor"
    
    if [ "$JOBS_COUNT" -gt 0 ]; then
        echo "   📋 Joblar ro'yxati:"
        php artisan tinker --execute="DB::table('jobs')->select('id', 'queue', 'attempts', 'created_at')->orderBy('created_at', 'desc')->limit(5)->get()->each(function(\$job) { echo \$job->id . ' | ' . \$job->queue . ' | attempts: ' . \$job->attempts . ' | ' . \$job->created_at . PHP_EOL; });" 2>&1 | grep -v "Psy\|tinker\|^$" | head -10
    fi
else
    echo "   ⚠️  Queue jadvalini o'qib bo'lmadi"
fi
echo ""

# 3. Failed jobs
echo "3️⃣ Failed jobs..."
FAILED_COUNT=$(php artisan tinker --execute="echo DB::table('failed_jobs')->count();" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')
if [ -n "$FAILED_COUNT" ] && [ "$FAILED_COUNT" != "null" ] && [ "$FAILED_COUNT" -gt 0 ]; then
    echo "   ⚠️  $FAILED_COUNT ta failed job bor:"
    php artisan tinker --execute="DB::table('failed_jobs')->select('id', 'queue', 'failed_at', 'exception')->orderBy('failed_at', 'desc')->limit(3)->get()->each(function(\$job) { echo 'ID: ' . \$job->id . ' | Queue: ' . \$job->queue . ' | Failed: ' . \$job->failed_at . PHP_EOL . 'Exception: ' . substr(\$job->exception, 0, 200) . '...' . PHP_EOL . PHP_EOL; });" 2>&1 | grep -v "Psy\|tinker\|^$" | head -20
else
    echo "   ✅ Failed joblar yo'q"
fi
echo ""

# 4. Queue loglar
echo "4️⃣ Queue loglar (oxirgi 20 qator)..."
if [ -f "storage/logs/queue-telegram.log" ]; then
    echo "   📄 queue-telegram.log:"
    tail -20 storage/logs/queue-telegram.log | grep -E "Welcome|SendTelegramWelcomeMessageJob|error|Error|Exception|Failed" || echo "   (Welcome message bilan bog'liq loglar topilmadi)"
else
    echo "   ⚠️  queue-telegram.log topilmadi"
fi
echo ""

# 5. Laravel loglar
echo "5️⃣ Laravel loglar (oxirgi 10 qator, Welcome message)..."
if [ -f "storage/logs/laravel.log" ]; then
    tail -50 storage/logs/laravel.log | grep -E "Welcome message|SendTelegramWelcomeMessageJob|telegram.*sendMessage" | tail -10 || echo "   (Welcome message loglari topilmadi)"
else
    echo "   ⚠️  laravel.log topilmadi"
fi
echo ""

# 6. Test: Welcome message job'ni qo'lda ishga tushirish
echo "6️⃣ Test: Welcome message job'ni qo'lda ishga tushirish..."
echo "   💡 Agar job queue'da qolgan bo'lsa, quyidagi buyruqni bajarish mumkin:"
echo "      php artisan queue:work database --queue=telegram --once"
echo ""

echo "===================================="
echo "✅ Tekshirish tugadi!"
echo ""
echo "💡 Agar welcome message hali ham ko'rinmasa:"
echo "   1. Worker ishlayotganini tekshiring (1-qadam)"
echo "   2. Queue'dagi joblarni tekshiring (2-qadam)"
echo "   3. Failed joblarni tekshiring (3-qadam)"
echo "   4. Loglarni tekshiring (4-5 qadamlar)"
echo ""
