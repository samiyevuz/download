# Cookies Sozlash - Qadam Baqadam Ko'rsatma 📖

## 1. Chrome Extension O'rnatish

### Qadam 1: Extension'ni o'rnatish
1. Chrome browser'ni oching
2. Quyidagi linkga kiring:
   ```
   https://chrome.google.com/webstore/detail/get-cookiestxt-locally/cclelndahbckbenkjhflpdbgdldlbecc
   ```
3. **"Add to Chrome"** tugmasini bosing
4. **"Add extension"** ni tasdiqlang

✅ Extension o'rnatildi!

## 2. Instagram Cookies Export Qilish

### Qadam 2: Instagram'ga login qiling
1. Chrome'da **www.instagram.com** ga kiring
2. Instagram akkauntingizga **login qiling**
3. Login bo'lgan holatda qolishingiz kerak

✅ Instagram'ga login qildingiz!

### Qadam 3: Cookies'ni export qiling
1. Chrome'da **F12** bosing (yoki o'ng tugma > "Inspect")
2. **Extensions** icon'iga bosing (browser yuqorisida)
3. **"Get cookies.txt"** extension'ni oching
4. **"Export Format: Netscape"** tanlanganligini tekshiring ✅
5. **"Export All Cookies"** tugmasini bosing
6. `cookies.txt` fayli yuklab olinadi

✅ Cookies export qilindi!

## 3. Serverga Yuklash

### Qadam 4: Faylni qayta nomlash
1. Yuklab olingan `cookies.txt` faylni toping
2. Faylni qayta nomlang: **`instagram_cookies.txt`**

### Qadam 5: Serverga yuklash (FTP/SSH)

**SSH orqali (eng oson):**
```bash
# 1. SSH orqali serverni ulang
ssh sardor@vmi2764405

# 2. Cookies papkasini yarating
mkdir -p /var/www/sardor/data/www/download.e-qarz.uz/storage/app/cookies

# 3. FTP yoki SCP orqali yuklang
# Masalan: WinSCP, FileZilla yoki scp komandasi
```

**Yoki WinSCP/FileZilla orqali:**
1. WinSCP/FileZilla'ni oching
2. Serverni ulang
3. Quyidagi papkaga kiring:
   ```
   /var/www/sardor/data/www/download.e-qarz.uz/storage/app/cookies/
   ```
4. `instagram_cookies.txt` faylini yuklang

✅ Serverga yuklandi!

### Qadam 6: Permissions o'rnatish
```bash
chmod 600 /var/www/sardor/data/www/download.e-qarz.uz/storage/app/cookies/instagram_cookies.txt
```

✅ Permissions o'rnatildi!

## 4. .env Faylga Qo'shish

### Qadam 7: .env faylni yangilang
```bash
# Serverda .env faylga qo'shing:
INSTAGRAM_COOKIES_PATH=/var/www/sardor/data/www/download.e-qarz.uz/storage/app/cookies/instagram_cookies.txt
```

Yoki qo'lda:
```bash
cd /var/www/sardor/data/www/download.e-qarz.uz
echo "" >> .env
echo "# Instagram Cookies" >> .env
echo "INSTAGRAM_COOKIES_PATH=/var/www/sardor/data/www/download.e-qarz.uz/storage/app/cookies/instagram_cookies.txt" >> .env
```

✅ .env faylga qo'shildi!

## 5. Config Yangilash

### Qadam 8: Config va workerlarni qayta ishga tushiring
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

# Tekshirish
sleep 3
ps aux | grep "artisan queue:work redis" | grep -v grep | grep -v "datacollector"
```

✅ Config yangilandi va workerlarni ishga tushdi!

## 6. Test Qilish

### Qadam 9: Bot'ni test qiling
1. Telegram bot'ga Instagram link yuboring
2. **"⏳ Downloading, please wait..."** kelishi kerak
3. Bir necha soniyadan keyin video/rasm yuborilishi kerak ✅

✅ Bot ishlayapti!

## 📋 Tezkor Buyruqlar (Hammasi Bir Martada)

```bash
# 1. Cookies papkasini yaratish
mkdir -p /var/www/sardor/data/www/download.e-qarz.uz/storage/app/cookies

# 2. .env faylga qo'shish (agar yo'q bo'lsa)
grep -q "INSTAGRAM_COOKIES_PATH" .env || echo "INSTAGRAM_COOKIES_PATH=/var/www/sardor/data/www/download.e-qarz.uz/storage/app/cookies/instagram_cookies.txt" >> .env

# 3. Permissions (agar fayl mavjud bo'lsa)
[ -f storage/app/cookies/instagram_cookies.txt ] && chmod 600 storage/app/cookies/instagram_cookies.txt

# 4. Config yangilash
php artisan config:clear && php artisan config:cache

# 5. Workerlarni qayta ishga tushirish
pkill -9 -f "artisan queue:work" 2>/dev/null
sleep 2
cd /var/www/sardor/data/www/download.e-qarz.uz
nohup php artisan queue:work redis --queue=downloads --tries=2 --timeout=60 > storage/logs/queue-downloads.log 2>&1 &
nohup php artisan queue:work redis --queue=telegram --tries=3 --timeout=10 > storage/logs/queue-telegram.log 2>&1 &

# 6. Tekshirish
sleep 3
ps aux | grep "artisan queue:work redis" | grep -v grep | grep -v "datacollector"
```

## ⚠️ Muhim Eslatmalar

1. **Cookies faylini `.gitignore` ga qo'shing:**
   ```bash
   echo "/storage/app/cookies" >> .gitignore
   ```

2. **Xavfsizlik:**
   - Cookies faylini hech kimga ko'rsatmang
   - `.env` faylini hech kimga ko'rsatmang
   - Permissions: `600` (faqat owner o'qiydi va yozadi)

3. **Cookies'ni yangilash:**
   - Agar Instagram'da logout/change password bo'lsa
   - Cookies'ni qayta export qiling va serverga yuklang

## ✅ Tugadi!

Endi bot Instagram'dan muammosiz yuklab olishi kerak! 🎉
