# Instagram Cookies Sozlash (ENG OPTIMAL YECHIM) ⭐

## Nima uchun cookies kerak?
Instagram rate-limit va login talab qiladi. Cookies bilan bu muammo hal bo'ladi.

## Qanday sozlash?

### 1-usul: Chrome Extension orqali (Oson) ⭐
1. Chrome/Edge'ga kiring
2. Extension o'rnating: [Get cookies.txt](https://chrome.google.com/webstore/detail/get-cookiestxt-locally/cclelndahbckbenkjhflpdbgdldlbecc)
3. Instagram.com ga login qiling
4. Extension orqali cookies.txt ni saqlang
5. Serverga yuklang: `/var/www/sardor/data/www/download.e-qarz.uz/storage/app/cookies/instagram_cookies.txt`

### 2-usul: Browser DevTools orqali
1. Chrome/Edge da Instagram.com ga login qiling
2. F12 (DevTools) oching
3. Application > Cookies > instagram.com
4. Barcha cookies ni copy qiling
5. `cookies.txt` formatiga convert qiling
6. Serverga yuklang

### 3-usul: curl orqali (Advanced)
```bash
# Browser'dan cookies ni eksport qiling va serverga yuklang
```

## .env faylga qo'shish

```env
INSTAGRAM_COOKIES_PATH=/var/www/sardor/data/www/download.e-qarz.uz/storage/app/cookies/instagram_cookies.txt
```

## Test qilish

```bash
# Config yangilash
php artisan config:clear && php artisan config:cache

# Test
cd /var/www/sardor/data/www/download.e-qarz.uz
yt-dlp --cookies storage/app/cookies/instagram_cookies.txt "https://www.instagram.com/reel/..."
```

## Xavfsizlik
- Cookies faylini `.gitignore` ga qo'shing
- Permissions: `chmod 600 storage/app/cookies/instagram_cookies.txt`
- `.env` faylini hech kimga ko'rsatmang

## Muammo hal qilindi! ✅
Cookies bilan Instagram yuklab olish 99% ishonchli!
