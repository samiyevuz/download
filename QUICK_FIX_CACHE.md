# Quick Fix: Cache Table Error

## The Problem

Your queue workers are failing because Laravel is trying to use the `cache` database table, but it doesn't exist.

## Quick Fix (Choose One)

### ✅ Option 1: Switch to Redis Cache (RECOMMENDED)

Since you're already using Redis for queues, use Redis for cache too:

```bash
# Edit .env file
nano .env

# Change this line:
CACHE_STORE=database

# To this:
CACHE_STORE=redis

# Save and exit (Ctrl+X, then Y, then Enter)

# Clear config cache
php artisan config:clear
php artisan config:cache

# Restart queue workers
php artisan queue:restart
```

### Option 2: Create Cache Table

If you want to keep using database cache:

```bash
# Create cache table migration
php artisan cache:table

# Run migration
php artisan migrate

# Restart queue workers
php artisan queue:restart
```

### Option 3: Use File Cache

Simplest option, no dependencies:

```bash
# Edit .env file
nano .env

# Change:
CACHE_STORE=file

# Then:
php artisan config:clear
php artisan config:cache
php artisan queue:restart
```

## Recommended Solution

**Use Redis cache** (Option 1) because:
- ✅ You're already using Redis for queues
- ✅ Much faster than database cache
- ✅ Better for production
- ✅ No additional setup needed

## After Fixing

Restart your queue workers:

```bash
php artisan queue:work redis-downloads --queue=downloads --tries=2 --timeout=60
php artisan queue:work redis-telegram --queue=telegram --tries=3 --timeout=10
```

Or if using Supervisor, restart the workers:

```bash
sudo supervisorctl restart telegram-bot-downloads-worker:*
sudo supervisorctl restart telegram-bot-telegram-worker:*
```
