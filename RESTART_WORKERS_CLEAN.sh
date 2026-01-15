#!/bin/bash

echo "🔄 Workerlarni to'liq qayta ishga tushirish..."
echo ""

# 1. Barcha workerlarni to'xtatish
echo "1️⃣ Eski workerlarni to'xtatish..."
pkill -9 -f "download.e-qarz.uz.*queue:work" 2>/dev/null
pkill -9 -f "artisan queue:work" 2>/dev/null
sleep 3

# 2. Loglarni tozalash (eski xatolarni olib tashlash)
echo "2️⃣ Loglarni tozalash..."
> storage/logs/queue-downloads.log
> storage/logs/queue-telegram.log
echo "✅ Loglar tozalandi"
echo ""

# 3. Config yangilash
echo "3️⃣ Config yangilash..."
php artisan config:clear
php artisan config:cache
echo "✅ Config yangilandi"
echo ""

# 4. failed_jobs jadvalini tekshirish
echo "4️⃣ failed_jobs jadvalini tekshirish..."
php artisan tinker <<'EOF'
try {
    $count = DB::table('failed_jobs')->count();
    echo "✅ failed_jobs jadvali mavjud (yozuvlar: $count)\n";
} catch (Exception $e) {
    echo "❌ failed_jobs jadvali yo'q! Yaratilmoqda...\n";
    DB::statement("CREATE TABLE IF NOT EXISTS failed_jobs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        uuid VARCHAR(255) UNIQUE NOT NULL,
        connection TEXT NOT NULL,
        queue TEXT NOT NULL,
        payload LONGTEXT NOT NULL,
        exception LONGTEXT NOT NULL,
        failed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "✅ Jadval yaratildi\n";
}
EOF
echo ""

# 5. Workerlarni qayta ishga tushirish
echo "5️⃣ Workerlarni qayta ishga tushirish..."
cd /var/www/sardor/data/www/download.e-qarz.uz

nohup php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
DOWNLOAD_PID=$!

nohup php artisan queue:work redis --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &
TELEGRAM_PID=$!

sleep 3
echo "✅ Workerlarni ishga tushirdim (PIDs: $DOWNLOAD_PID, $TELEGRAM_PID)"
echo ""

# 6. Tekshirish
echo "6️⃣ Tekshirish..."
WORKERS=$(ps aux | grep "download.e-qarz.uz.*queue:work" | grep -v grep | wc -l)
echo "   Ishlayotgan workerlar: $WORKERS"

if [ "$WORKERS" -ge 2 ]; then
    echo "✅ Workerlarni ishlayapti"
    ps aux | grep "download.e-qarz.uz.*queue:work" | grep -v grep
else
    echo "⚠️  Workerlarni qayta ishga tushirish kerak"
    echo "   Loglarni tekshiring:"
    echo "   tail -20 storage/logs/queue-downloads.log"
    echo "   tail -20 storage/logs/queue-telegram.log"
fi
echo ""

# 7. Birinchi 10 qator loglarni ko'rsatish
echo "7️⃣ Birinchi loglar:"
echo "Downloads queue:"
head -10 storage/logs/queue-downloads.log 2>/dev/null || echo "   (hali log yo'q)"
echo ""
echo "Telegram queue:"
head -10 storage/logs/queue-telegram.log 2>/dev/null || echo "   (hali log yo'q)"
echo ""

echo "✅ Barcha o'zgarishlar amalga oshirildi!"
echo ""
echo "🎉 Endi botga Instagram link yuborib test qiling!"
