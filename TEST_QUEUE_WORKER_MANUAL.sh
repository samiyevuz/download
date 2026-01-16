#!/bin/bash

echo "🧪 Queue Worker Manual Test"
echo "==========================="
echo ""

cd ~/www/download.e-qarz.uz

# 1. Worker status
echo "1️⃣ Worker status..."
WORKERS=$(ps aux | grep "artisan queue:work" | grep -v grep | grep -v "datacollector" | wc -l)
if [ "$WORKERS" -ge 1 ]; then
    echo "✅ $WORKERS worker ishlayapti:"
    ps aux | grep "artisan queue:work" | grep -v grep | grep -v "datacollector" | awk '{print "   PID:", $2, "Queue:", $NF}'
else
    echo "❌ Hech qanday worker ishlamayapti!"
fi
echo ""

# 2. Queue'dagi joblar
echo "2️⃣ Queue'dagi joblar..."
JOBS_COUNT=$(php artisan tinker --execute="echo DB::table('jobs')->count();" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')
if [ -n "$JOBS_COUNT" ] && [ "$JOBS_COUNT" != "null" ]; then
    if [ "$JOBS_COUNT" -gt 0 ]; then
        echo "   📊 Queue'da $JOBS_COUNT ta job bor"
        echo "   📋 Joblar ro'yxati:"
        php artisan tinker --execute="DB::table('jobs')->select('id', 'queue', 'attempts', 'created_at')->orderBy('created_at', 'desc')->limit(5)->get()->each(function(\$job) { echo \$job->id . ' | ' . \$job->queue . ' | attempts: ' . \$job->attempts . ' | ' . \$job->created_at . PHP_EOL; });" 2>&1 | grep -v "Psy\|tinker\|^$" | head -10
    else
        echo "   ✅ Queue bo'sh"
    fi
else
    echo "   ⚠️  Queue jadvalini o'qib bo'lmadi"
fi
echo ""

# 3. Manual job ishga tushirish
echo "3️⃣ Manual job ishga tushirish..."
if [ "$JOBS_COUNT" -gt 0 ]; then
    echo "   💡 Queue'dagi joblarni qo'lda ishga tushirish:"
    echo "      php artisan queue:work database --queue=downloads --once --verbose"
    echo ""
    echo "   🔄 Bajarilmoqda..."
    php artisan queue:work database --queue=downloads --once --verbose 2>&1 | head -50
else
    echo "   ℹ️  Queue bo'sh, test qilish uchun job yo'q"
fi
echo ""

# 4. Loglarni kuzatish
echo "4️⃣ Oxirgi loglar..."
if [ -f "storage/logs/laravel.log" ]; then
    echo "   📄 Laravel log (oxirgi 30 qator, DownloadMediaJob):"
    tail -100 storage/logs/laravel.log | grep -E "DownloadMediaJob|Starting media download|ytDlpService|Sending videos|sendVideo" | tail -30 || echo "   (DownloadMediaJob loglari topilmadi)"
else
    echo "   ⚠️  laravel.log topilmadi"
fi
echo ""

if [ -f "storage/logs/queue-downloads.log" ]; then
    echo "   📄 Queue log (oxirgi 20 qator):"
    tail -20 storage/logs/queue-downloads.log || echo "   (Queue log bo'sh)"
else
    echo "   ⚠️  queue-downloads.log topilmadi"
fi
echo ""

echo "===================================="
echo "✅ Test tugadi!"
echo ""
echo "💡 Agar job ishlamasa:"
echo "   1. Worker ishlayotganini tekshiring"
echo "   2. Queue'dagi joblarni tekshiring"
echo "   3. Manual job ishga tushiring (3-qadam)"
echo "   4. Loglarni tekshiring (4-qadam)"
echo ""
