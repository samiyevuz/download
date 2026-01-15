#!/bin/bash

# Queue Monitoring Script
# Monitors queue health and worker status

PROJECT_PATH="/path/to/your/project"

cd "$PROJECT_PATH" || exit 1

echo "=== Queue Health Monitor ==="
echo ""

# Check Redis queue lengths
if command -v redis-cli &> /dev/null; then
    echo "📊 Queue Lengths:"
    DOWNLOADS_LEN=$(redis-cli LLEN queues:downloads 2>/dev/null || echo "N/A")
    TELEGRAM_LEN=$(redis-cli LLEN queues:telegram 2>/dev/null || echo "N/A")
    echo "  Downloads queue: $DOWNLOADS_LEN jobs"
    echo "  Telegram queue: $TELEGRAM_LEN jobs"
    echo ""
fi

# Check running workers
echo "👷 Running Workers:"
WORKER_COUNT=$(ps aux | grep -c "[q]ueue:work" || echo "0")
echo "  Total workers: $WORKER_COUNT"
ps aux | grep "[q]ueue:work" | grep -v grep | awk '{print "  PID: " $2 " | " $11 " " $12 " " $13}'
echo ""

# Check failed jobs
echo "❌ Failed Jobs:"
FAILED_COUNT=$(php artisan queue:failed --format=json 2>/dev/null | jq '. | length' 2>/dev/null || echo "0")
echo "  Failed jobs count: $FAILED_COUNT"
echo ""

# Check supervisor status (if using supervisor)
if command -v supervisorctl &> /dev/null; then
    echo "🔧 Supervisor Status:"
    supervisorctl status | grep telegram-bot || echo "  No supervisor workers found"
    echo ""
fi

# Check disk space
echo "💾 Disk Space:"
df -h . | tail -1 | awk '{print "  Available: " $4 " | Used: " $5}'
echo ""

# Check recent logs
echo "📝 Recent Errors (last 5):"
tail -n 5 storage/logs/laravel.log 2>/dev/null | grep -i error || echo "  No recent errors"
echo ""

echo "=== End Monitor ==="
