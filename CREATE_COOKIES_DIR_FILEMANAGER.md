# Cookies Papkasini Yaratish - File Manager Orqali 📁

## 1. File Manager Orqali Papka Yaratish

### Qadam 1: File Manager'da papkaga kiring
1. File Manager'ni oching (ISPManager, cPanel, yoki boshqa)
2. Quyidagi papkaga kiring:
   ```
   /www/download.e-qarz.uz/storage/app/
   ```
   Yoki
   ```
   /var/www/sardor/data/www/download.e-qarz.uz/storage/app/
   ```

### Qadam 2: Yangi papka yarating
1. **"Создать"** (yoki "Create" / "New Folder") tugmasini bosing
2. Papka nomi: **`cookies`** (kichik harflarda)
3. **"Создать"** tugmasini bosing

✅ **`cookies`** papkasi yaratildi!

### Qadam 3: Tekshirish
Papka ichida quyidagilar bo'lishi kerak:
- `private` (papka)
- `public` (papka)
- `temp` (papka)
- `temp_videos` (papka)
- **`cookies`** (papka) ← Yangi yaratilgan
- `.gitignore` (fayl)

## 2. SSH Orqali Papka Yaratish (Alternativ)

Agar SSH kirish mumkin bo'lsa:

```bash
# SSH orqali serverni ulang
ssh sardor@vmi2764405

# Cookies papkasini yaratish
mkdir -p /var/www/sardor/data/www/download.e-qarz.uz/storage/app/cookies
chmod 755 /var/www/sardor/data/www/download.e-qarz.uz/storage/app/cookies

# Tekshirish
ls -la /var/www/sardor/data/www/download.e-qarz.uz/storage/app/
```

✅ Papka yaratildi!

## 3. Script Orqali Papka Yaratish (Eng Oson)

```bash
# Script'ni ishga tushiring
chmod +x CREATE_COOKIES_DIR.sh
./CREATE_COOKIES_DIR.sh
```

✅ Papka avtomatik yaratildi!

## 4. Instagram Cookies Faylini Yuklash

### Qadam 4: Chrome'da cookies export qiling
1. Chrome'da **www.instagram.com** ga login qiling
2. **F12** bosing
3. **Extensions** icon'iga bosing
4. **"Get cookies.txt"** extension'ni oching
5. **"Export Format: Netscape"** tanlanganligini tekshiring ✅
6. **"Export All Cookies"** tugmasini bosing
7. `cookies.txt` fayli yuklab olinadi

### Qadam 5: Faylni qayta nomlang
1. Yuklab olingan `cookies.txt` faylni toping
2. Faylni qayta nomlang: **`instagram_cookies.txt`**

### Qadam 6: Serverga yuklang
1. File Manager'da **`cookies`** papkasiga kiring:
   ```
   /www/download.e-qarz.uz/storage/app/cookies/
   ```
2. **"Загрузить"** (yoki "Upload" / "Yuklash") tugmasini bosing
3. **`instagram_cookies.txt`** faylini tanlang va yuklang

✅ Fayl yuklandi!

### Qadam 7: Permissions o'rnatish
File Manager'da:
1. **`instagram_cookies.txt`** faylini toping
2. O'ng tugma > **"Имя и атрибуты"** (yoki "Permissions")
3. Permissions: **`600`** (faqat owner o'qiydi va yozadi)
4. **"Сохранить"** (Save) tugmasini bosing

Yoki SSH orqali:
```bash
chmod 600 /var/www/sardor/data/www/download.e-qarz.uz/storage/app/cookies/instagram_cookies.txt
```

✅ Permissions o'rnatildi!

## 5. .env Faylga Qo'shish

File Manager orqali yoki SSH orqali `.env` faylga qo'shing:

```bash
INSTAGRAM_COOKIES_PATH=/var/www/sardor/data/www/download.e-qarz.uz/storage/app/cookies/instagram_cookies.txt
```

Yoki SSH orqali:
```bash
cd /var/www/sardor/data/www/download.e-qarz.uz
echo "" >> .env
echo "# Instagram Cookies" >> .env
echo "INSTAGRAM_COOKIES_PATH=/var/www/sardor/data/www/download.e-qarz.uz/storage/app/cookies/instagram_cookies.txt" >> .env
```

✅ .env faylga qo'shildi!

## 6. Config Yangilash va Test

```bash
# Config yangilash
php artisan config:clear
php artisan config:cache

# Workerlarni qayta ishga tushirish
pkill -9 -f "artisan queue:work" 2>/dev/null
sleep 2

cd /var/www/sardor/data/www/download.e-qarz.uz
nohup php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
nohup php artisan queue:work redis --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &
```

✅ Bot tayyor!

## 📋 Tezkor Buyruqlar (SSH orqali)

```bash
# 1. Cookies papkasini yaratish
mkdir -p /var/www/sardor/data/www/download.e-qarz.uz/storage/app/cookies
chmod 755 /var/www/sardor/data/www/download.e-qarz.uz/storage/app/cookies

# 2. .gitignore ga qo'shish
cd /var/www/sardor/data/www/download.e-qarz.uz
grep -q "storage/app/cookies" .gitignore || echo "/storage/app/cookies" >> .gitignore

# 3. Tekshirish
ls -la storage/app/cookies/
```

## ✅ Tugadi!

Endi:
1. ✅ `cookies` papkasi yaratildi
2. 📤 Chrome'dan `instagram_cookies.txt` ni export qiling
3. 📤 Serverga yuklang
4. 🎉 Bot test qiling!
