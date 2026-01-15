#!/bin/bash

echo "🔍 TO'LIQ XATO XABARNI TOPISH"
echo "=============================="
echo ""

echo "1️⃣ Oxirgi DownloadMediaJob xatolari:"
echo "-----------------------------------"
tail -200 storage/logs/laravel.log | grep -A 20 "DownloadMediaJob\|yt-dlp download failed\|Download failed" | tail -40
echo ""

echo "2️⃣ yt-dlp xatolari:"
echo "-----------------------------------"
tail -200 storage/logs/laravel.log | grep -A 15 "yt-dlp" | grep -E "(error|ERROR|failed|Failed)" | tail -10
echo ""

echo "3️⃣ Eng oxirgi to'liq xato:"
echo "-----------------------------------"
tail -300 storage/logs/laravel.log | grep -B 10 -A 30 "local.ERROR.*Download failed" | tail -40
echo ""

echo "4️⃣ Queue worker log:"
echo "-----------------------------------"
tail -50 storage/logs/queue-downloads.log
echo ""

echo "✅ Tekshiruv tugadi!"
