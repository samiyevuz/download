#!/bin/bash

echo "🔧 Barcha muammolarni hal qilish..."
echo ""

# 1. failed_jobs jadvalini yaratish (agar yo'q bo'lsa)
echo "1️⃣ failed_jobs jadvalini tekshirish..."
php artisan tinker --execute="try { DB::table('failed_jobs')->count(); echo 'failed_jobs jadvali mavjud'; } catch (Exception \$e) { echo 'failed_jobs jadvali yoq, yaratilmoqda...'; }" 2>&1 | grep -v "Psy\|tinker"

# Agar yo'q bo'lsa, SQL orqali yaratish
php artisan tinker --execute="try { DB::select('SELECT 1 FROM failed_jobs LIMIT 1'); echo 'OK'; } catch (Exception \$e) { Schema::create('failed_jobs', function(\$table) { \$table->id(); \$table->string('uuid')->unique(); \$table->text('connection'); \$table->text('queue'); \$table->longText('payload'); \$table->longText('exception'); \$table->timestamp('failed_at')->useCurrent(); }); echo 'Yaratildi'; }" 2>&1 | grep -v "Psy\|tinker" || echo "Jadval yaratishga harakat qilindi"
echo ""

# 2. Config yangilash
echo "2️⃣ Config yangilash..."
php artisan config:clear
php artisan config:cache
echo "✅ Config yangilandi"
echo ""

# 3. Workerlarni qayta ishga tushirish
echo "3️⃣ Workerlarni qayta ishga tushirish..."
pkill -f "download.e-qarz.uz.*queue:work" 2>/dev/null
sleep 2

nohup php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
nohup php artisan queue:work redis --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &

sleep 2
echo "✅ Workerlarni qayta ishga tushirdim"
echo ""

# 4. Tekshirish
echo "4️⃣ Tekshirish..."
WORKERS=$(ps aux | grep "download.e-qarz.uz.*queue:work" | grep -v grep | wc -l)
echo "   Ishlayotgan workerlar: $WORKERS"
if [ "$WORKERS" -ge 2 ]; then
    echo "✅ Workerlarni ishlayapti"
else
    echo "⚠️  Workerlarni qayta ishga tushirish kerak"
fi
echo ""

echo "✅ Barcha o'zgarishlar amalga oshirildi!"
echo ""
echo "🎉 Endi botga Instagram link yuborib test qiling!"
