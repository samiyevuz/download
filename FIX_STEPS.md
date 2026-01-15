# Cache Muammosini Hal Qilish - Qadamlari

## Muammo
Queue workerlar ishlamayapti, chunki `cache` jadvali mavjud emas.

## Yechim (3 ta oddiy qadam)

### 1-qadam: .env faylini o'zgartirish

```bash
nano .env
```

Faylda quyidagi qatorni toping:
```
CACHE_STORE=database
```

Uni quyidagiga o'zgartiring:
```
CACHE_STORE=redis
```

Saqlash: `Ctrl+X`, keyin `Y`, keyin `Enter`

### 2-qadam: Config cache'ni tozalash

```bash
php artisan config:clear
php artisan config:cache
```

### 3-qadam: Queue workerlarni qayta ishga tushirish

```bash
php artisan queue:restart
```

Keyin workerlarni qayta ishga tushiring:

```bash
php artisan queue:work redis-downloads --queue=downloads --tries=2 --timeout=60
```

Yoki ikkinchi terminalda:

```bash
php artisan queue:work redis-telegram --queue=telegram --tries=3 --timeout=10
```

## Tekshirish

Agar hammasi to'g'ri bo'lsa, siz quyidagilarni ko'rasiz:
```
INFO  Processing jobs from the [downloads] queue.
```

Xato bo'lmasligi kerak!
