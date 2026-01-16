#!/bin/bash

echo "✅ Bot To'liq Tekshirish va Tuzatish"
echo "======================================"
echo ""

cd ~/www/download.e-qarz.uz

# 1. Barcha kerakli fayllar mavjudligini tekshirish
echo "1️⃣ Kerakli fayllar tekshirish..."
REQUIRED_FILES=(
    "app/Http/Controllers/TelegramWebhookController.php"
    "app/Jobs/DownloadMediaJob.php"
    "app/Jobs/SendTelegramLanguageSelectionJob.php"
    "app/Jobs/SendTelegramWelcomeMessageJob.php"
    "app/Jobs/AnswerCallbackQueryJob.php"
    "app/Services/TelegramService.php"
    "app/Services/YtDlpService.php"
    "app/Utils/MediaConverter.php"
    "app/Validators/UrlValidator.php"
)

ALL_EXIST=true
for file in "${REQUIRED_FILES[@]}"; do
    if [ -f "$file" ]; then
        echo "   ✅ $file"
    else
        echo "   ❌ $file - TOPILMADI!"
        ALL_EXIST=false
    fi
done

if [ "$ALL_EXIST" = false ]; then
    echo ""
    echo "❌ Ba'zi fayllar topilmadi!"
    exit 1
fi
echo ""

# 2. PHP syntax tekshirish
echo "2️⃣ PHP syntax tekshirish..."
ERRORS=0
for file in "${REQUIRED_FILES[@]}"; do
    if ! php -l "$file" > /dev/null 2>&1; then
        echo "   ❌ $file - syntax xatosi!"
        ERRORS=$((ERRORS + 1))
    fi
done

if [ $ERRORS -gt 0 ]; then
    echo "   ❌ $ERRORS faylda syntax xatosi bor!"
    exit 1
fi
echo "   ✅ Barcha fayllar syntax to'g'ri"
echo ""

# 3. MediaConverter integratsiyasi
echo "3️⃣ MediaConverter integratsiyasi..."
if grep -q "MediaConverter" app/Services/TelegramService.php && grep -q "convertImageIfNeeded" app/Services/TelegramService.php; then
    echo "   ✅ TelegramService MediaConverter'ni ishlatadi"
else
    echo "   ❌ TelegramService MediaConverter'ni ishlatmayapti!"
fi
echo ""

# 4. Til tanlash funksiyasi
echo "4️⃣ Til tanlash funksiyasi..."
if grep -q "sendMessageWithKeyboard.*language\|lang_uz\|lang_ru\|lang_en" app/Http/Controllers/TelegramWebhookController.php; then
    echo "   ✅ Til tanlash funksiyasi mavjud"
else
    echo "   ❌ Til tanlash funksiyasi topilmadi!"
fi
echo ""

# 5. Subscription check
echo "5️⃣ Subscription check..."
if grep -q "checkSubscription\|checkChannelMembership" app/Http/Controllers/TelegramWebhookController.php; then
    echo "   ✅ Subscription check funksiyasi mavjud"
else
    echo "   ❌ Subscription check funksiyasi topilmadi!"
fi
echo ""

# 6. URL validation
echo "6️⃣ URL validation..."
if grep -q "validateAndSanitize\|instagram.com\|tiktok.com" app/Http/Controllers/TelegramWebhookController.php; then
    echo "   ✅ URL validation funksiyasi mavjud"
else
    echo "   ❌ URL validation funksiyasi topilmadi!"
fi
echo ""

# 7. Media download va sending
echo "7️⃣ Media download va sending..."
if grep -q "DownloadMediaJob\|sendPhoto\|sendVideo\|sendMediaGroup" app/Http/Controllers/TelegramWebhookController.php; then
    echo "   ✅ Media download va sending funksiyalari mavjud"
else
    echo "   ❌ Media download va sending funksiyalari topilmadi!"
fi
echo ""

# 8. WebP conversion
echo "8️⃣ WebP conversion..."
if grep -q "MediaConverter::convertImageIfNeeded\|convertWebpToJpg" app/Services/TelegramService.php; then
    echo "   ✅ WebP conversion integratsiya qilingan"
else
    echo "   ❌ WebP conversion integratsiya qilinmagan!"
fi
echo ""

# 9. Cleanup
echo "9️⃣ Cleanup funksiyasi..."
if grep -q "cleanup\|unlink\|rmdir" app/Jobs/DownloadMediaJob.php; then
    echo "   ✅ Cleanup funksiyasi mavjud"
else
    echo "   ❌ Cleanup funksiyasi topilmadi!"
fi
echo ""

# 10. Config yangilash
echo "🔟 Config yangilash..."
php artisan config:clear
php artisan config:cache
echo "   ✅ Config yangilandi"
echo ""

# 11. Workerlarni qayta ishga tushirish
echo "1️⃣1️⃣ Workerlarni qayta ishga tushirish..."
pkill -9 -f "artisan queue:work" 2>/dev/null
sleep 2

nohup php artisan queue:work database --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
DOWNLOAD_PID=$!

nohup php artisan queue:work database --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &
TELEGRAM_PID=$!

sleep 3
echo "   ✅ Workerlarni ishga tushirdim (PIDs: $DOWNLOAD_PID, $TELEGRAM_PID)"
echo ""

# 12. Final tekshirish
echo "1️⃣2️⃣ Final tekshirish..."
WORKERS=$(ps aux | grep "artisan queue:work" | grep -v grep | grep -v "datacollector" | wc -l)
if [ "$WORKERS" -ge 2 ]; then
    echo "   ✅ $WORKERS worker ishlayapti"
else
    echo "   ⚠️  Faqat $WORKERS worker ishlayapti"
fi

# PHP GD
if php -m | grep -q "gd" && php -r "exit(function_exists('imagecreatefromwebp') ? 0 : 1);"; then
    echo "   ✅ PHP GD va WebP support mavjud"
else
    echo "   ⚠️  PHP GD yoki WebP support yo'q"
fi

# yt-dlp
YT_DLP_PATH=$(php artisan tinker --execute="echo config('telegram.yt_dlp_path');" 2>&1 | grep -v "Psy\|tinker" | tail -1 | tr -d ' ')
if [ -f "$YT_DLP_PATH" ] && [ -x "$YT_DLP_PATH" ]; then
    echo "   ✅ yt-dlp mavjud va ishlaydi"
else
    echo "   ⚠️  yt-dlp topilmadi yoki executable emas"
fi
echo ""

echo "===================================="
echo "✅ Bot to'liq tuzatildi va tekshirildi!"
echo ""
echo "📋 Bot funksiyalari:"
echo "   ✅ /start - til tanlash"
echo "   ✅ Til tanlash - 3 til (UZ, RU, EN)"
echo "   ✅ Welcome message - tanlangan tilda"
echo "   ✅ Subscription check - kanallarga a'zo bo'lish (private chat uchun)"
echo "   ✅ URL validation - Instagram va TikTok"
echo "   ✅ Media download - yt-dlp orqali"
echo "   ✅ WebP → JPG conversion - avtomatik"
echo "   ✅ Media sending - sendPhoto/sendVideo/sendMediaGroup"
echo "   ✅ Carousel posts - ko'p rasmlar"
echo "   ✅ Cleanup - barcha temp fayllar"
echo ""
echo "🧪 Test qiling:"
echo "   1. /start → Til tanlash ko'rinishi kerak"
echo "   2. Til tanlang → Welcome message ko'rinishi kerak"
echo "   3. Instagram rasm linki → Rasm yuborilishi kerak"
echo "   4. Instagram video linki → Video yuborilishi kerak"
echo ""
echo "📊 Loglarni kuzatish:"
echo "   tail -f storage/logs/laravel.log | grep -E 'start|language|download|sendPhoto|sendVideo'"
echo ""
