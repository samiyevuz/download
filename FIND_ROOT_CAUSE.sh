#!/bin/bash

echo "🔍 ASOSIY MUAMMONI TOPISH..."
echo ""

# 1. PHP syntax xatolarini tekshirish
echo "1️⃣ PHP syntax tekshirish..."
php -l app/Jobs/DownloadMediaJob.php
php -l app/Jobs/SendTelegramMessageJob.php
php -l app/Http/Controllers/TelegramWebhookController.php
echo ""

# 2. Redis ulanishini tekshirish
echo "2️⃣ Redis ulanishi..."
php artisan tinker <<'EOF'
try {
    Redis::ping();
    echo "✅ Redis ulandi\n";
} catch (Exception $e) {
    echo "❌ Redis xatosi: " . $e->getMessage() . "\n";
}
EOF
echo ""

# 3. Config tekshirish
echo "3️⃣ Config tekshirish..."
php artisan tinker <<'EOF'
try {
    echo "Queue connection: " . config('queue.default') . "\n";
    echo "Redis client: " . config('database.redis.client') . "\n";
    echo "Telegram token: " . (config('telegram.bot_token') ? 'Mavjud' : 'Yo\'q') . "\n";
} catch (Exception $e) {
    echo "❌ Config xatosi: " . $e->getMessage() . "\n";
}
EOF
echo ""

# 4. Workerlarni FOREGROUND'da ishga tushirish (xatolarni ko'rish uchun)
echo "4️⃣ Workerlarni FOREGROUND'da ishga tushirish (5 soniya)..."
echo "   Agar xato bo'lsa, darhol ko'rinadi:"
echo ""

timeout 5 php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 2>&1 | head -30 || echo "   (Timeout yoki xato)"
echo ""

echo "✅ Tekshiruv tugadi!"
echo ""
echo "📋 KEYINGI QADAM:"
echo "   Agar yuqorida xato ko'rsatilgan bo'lsa, uni tuzating."
echo "   Agar xato yo'q bo'lsa, workerlarni background'da ishga tushiring."
