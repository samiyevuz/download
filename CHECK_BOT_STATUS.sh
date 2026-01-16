#!/bin/bash

echo "🔍 Bot Status Tekshirish"
echo "========================"
echo ""

cd ~/www/download.e-qarz.uz

# 1. Queue workers
echo "1️⃣ Queue Workers Tekshirish..."
WORKERS=$(ps aux | grep "artisan queue:work" | grep -v grep | grep -v "datacollector" | wc -l)

if [ "$WORKERS" -ge 2 ]; then
    echo "   ✅ $WORKERS worker ishlayapti:"
    ps aux | grep "artisan queue:work" | grep -v grep | grep -v "datacollector" | awk '{print "      PID:", $2, "Queue:", $NF, "Time:", $10}'
else
    echo "   ❌ Faqat $WORKERS worker ishlayapti (2 ta bo'lishi kerak)"
    echo "   💡 Ishga tushirish:"
    echo "      nohup php artisan queue:work database --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &"
    echo "      nohup php artisan queue:work database --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &"
fi
echo ""

# 2. Queue loglarni tekshirish
echo "2️⃣ Queue Loglar (oxirgi 10 qator)..."
if [ -f "storage/logs/queue-downloads.log" ]; then
    echo "   📥 Downloads queue log:"
    tail -10 storage/logs/queue-downloads.log | sed 's/^/      /'
else
    echo "   ⚠️  queue-downloads.log topilmadi"
fi
echo ""

if [ -f "storage/logs/queue-telegram.log" ]; then
    echo "   📤 Telegram queue log:"
    tail -10 storage/logs/queue-telegram.log | sed 's/^/      /'
else
    echo "   ⚠️  queue-telegram.log topilmadi"
fi
echo ""

# 3. Queue'dagi joblar
echo "3️⃣ Queue'dagi Joblar..."
JOBS_COUNT=$(php artisan tinker --execute="echo DB::table('jobs')->count();" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')

if [ -n "$JOBS_COUNT" ] && [ "$JOBS_COUNT" != "null" ]; then
    if [ "$JOBS_COUNT" -gt 0 ]; then
        echo "   ⚠️  Queue'da $JOBS_COUNT ta job bor (ishlamayapti)"
        echo "   💡 Workerlar ishlayaptimi? Tekshiring: ps aux | grep 'queue:work'"
    else
        echo "   ✅ Queue bo'sh (barcha joblar ishlangan)"
    fi
else
    echo "   ⚠️  Queue jadvalini o'qib bo'lmadi"
fi
echo ""

# 4. Failed jobs
echo "4️⃣ Failed Jobs..."
FAILED_COUNT=$(php artisan tinker --execute="echo DB::table('failed_jobs')->count();" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')

if [ -n "$FAILED_COUNT" ] && [ "$FAILED_COUNT" != "null" ] && [ "$FAILED_COUNT" -gt 0 ]; then
    echo "   ⚠️  $FAILED_COUNT ta failed job bor"
    echo "   💡 Failed joblarni ko'rish: php artisan queue:failed"
else
    echo "   ✅ Failed joblar yo'q"
fi
echo ""

# 5. Bot token
echo "5️⃣ Bot Token..."
BOT_TOKEN=$(php artisan tinker --execute="echo config('telegram.bot_token');" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')

if [ -n "$BOT_TOKEN" ] && [ "$BOT_TOKEN" != "null" ] && [ "$BOT_TOKEN" != "" ]; then
    echo "   ✅ Bot token sozlangan: ${BOT_TOKEN:0:20}..."
    
    # Telegram API test
    echo "   🔄 Telegram API test..."
    RESPONSE=$(curl -s "https://api.telegram.org/bot${BOT_TOKEN}/getMe")
    if echo "$RESPONSE" | grep -q '"ok":true'; then
        BOT_USERNAME=$(echo "$RESPONSE" | grep -o '"username":"[^"]*"' | cut -d'"' -f4)
        echo "   ✅ Bot API ishlayapti (@$BOT_USERNAME)"
    else
        echo "   ❌ Bot API'ga ulanishda xatolik"
        echo "   Response: ${RESPONSE:0:200}"
    fi
else
    echo "   ❌ Bot token sozlanmagan!"
fi
echo ""

# 6. Webhook route
echo "6️⃣ Webhook Route..."
if php artisan route:list | grep -q "telegram.webhook"; then
    echo "   ✅ Webhook route mavjud"
    ROUTE_INFO=$(php artisan route:list | grep "telegram.webhook")
    echo "   $ROUTE_INFO" | sed 's/^/      /'
else
    echo "   ❌ Webhook route topilmadi!"
fi
echo ""

# 7. Config
echo "7️⃣ Config..."
php artisan config:show telegram.bot_token > /dev/null 2>&1
if [ $? -eq 0 ]; then
    echo "   ✅ Config cache ishlayapti"
    
    # yt-dlp path
    YT_DLP_PATH=$(php artisan tinker --execute="echo config('telegram.yt_dlp_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')
    if [ -f "$YT_DLP_PATH" ] && [ -x "$YT_DLP_PATH" ]; then
        VERSION=$("$YT_DLP_PATH" --version 2>/dev/null || echo "unknown")
        echo "   ✅ yt-dlp: $YT_DLP_PATH (versiya: $VERSION)"
    else
        echo "   ❌ yt-dlp topilmadi: $YT_DLP_PATH"
    fi
else
    echo "   ⚠️  Config cache muammosi"
fi
echo ""

# 8. Laravel log (oxirgi 20 qator)
echo "8️⃣ Laravel Log (oxirgi 20 qator)..."
if [ -f "storage/logs/laravel.log" ]; then
    echo "   📋 Oxirgi loglar:"
    tail -20 storage/logs/laravel.log | grep -E "Telegram webhook|DownloadMediaJob|Starting media download|Sending images|Sending videos" | tail -10 | sed 's/^/      /'
    
    # Xatoliklar
    ERROR_COUNT=$(tail -100 storage/logs/laravel.log | grep -i "error\|exception" | wc -l)
    if [ "$ERROR_COUNT" -gt 0 ]; then
        echo "   ⚠️  Oxirgi 100 qatorda $ERROR_COUNT ta xatolik topildi"
    else
        echo "   ✅ Oxirgi 100 qatorda xatoliklar yo'q"
    fi
else
    echo "   ⚠️  laravel.log topilmadi"
fi
echo ""

# 9. PHP extensions
echo "9️⃣ PHP Extensions..."
if php -m | grep -q "gd"; then
    echo "   ✅ GD extension mavjud"
    if php -r "exit(function_exists('imagecreatefromwebp') ? 0 : 1);"; then
        echo "   ✅ WebP support mavjud"
    else
        echo "   ⚠️  WebP support yo'q"
    fi
else
    echo "   ❌ GD extension yo'q"
fi

if php -m | grep -q "curl"; then
    echo "   ✅ cURL extension mavjud"
else
    echo "   ❌ cURL extension yo'q"
fi

if php -m | grep -q "mbstring"; then
    echo "   ✅ mbstring extension mavjud"
else
    echo "   ⚠️  mbstring extension yo'q"
fi
echo ""

# 10. Disk space
echo "🔟 Disk Space..."
DISK_USAGE=$(df -h . | tail -1 | awk '{print $5}' | sed 's/%//')
if [ "$DISK_USAGE" -lt 80 ]; then
    echo "   ✅ Disk space yetarli: ${DISK_USAGE}% ishlatilgan"
else
    echo "   ⚠️  Disk space kam: ${DISK_USAGE}% ishlatilgan"
fi

TEMP_SIZE=$(du -sh storage/app/temp* 2>/dev/null | awk '{sum+=$1} END {print sum}' || echo "0")
echo "   📁 Temp fayllar: ${TEMP_SIZE:-0}"
echo ""

# 11. Database connection
echo "1️⃣1️⃣ Database Connection..."
if php artisan tinker --execute="DB::connection()->getPdo(); echo 'OK';" > /dev/null 2>&1; then
    echo "   ✅ Database ulanish ishlayapti"
else
    echo "   ❌ Database ulanishda muammo"
fi
echo ""

echo "===================================="
echo "📊 NATIJA"
echo ""

# Xulosa
ISSUES=0

if [ "$WORKERS" -lt 2 ]; then
    echo "❌ Queue workers yetarli emas ($WORKERS/2)"
    ISSUES=$((ISSUES + 1))
fi

if [ -n "$JOBS_COUNT" ] && [ "$JOBS_COUNT" != "null" ] && [ "$JOBS_COUNT" -gt 0 ]; then
    echo "⚠️  Queue'da $JOBS_COUNT ta job kutmoqda"
    ISSUES=$((ISSUES + 1))
fi

if [ -n "$FAILED_COUNT" ] && [ "$FAILED_COUNT" != "null" ] && [ "$FAILED_COUNT" -gt 0 ]; then
    echo "⚠️  $FAILED_COUNT ta failed job bor"
    ISSUES=$((ISSUES + 1))
fi

if [ -z "$BOT_TOKEN" ] || [ "$BOT_TOKEN" = "null" ] || [ "$BOT_TOKEN" = "" ]; then
    echo "❌ Bot token sozlanmagan"
    ISSUES=$((ISSUES + 1))
fi

if [ "$ISSUES" -eq 0 ]; then
    echo "✅ Bot to'liq ishlayapti!"
else
    echo "⚠️  $ISSUES ta muammo topildi"
fi
echo ""

echo "💡 Tekshirish komandalari:"
echo "   # Workerlar"
echo "   ps aux | grep 'queue:work'"
echo ""
echo "   # Queue'dagi joblar"
echo "   php artisan tinker --execute=\"DB::table('jobs')->get();\""
echo ""
echo "   # Failed jobs"
echo "   php artisan queue:failed"
echo ""
echo "   # Real-time loglar"
echo "   tail -f storage/logs/laravel.log"
echo "   tail -f storage/logs/queue-downloads.log"
echo ""
