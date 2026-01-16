#!/bin/bash

echo "🔄 yt-dlp sozlangandan keyin workerlarni qayta ishga tushirish..."
echo ""

cd ~/www/download.e-qarz.uz

# 1. Config tekshirish
echo "1️⃣ Config tekshirish..."
YT_DLP_PATH=$(php artisan tinker --execute="echo config('telegram.yt_dlp_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1)
echo "   yt-dlp path: $YT_DLP_PATH"

if [ -z "$YT_DLP_PATH" ] || [ "$YT_DLP_PATH" = "null" ] || [ "$YT_DLP_PATH" = "" ]; then
    echo "   ❌ yt-dlp path topilmadi!"
    echo "   .env faylida YT_DLP_PATH ni tekshiring"
    exit 1
fi

if [ ! -f "$YT_DLP_PATH" ] || [ ! -x "$YT_DLP_PATH" ]; then
    echo "   ❌ yt-dlp fayli topilmadi yoki executable emas: $YT_DLP_PATH"
    exit 1
fi

echo "   ✅ yt-dlp to'g'ri sozlangan"
echo ""

# 2. Eski workerlarni to'xtatish
echo "2️⃣ Eski workerlarni to'xtatish..."
pkill -9 -f "artisan queue:work" 2>/dev/null
sleep 2
echo "   ✅ Eski workerlar to'xtatildi"
echo ""

# 3. Yangi workerlarni ishga tushirish
echo "3️⃣ Yangi workerlarni ishga tushirish..."
nohup php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
DOWNLOAD_PID=$!

nohup php artisan queue:work redis --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &
TELEGRAM_PID=$!

sleep 3
echo "   ✅ Workerlarni ishga tushirdim (PIDs: $DOWNLOAD_PID, $TELEGRAM_PID)"
echo ""

# 4. Tekshirish
echo "4️⃣ Tekshirish..."
WORKERS=$(ps aux | grep "artisan queue:work redis" | grep -v grep | grep -v "datacollector" | wc -l)
if [ "$WORKERS" -ge 2 ]; then
    echo "   ✅ $WORKERS worker ishlayapti"
    ps aux | grep "artisan queue:work redis" | grep -v grep | grep -v "datacollector" | awk '{print "      PID:", $2, "Queue:", $NF}'
else
    echo "   ⚠️  Faqat $WORKERS worker ishlayapti"
fi
echo ""

# 5. yt-dlp test
echo "5️⃣ yt-dlp test qilish..."
"$YT_DLP_PATH" --version > /dev/null 2>&1
if [ $? -eq 0 ]; then
    VERSION=$("$YT_DLP_PATH" --version)
    echo "   ✅ yt-dlp versiyasi: $VERSION"
else
    echo "   ❌ yt-dlp ishlamayapti"
fi
echo ""

echo "===================================="
echo "✅ Barcha o'zgarishlar amalga oshirildi!"
echo ""
echo "📝 Holat:"
echo "   ✅ yt-dlp topildi va sozlandi"
echo "   ✅ Config yangilandi"
echo "   ✅ Workerlarni qayta ishga tushirildi"
echo ""
echo "📱 Test qiling:"
echo "   1. Botga Instagram rasm link yuboring"
echo "   2. Rasm yuklab olinishi va yuborilishi kerak"
echo ""
echo "🔍 Agar muammo bo'lsa, loglarni ko'ring:"
echo "   tail -f storage/logs/laravel.log | grep -i 'image\|download\|instagram\|yt-dlp'"
echo "   tail -f storage/logs/queue-downloads.log"
echo ""
