# Instagram Cookies Export - Qadam-baqadam Qo'llanma

## 🎯 Maqsad
Instagram cookies'ni browser'dan export qilib, bot uchun sozlash.

## 📱 Method 1: EditThisCookie Extension (Chrome/Edge)

### Qadam 1: Extension'ni ochish
1. Chrome/Edge'da `https://www.instagram.com` ga kiring
2. Instagram account'ingizga **login qiling** (muhim!)
3. Browser toolbar'dagi **EditThisCookie** icon'iga bosing
   - Agar icon ko'rinmasa: `chrome://extensions/` → EditThisCookie → "Details" → "Extension options"

### Qadam 2: Cookies Export
1. EditThisCookie oynasida **"Export"** tugmasini bosing
2. Format tanlang: **"Netscape HTTP Cookie File"**
3. **"Save"** tugmasini bosing
4. Fayl nomi: `instagram_cookies.txt`

### Qadam 3: Serverga yuklash
1. Faylni serverga yuklang:
   ```
   /var/www/sardor/data/www/download.e-qarz.uz/storage/app/cookies/instagram_cookies.txt
   ```

## 📱 Method 2: Cookie-Editor Extension (Firefox/Chrome)

### Qadam 1: Extension'ni ochish
1. Browser'da `https://www.instagram.com` ga kiring
2. Instagram account'ingizga **login qiling**
3. **Cookie-Editor** extension icon'iga bosing

### Qadam 2: Cookies Export
1. Cookie-Editor oynasida **"Export"** tugmasini bosing
2. Format tanlang: **"Netscape"**
3. **"Save"** tugmasini bosing
4. Fayl nomi: `instagram_cookies.txt`

### Qadam 3: Serverga yuklash
Faylni serverga yuklang

## 📱 Method 3: Browser DevTools (Extension yo'q bo'lsa)

### Qadam 1: DevTools ochish
1. Chrome/Edge'da `https://www.instagram.com` ga kiring
2. Instagram account'ingizga **login qiling**
3. **F12** yoki **Right-click → "Inspect"**
4. **"Application"** tab'iga o'ting

### Qadam 2: Cookies ko'rish
1. Chap panelda: **Storage → Cookies → `https://www.instagram.com`**
2. Barcha cookies ko'rinadi

### Qadam 3: Muhim cookies'ni topish
Quyidagi cookies'ni toping va **Value**'larini copy qiling:
- **sessionid** - ENG MUHIM! (uzun string)
- **csrftoken** - Muhim (qisqa string)
- **ds_user_id** - Foydali (raqam)

### Qadam 4: cookies.txt yaratish
Server'da fayl yarating:

```bash
nano /var/www/sardor/data/www/download.e-qarz.uz/storage/app/cookies/instagram_cookies.txt
```

Quyidagi formatda yozing (YOUR_* o'rniga real values):

```
# Netscape HTTP Cookie File
.instagram.com	TRUE	/	FALSE	1735689600	sessionid	YOUR_SESSIONID_VALUE_HERE
.instagram.com	TRUE	/	FALSE	1735689600	csrftoken	YOUR_CSRFTOKEN_VALUE_HERE
.instagram.com	TRUE	/	FALSE	1735689600	ds_user_id	YOUR_USER_ID_HERE
```

**Format tushuntirish:**
```
domain	flag	path	secure	expiration	name	value
```

- `domain`: `.instagram.com`
- `flag`: `TRUE`
- `path`: `/`
- `secure`: `FALSE` (yoki `TRUE` agar HTTPS bo'lsa)
- `expiration`: Unix timestamp (masalan: `1735689600` - 2025 yil uchun)
- `name`: Cookie nomi (`sessionid`, `csrftoken`, va h.k.)
- `value`: Cookie value (sizning real values'ingiz)

**Expiration hisoblash:**
```bash
# 1 yil uchun (365 kun)
date -d "+365 days" +%s
```

Yoki oddiy: `1735689600` (2025 yil uchun) ishlatishingiz mumkin.

## ✅ Tekshirish

Cookies faylini yaratgandan keyin:

```bash
cd ~/www/download.e-qarz.uz
chmod +x CHECK_INSTAGRAM_COOKIES.sh
./CHECK_INSTAGRAM_COOKIES.sh
```

## 📝 .env faylida sozlash

```bash
INSTAGRAM_COOKIES_PATH=/var/www/sardor/data/www/download.e-qarz.uz/storage/app/cookies/instagram_cookies.txt
```

Keyin:
```bash
php artisan config:clear && php artisan config:cache
```

## ⚠️ MUHIM Eslatmalar

1. **Cookies yangi bo'lishi kerak** (30 kundan eski bo'lmasligi kerak)
2. **sessionid cookie mavjud bo'lishi kerak** (eng muhim!)
3. **Format Netscape bo'lishi kerak**
4. **Instagram.com ga login qilingan bo'lishi kerak** (browser'da)

## 🔍 Tekshirish

Cookies faylini yaratgandan keyin test qiling:

```bash
./CHECK_INSTAGRAM_COOKIES.sh
```

Agar hamma narsa to'g'ri bo'lsa, bot Instagram content'ni yuklab olishi kerak!
