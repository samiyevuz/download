# Workerlar Nega Ishga Tushmagan - Tekshirish

## Muammo
Workerlar ishga tushmagan (ps aux'da ko'rinmayapti)

## Tekshirish Qadamlari

### 1. Worker loglarini ko'rish
```bash
cat storage/logs/queue-downloads.log
cat storage/logs/queue-telegram.log
```

### 2. Redis ishlayaptimi?
```bash
redis-cli ping
```

### 3. Config to'g'rimi?
```bash
php artisan config:show queue.connections.redis-downloads
```

### 4. Queue connection'ni test qilish
```bash
php artisan tinker
# Tinker'da:
Redis::connection('default')->ping();
exit
```
