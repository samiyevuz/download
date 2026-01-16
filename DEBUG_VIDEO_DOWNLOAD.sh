#!/bin/bash

echo "🔍 Video Download Debug"
echo "======================"
echo ""

cd ~/www/download.e-qarz.uz

# 1. Queue workers
echo "1️⃣ Queue Workers..."
WORKERS=$(ps aux | grep "artisan queue:work" | grep -v grep | grep -v "datacollector" | wc -l)
if [ "$WORKERS" -ge 1 ]; then
    echo "   ✅ $WORKERS worker ishlayapti"
    ps aux | grep "artisan queue:work" | grep -v grep | grep -v "datacollector" | awk '{print "      PID:", $2, "Queue:", $NF}'
else
    echo "   ❌ Worker ishlamayapti!"
fi
echo ""

# 2. Queue'dagi joblar
echo "2️⃣ Queue'dagi Joblar..."
JOBS_COUNT=$(php artisan tinker --execute="echo DB::table('jobs')->count();" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')

if [ -n "$JOBS_COUNT" ] && [ "$JOBS_COUNT" != "null" ]; then
    if [ "$JOBS_COUNT" -gt 0 ]; then
        echo "   ⚠️  Queue'da $JOBS_COUNT ta job bor"
        
        # Joblar haqida ma'lumot
        echo "   📋 Joblar:"
        php artisan tinker --execute="DB::table('jobs')->select('id', 'queue', 'payload', 'created_at')->orderBy('id', 'desc')->limit(5)->get()->each(function(\$job) { echo \$job->id . ' | ' . \$job->queue . ' | ' . substr(\$job->payload, 0, 100) . '... | ' . \$job->created_at . PHP_EOL; });" 2>&1 | grep -v "Psy\|tinker\|^>" | sed 's/^/      /'
    else
        echo "   ✅ Queue bo'sh"
    fi
else
    echo "   ⚠️  Queue jadvalini o'qib bo'lmadi"
fi
echo ""

# 3. Failed jobs
echo "3️⃣ Failed Jobs..."
FAILED_COUNT=$(php artisan tinker --execute="echo DB::table('failed_jobs')->count();" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')

if [ -n "$FAILED_COUNT" ] && [ "$FAILED_COUNT" != "null" ] && [ "$FAILED_COUNT" -gt 0 ]; then
    echo "   ⚠️  $FAILED_COUNT ta failed job bor"
    echo "   📋 Oxirgi failed joblar:"
    php artisan queue:failed | tail -10 | sed 's/^/      /'
else
    echo "   ✅ Failed joblar yo'q"
fi
echo ""

# 4. Oxirgi loglar (DownloadMediaJob)
echo "4️⃣ Oxirgi DownloadMediaJob Loglari..."
tail -50 storage/logs/laravel.log | grep -E "Download job dispatched|DownloadMediaJob|Starting media download|Calling ytDlpService|ytDlpService->download completed|Downloaded files separated|Sending videos|sendVideo" | tail -20 | sed 's/^/   /'
echo ""

# 5. Queue worker loglar
echo "5️⃣ Queue Worker Loglari (oxirgi 20 qator)..."
if [ -f "storage/logs/queue-downloads.log" ]; then
    echo "   📥 Downloads queue log:"
    tail -20 storage/logs/queue-downloads.log | sed 's/^/      /'
else
    echo "   ⚠️  queue-downloads.log topilmadi"
fi
echo ""

# 6. Job dispatch tekshirish (oxirgi 10 daqiqa)
echo "6️⃣ Oxirgi 10 daqiqada Job Dispatch..."
tail -100 storage/logs/laravel.log | grep -E "Download job dispatched|Telegram webhook received" | tail -10 | sed 's/^/   /'
echo ""

# 7. Webhook loglar
echo "7️⃣ Oxirgi Webhook Loglari..."
tail -30 storage/logs/laravel.log | grep -E "Telegram webhook received|Message received|text.*instagram|text.*tiktok" -i | tail -10 | sed 's/^/   /'
echo ""

echo "===================================="
echo "📊 XULOSA"
echo ""

# Xulosa
ISSUES=0

if [ "$WORKERS" -lt 1 ]; then
    echo "❌ Queue worker ishlamayapti!"
    ISSUES=$((ISSUES + 1))
fi

if [ -n "$JOBS_COUNT" ] && [ "$JOBS_COUNT" != "null" ] && [ "$JOBS_COUNT" -gt 0 ]; then
    echo "⚠️  Queue'da $JOBS_COUNT ta job kutmoqda (worker ishlamayapti?)"
    ISSUES=$((ISSUES + 1))
fi

if [ -n "$FAILED_COUNT" ] && [ "$FAILED_COUNT" != "null" ] && [ "$FAILED_COUNT" -gt 0 ]; then
    echo "⚠️  $FAILED_COUNT ta failed job bor"
    ISSUES=$((ISSUES + 1))
fi

if [ "$ISSUES" -eq 0 ]; then
    echo "✅ Hammasi yaxshi ko'rinadi"
    echo "   💡 Agar video yuborilmasa, real-time loglarni kuzating:"
    echo "      tail -f storage/logs/laravel.log | grep -E 'DownloadMediaJob|sendVideo|Sending videos'"
else
    echo "⚠️  $ISSUES ta muammo topildi"
fi
echo ""

echo "💡 Tekshirish komandalari:"
echo "   # Queue'dagi joblar"
echo "   php artisan tinker --execute=\"DB::table('jobs')->get();\""
echo ""
echo "   # Failed jobs"
echo "   php artisan queue:failed"
echo ""
echo "   # Real-time loglar"
echo "   tail -f storage/logs/laravel.log | grep -E 'DownloadMediaJob|sendVideo'"
echo ""
