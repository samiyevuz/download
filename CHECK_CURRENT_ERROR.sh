#!/bin/bash

echo "🔍 Hozirgi xatolarni tekshirish..."
echo ""

# 1. Oxirgi xatolarni ko'rish
echo "1️⃣ Oxirgi xatolar (oxirgi 10 daqiqa):"
echo "-----------------------------------"
tail -100 storage/logs/laravel.log | grep -A 5 "ERROR\|Exception\|failed" | tail -30
echo ""

# 2. Queue worker loglari
echo "2️⃣ Queue worker loglari:"
echo "-----------------------------------"
tail -30 storage/logs/queue-downloads.log
echo ""

# 3. Eng oxirgi to'liq xato
echo "3️⃣ Eng oxirgi to'liq xato:"
echo "-----------------------------------"
tail -200 storage/logs/laravel.log | grep -B 3 -A 20 "Download failed\|yt-dlp\|Instagram" | tail -40
echo ""

echo "✅ Tekshiruv tugadi!"
