#!/bin/bash

echo "🔍 Xatolarni tekshirish..."
echo ""

echo "1️⃣ Oxirgi 50 qator log (xato xabarlari bilan):"
echo "=========================================="
tail -50 storage/logs/laravel.log | grep -A 5 -B 5 -E "(ERROR|Exception|Failed|error)" | tail -30
echo ""

echo "2️⃣ Oxirgi xato (to'liq):"
echo "=========================================="
tail -100 storage/logs/laravel.log | grep -A 20 "local.ERROR" | tail -25
echo ""

echo "3️⃣ DownloadMediaJob xatolari:"
echo "=========================================="
tail -100 storage/logs/laravel.log | grep -A 10 "DownloadMediaJob" | tail -15
echo ""

echo "4️⃣ Queue worker loglari:"
echo "=========================================="
echo "Downloads queue:"
tail -20 storage/logs/queue-downloads.log 2>/dev/null || echo "Log fayli topilmadi"
echo ""
echo "Telegram queue:"
tail -20 storage/logs/queue-telegram.log 2>/dev/null || echo "Log fayli topilmadi"
echo ""

echo "✅ Tekshiruv tugadi!"
