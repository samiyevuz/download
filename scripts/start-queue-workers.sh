#!/bin/bash

# Start Queue Workers for Telegram Bot
# This script starts separate workers for downloads and telegram queues

PROJECT_PATH="/path/to/your/project"
QUEUE_CONNECTION="redis-downloads"
TELEGRAM_QUEUE_CONNECTION="redis-telegram"

cd "$PROJECT_PATH" || exit 1

echo "Starting queue workers..."

# Start downloads queue workers (2 workers)
for i in {1..2}; do
    nohup php artisan queue:work "$QUEUE_CONNECTION" \
        --queue=downloads \
        --sleep=3 \
        --tries=2 \
        --max-time=3600 \
        --timeout=60 \
        --max-jobs=50 \
        >> storage/logs/queue-downloads-worker-$i.log 2>&1 &
    echo "Started downloads worker $i (PID: $!)"
done

# Start telegram queue workers (4 workers)
for i in {1..4}; do
    nohup php artisan queue:work "$TELEGRAM_QUEUE_CONNECTION" \
        --queue=telegram \
        --sleep=1 \
        --tries=3 \
        --max-time=3600 \
        --timeout=10 \
        --max-jobs=100 \
        >> storage/logs/queue-telegram-worker-$i.log 2>&1 &
    echo "Started telegram worker $i (PID: $!)"
done

echo "All workers started!"
echo "Check logs in: storage/logs/queue-*.log"
echo "To stop workers: pkill -f 'queue:work'"
