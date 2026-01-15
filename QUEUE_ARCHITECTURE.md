# Queue-Based Architecture Documentation

## 🏗️ Architecture Overview

This Telegram bot uses a **fully asynchronous, queue-based architecture** to ensure:
- ✅ Webhook responses in under 1 second
- ✅ No blocking operations during webhook requests
- ✅ Scalable and fault-tolerant processing
- ✅ Proper error handling and retry logic

## 📊 Architecture Flow

```
┌─────────────────┐
│  Telegram API   │
│   (Webhook)     │
└────────┬────────┘
         │
         ▼
┌─────────────────────────┐
│ TelegramWebhookController│
│  - Validates request     │
│  - Dispatches job        │
│  - Returns 200 OK        │
│  (< 1 second response)   │
└────────┬─────────────────┘
         │
         ▼
┌─────────────────────────┐
│    Queue System         │
│  (Redis/Database)       │
│                         │
│  ┌──────────────┐      │
│  │  downloads   │      │
│  │   queue     │      │
│  └──────────────┘      │
│  ┌──────────────┐      │
│  │  telegram   │      │
│  │   queue     │      │
│  └──────────────┘      │
└────────┬─────────────────┘
         │
         ▼
┌─────────────────────────┐
│   Queue Workers          │
│                         │
│  Worker 1: downloads    │
│  Worker 2: downloads     │
│  Worker 3: telegram     │
│  Worker 4: telegram     │
└────────┬─────────────────┘
         │
         ▼
┌─────────────────────────┐
│   DownloadMediaJob       │
│  - Creates temp dir      │
│  - Executes yt-dlp       │
│  - Sends media to user  │
│  - Cleans up files      │
└─────────────────────────┘
```

## 🔄 Queue Types

### 1. Downloads Queue (`downloads`)

**Purpose**: Handle heavy yt-dlp download operations

**Characteristics**:
- Long-running jobs (up to 60 seconds)
- CPU and I/O intensive
- Fewer concurrent workers recommended
- Higher timeout values

**Job Types**:
- `DownloadMediaJob` - Downloads and processes media

**Configuration**:
```php
// config/queue.php
'redis-downloads' => [
    'driver' => 'redis',
    'queue' => 'downloads',
    'retry_after' => 120,
],
```

### 2. Telegram Queue (`telegram`)

**Purpose**: Handle lightweight Telegram API calls

**Characteristics**:
- Quick operations (< 5 seconds)
- Network I/O only
- More concurrent workers possible
- Lower timeout values

**Job Types**:
- `SendTelegramMessageJob` - Sends text messages

**Configuration**:
```php
// config/queue.php
'redis-telegram' => [
    'driver' => 'redis',
    'queue' => 'telegram',
    'retry_after' => 30,
],
```

## 🚀 Queue Worker Configuration

### Development

```bash
# Single worker for all queues
php artisan queue:work --tries=2 --timeout=60

# Separate workers for each queue
php artisan queue:work redis-downloads --queue=downloads --tries=2 --timeout=60
php artisan queue:work redis-telegram --queue=telegram --tries=3 --timeout=10
```

### Production (Supervisor)

Create `/etc/supervisor/conf.d/telegram-bot-workers.conf`:

```ini
[program:telegram-bot-downloads-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/project/artisan queue:work redis-downloads --queue=downloads --sleep=3 --tries=2 --max-time=3600 --timeout=60 --max-jobs=50
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/project/storage/logs/queue-downloads.log
stopwaitsecs=3600

[program:telegram-bot-telegram-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/project/artisan queue:work redis-telegram --queue=telegram --sleep=1 --tries=3 --max-time=3600 --timeout=10 --max-jobs=100
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/path/to/project/storage/logs/queue-telegram.log
stopwaitsecs=3600
```

**Key Settings Explained**:
- `numprocs=2` (downloads): Fewer workers for CPU-intensive tasks
- `numprocs=4` (telegram): More workers for quick API calls
- `--max-jobs=50`: Restart worker after 50 jobs (prevents memory leaks)
- `--max-time=3600`: Restart worker after 1 hour
- `--timeout=60`: Job timeout (downloads)
- `--timeout=10`: Job timeout (telegram)

**Start Workers**:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start telegram-bot-downloads-worker:*
sudo supervisorctl start telegram-bot-telegram-worker:*
```

## 🔄 Job Retry Logic

### Smart Retry Strategy

The `DownloadMediaJob` implements intelligent retry logic:

**Retry Conditions** (Network/Process Errors):
- ✅ Timeout errors
- ✅ Network/connection errors
- ✅ Process execution errors
- ✅ Temporary file errors

**No Retry** (Permanent Errors):
- ❌ Invalid URLs
- ❌ Private/unavailable content
- ❌ Content not found
- ❌ No files downloaded

**Retry Configuration**:
```php
public int $tries = 2;  // Maximum retry attempts
public int $timeout = 60;  // Job timeout in seconds

public function backoff(): array
{
    return [5, 15];  // Wait 5s, then 15s before retry
}
```

## 📈 Performance Optimization

### Worker Scaling Guidelines

**Downloads Queue**:
- Start with 2 workers
- Monitor CPU usage
- Scale up if queue backs up
- Each worker uses ~50-100MB RAM

**Telegram Queue**:
- Start with 4 workers
- Can scale to 8-10 workers easily
- Each worker uses ~20-30MB RAM

### Monitoring Queue Health

```bash
# Check queue length
php artisan queue:monitor downloads telegram

# View failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all

# Clear failed jobs
php artisan queue:flush
```

### Redis Queue Monitoring

```bash
# Check Redis queue length
redis-cli LLEN queues:downloads
redis-cli LLEN queues:telegram

# View all queues
redis-cli KEYS "queues:*"
```

## 🛡️ Error Handling

### Job Failure Flow

1. **Job Exception Thrown**
   - Log error with context
   - Determine if retryable
   - Send user notification (if not retrying)

2. **Retry Decision**
   - Network/process errors → Retry
   - Validation errors → Don't retry
   - Max attempts reached → Mark as failed

3. **Permanent Failure**
   - Log to `failed_jobs` table
   - Send error message to user
   - Clean up temporary files

### Error Notification

Users receive friendly error messages:
- "❌ Download failed. The content may be private or unavailable."

## 🔍 Monitoring & Debugging

### Log Files

```bash
# Application logs
tail -f storage/logs/laravel.log

# Queue worker logs (if using supervisor)
tail -f storage/logs/queue-downloads.log
tail -f storage/logs/queue-telegram.log
```

### Key Metrics to Monitor

1. **Queue Length**: Should stay low (< 100 jobs)
2. **Failed Jobs**: Should be minimal
3. **Worker Memory**: Should be stable
4. **Job Duration**: Downloads ~10-30s, Telegram < 1s
5. **Error Rate**: Should be < 5%

### Health Check Commands

```bash
# Check if workers are running
ps aux | grep "queue:work"

# Check supervisor status
sudo supervisorctl status

# Check Redis connection
redis-cli ping

# Check queue statistics
php artisan queue:monitor
```

## 🚨 Troubleshooting

### Queue Not Processing

1. **Check Workers Running**:
   ```bash
   ps aux | grep queue:work
   sudo supervisorctl status
   ```

2. **Check Redis Connection**:
   ```bash
   redis-cli ping
   # Should return: PONG
   ```

3. **Check Queue Configuration**:
   ```bash
   php artisan config:cache
   php artisan queue:restart
   ```

### Jobs Stuck in Queue

1. **Clear Stuck Jobs**:
   ```bash
   php artisan queue:restart
   ```

2. **Check for Dead Workers**:
   ```bash
   sudo supervisorctl restart all
   ```

### High Memory Usage

1. **Reduce Worker Count**:
   Edit supervisor config, reduce `numprocs`

2. **Enable Job Limits**:
   Add `--max-jobs=50` to worker command

3. **Monitor Memory**:
   ```bash
   ps aux | grep queue:work | awk '{print $6/1024 " MB"}'
   ```

## 📋 Best Practices

1. **Always Use Queues**: Never perform long operations in webhook
2. **Separate Queue Types**: Use different queues for different job types
3. **Monitor Queue Length**: Set up alerts for queue backup
4. **Regular Worker Restarts**: Use `--max-jobs` and `--max-time`
5. **Proper Error Handling**: Log everything, notify users appropriately
6. **Resource Limits**: Set appropriate timeouts and memory limits
7. **Cleanup**: Always clean up temporary files in `finally` blocks

## 🔐 Security Considerations

1. **Queue Authentication**: Use Redis password in production
2. **Job Data**: Sensitive data is serialized, ensure encryption
3. **File Permissions**: Temporary files use UUID-based paths
4. **Rate Limiting**: Consider rate limiting for webhook endpoint

## 📊 Expected Performance

**Webhook Response Time**: < 1 second
**Download Job Duration**: 10-30 seconds (average)
**Telegram Job Duration**: < 1 second
**Concurrent Users**: 50+ (with proper worker scaling)
**Queue Throughput**: 100+ jobs/minute (with 4 download workers)

---

**Last Updated**: 2025-01-15
**Version**: 1.0.0
