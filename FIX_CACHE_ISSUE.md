# Fix Cache Table Issue

## Problem

Queue workers are failing with:
```
SQLSTATE[42S02]: Base table or view not found: 1146 Table 'download.cache' doesn't exist
```

## Solution Options

### Option 1: Use Redis Cache (Recommended)

Since you're already using Redis for queues, use Redis for cache too:

1. **Update `.env` file**:
```env
CACHE_STORE=redis
```

2. **Clear config cache**:
```bash
php artisan config:clear
php artisan config:cache
```

3. **Restart queue workers**:
```bash
php artisan queue:restart
```

### Option 2: Create Cache Table (If using database cache)

If you prefer to use database cache:

1. **Create cache table migration**:
```bash
php artisan cache:table
```

2. **Run migration**:
```bash
php artisan migrate
```

3. **Restart queue workers**:
```bash
php artisan queue:restart
```

### Option 3: Use File Cache (Simplest, no dependencies)

If you don't want to use Redis or database:

1. **Update `.env` file**:
```env
CACHE_STORE=file
```

2. **Clear config cache**:
```bash
php artisan config:clear
php artisan config:cache
```

3. **Restart queue workers**:
```bash
php artisan queue:restart
```

## Recommended: Use Redis Cache

For production, Redis cache is recommended because:
- ✅ Faster than database cache
- ✅ Already configured (you're using Redis for queues)
- ✅ Better performance
- ✅ No database table needed

## Quick Fix Command

```bash
# Switch to Redis cache
sed -i 's/CACHE_STORE=database/CACHE_STORE=redis/' .env

# Clear and rebuild config
php artisan config:clear
php artisan config:cache

# Restart workers
php artisan queue:restart
```

Then restart your queue workers.
