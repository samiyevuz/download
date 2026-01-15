# Production Hardening Guide

## 🛡️ Hardening Overview

This document describes all production hardening measures implemented to ensure the Telegram bot runs reliably 24/7 without timeouts, freezes, memory leaks, or zombie processes.

## ✅ Hardening Measures Implemented

### 1. Process Management

#### Process Isolation
- ✅ Each yt-dlp process runs in isolated working directory
- ✅ Processes don't inherit environment variables
- ✅ Process groups properly managed

#### Process Termination
- ✅ Automatic process termination on timeout
- ✅ Force kill on exceptions
- ✅ Process group termination (kills all children)
- ✅ Graceful shutdown (SIGTERM) before force kill (SIGKILL)

**Implementation**: `YtDlpService::forceKillProcess()`

#### Zombie Process Prevention
- ✅ Automatic cleanup of stuck processes
- ✅ Scheduled zombie process detection
- ✅ Manual cleanup command available

**Commands**:
```bash
# Dry-run (detect only)
php artisan process:cleanup-zombies

# Actually kill zombies
php artisan process:cleanup-zombies --kill
```

### 2. Memory Management

#### Job Memory Limits
- ✅ Per-job memory limit: 512MB (downloads)
- ✅ Per-job memory limit: 256MB (telegram)
- ✅ Memory usage logging
- ✅ Warnings when memory usage exceeds 90%

**Implementation**: `DownloadMediaJob::$memory`

#### Worker Memory Limits
- ✅ Supervisor memory limits per worker
- ✅ Automatic worker restart after N jobs
- ✅ Automatic worker restart after time limit

**Supervisor Config**:
```ini
memlimit=536870912  # 512MB for downloads workers
memlimit=268435456  # 256MB for telegram workers
```

### 3. Timeout Protection

#### Process Timeouts
- ✅ Execution timeout: 60 seconds
- ✅ Idle timeout: 60 seconds
- ✅ Automatic termination on timeout

#### Job Timeouts
- ✅ Job timeout: 60 seconds
- ✅ Queue retry timeout: 120 seconds
- ✅ Worker max execution time: 3600 seconds (1 hour)

### 4. File Cleanup Guarantees

#### Automatic Cleanup
- ✅ Cleanup in `finally` blocks (always executes)
- ✅ Shutdown function registration (fatal error protection)
- ✅ Retry mechanism for cleanup failures
- ✅ Fallback to system commands if needed

**Implementation**: `DownloadMediaJob::cleanup()`

#### Orphaned File Cleanup
- ✅ Scheduled cleanup job runs hourly
- ✅ Removes temp directories older than 2 hours
- ✅ Logs cleanup statistics

**Scheduled Job**: `CleanupOrphanedFilesJob`

### 5. Queue Worker Hardening

#### Worker Configuration
- ✅ Separate workers for different queue types
- ✅ Memory limits per worker
- ✅ Max jobs per worker (prevents memory leaks)
- ✅ Max time per worker (prevents infinite loops)
- ✅ Graceful shutdown handling

#### Supervisor Configuration
```ini
# Downloads Workers
numprocs=2
memlimit=536870912  # 512MB
--max-jobs=50
--max-time=3600

# Telegram Workers
numprocs=4
memlimit=268435456  # 256MB
--max-jobs=100
--max-time=3600
```

### 6. Error Handling

#### Exception Handling
- ✅ Try-catch blocks around all critical operations
- ✅ Process termination in finally blocks
- ✅ Cleanup guaranteed even on exceptions
- ✅ Comprehensive error logging

#### Failure Recovery
- ✅ Smart retry logic (only network/process errors)
- ✅ Failed job tracking
- ✅ User notification on permanent failures
- ✅ No retries for validation errors

### 7. Resource Monitoring

#### Health Checks
- ✅ Queue health check command
- ✅ Process monitoring
- ✅ Memory usage tracking
- ✅ Disk space monitoring

**Commands**:
```bash
php artisan queue:health
php artisan queue:health --detailed
php artisan process:cleanup-zombies
```

## 📋 Production Checklist

### Pre-Deployment

- [ ] Supervisor configuration installed
- [ ] Memory limits configured appropriately
- [ ] Queue workers started and monitored
- [ ] Cleanup jobs scheduled
- [ ] Log rotation configured
- [ ] Disk space monitoring enabled
- [ ] Health check commands tested

### Runtime Monitoring

- [ ] Queue lengths monitored (< 100 jobs)
- [ ] Worker memory usage tracked
- [ ] Failed jobs reviewed regularly
- [ ] Zombie processes checked hourly
- [ ] Disk space checked daily
- [ ] Logs reviewed for errors

### Maintenance

- [ ] Cleanup jobs running successfully
- [ ] Orphaned files removed regularly
- [ ] Zombie processes killed automatically
- [ ] Workers restarting properly
- [ ] Memory leaks not occurring
- [ ] No timeout issues

## 🔧 Configuration

### Environment Variables

```env
# Memory limits
PHP_MEMORY_LIMIT=512M

# Timeouts
DOWNLOAD_TIMEOUT=60

# Queue
QUEUE_CONNECTION=redis
REDIS_QUEUE_RETRY_AFTER=120
```

### Supervisor Configuration

See `supervisor/telegram-bot-workers.conf` for complete configuration.

**Key Settings**:
- `memlimit`: Memory limit per worker
- `stopwaitsecs`: Graceful shutdown timeout
- `stopsignal`: TERM (graceful) before KILL
- `max-jobs`: Restart after N jobs
- `max-time`: Restart after N seconds

### Scheduler Configuration

Add to `routes/console.php`:
```php
// Cleanup orphaned files hourly
Schedule::job(new \App\Jobs\CleanupOrphanedFilesJob())
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// Cleanup zombie processes every 30 minutes
Schedule::command('process:cleanup-zombies --kill')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->onOneServer();
```

## 🚨 Troubleshooting

### High Memory Usage

1. **Check worker memory**:
   ```bash
   ps aux | grep queue:work | awk '{print $6/1024 " MB"}'
   ```

2. **Reduce worker count**:
   Edit supervisor config, reduce `numprocs`

3. **Lower max-jobs**:
   Reduce `--max-jobs` to restart workers more frequently

### Zombie Processes

1. **Detect zombies**:
   ```bash
   php artisan process:cleanup-zombies
   ```

2. **Kill zombies**:
   ```bash
   php artisan process:cleanup-zombies --kill
   ```

3. **Check for stuck processes**:
   ```bash
   ps aux | grep yt-dlp
   ```

### Orphaned Files

1. **Check temp directory**:
   ```bash
   du -sh storage/app/temp/downloads
   ```

2. **Manual cleanup**:
   ```bash
   find storage/app/temp/downloads -type d -mtime +2 -exec rm -rf {} +
   ```

3. **Verify cleanup job**:
   Check scheduler logs for `CleanupOrphanedFilesJob`

### Worker Crashes

1. **Check supervisor status**:
   ```bash
   sudo supervisorctl status
   ```

2. **Check logs**:
   ```bash
   tail -f storage/logs/queue-*.log
   ```

3. **Restart workers**:
   ```bash
   sudo supervisorctl restart telegram-bot-*-worker:*
   ```

## 📊 Monitoring Recommendations

### Metrics to Track

1. **Queue Metrics**:
   - Queue length (should be < 100)
   - Processing rate (jobs/minute)
   - Failed job rate (should be < 5%)

2. **Resource Metrics**:
   - Worker memory usage (should be stable)
   - Disk space usage (should not grow unbounded)
   - CPU usage (should be reasonable)

3. **Process Metrics**:
   - Active yt-dlp processes (should be < worker count)
   - Zombie process count (should be 0)
   - Process runtime (should be < timeout)

### Alerting Thresholds

- **Queue Length**: Alert if > 200 jobs
- **Failed Jobs**: Alert if > 10% failure rate
- **Memory Usage**: Alert if > 80% of limit
- **Disk Space**: Alert if < 10% free
- **Zombie Processes**: Alert if > 0

## 🔐 Security Considerations

1. **Process Isolation**: Each job runs in isolated directory
2. **Command Injection**: URL validation prevents injection
3. **Resource Limits**: Memory and time limits prevent DoS
4. **File Permissions**: Temp files use restrictive permissions
5. **Cleanup**: Automatic cleanup prevents disk exhaustion

## 📈 Performance Optimization

### Worker Scaling

**Downloads Workers**:
- Start with 2 workers
- Scale up if queue backs up
- Monitor CPU usage
- Each worker: ~50-100MB RAM

**Telegram Workers**:
- Start with 4 workers
- Can scale to 8-10 workers
- Each worker: ~20-30MB RAM

### Queue Tuning

- **Downloads Queue**: Longer timeout (120s), fewer workers
- **Telegram Queue**: Shorter timeout (30s), more workers
- **Redis**: Tune `retry_after` based on job duration

## ✅ Verification

### Test Hardening Measures

1. **Process Termination**:
   ```bash
   # Start a long-running job, then kill worker
   # Verify process is terminated
   ps aux | grep yt-dlp
   ```

2. **Memory Limits**:
   ```bash
   # Monitor memory during job execution
   watch -n 1 'ps aux | grep queue:work'
   ```

3. **Cleanup**:
   ```bash
   # Create temp files, then check cleanup
   ls -la storage/app/temp/downloads
   ```

4. **Zombie Detection**:
   ```bash
   # Run cleanup command
   php artisan process:cleanup-zombies
   ```

## 🎯 Success Criteria

The system is considered hardened when:

- ✅ No webhook timeouts (> 1 second)
- ✅ No server freezes
- ✅ No zombie processes accumulate
- ✅ No memory leaks
- ✅ No orphaned files accumulate
- ✅ Workers restart automatically
- ✅ Cleanup always executes
- ✅ Processes always terminate

---

**Version**: 1.0.0  
**Last Updated**: 2025-01-15  
**Status**: ✅ Production Ready
