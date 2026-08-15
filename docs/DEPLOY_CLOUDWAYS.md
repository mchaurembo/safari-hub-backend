# Safari Hub 360 — Deploy: GitHub → Cloudways

Mwongozo kamili wa kuweka na kusasisha production kwenye Cloudways kupitia GitHub.

---

## 1. Muundo wa mradi

Lokal (monorepo folder):

```
safari-hub/
├── backend/     ← Laravel API + SPA (public/)
├── frontend/    ← React (Vite) — source
├── mobile/      ← Expo (haitumiki kwenye Cloudways)
└── scripts/prepare-for-cloudways.sh
```

### GitHub (repo mbili)

| Repo | URL | Matumizi |
|------|-----|----------|
| Backend | `git@github.com:mchaurembo/safari-hub-backend.git` | **Cloudways production** |
| Frontend | `git@github.com:mchaurembo/safari-hub-frontend.git` | Development; build inanakiliwa → backend |

### Cloudways (app **moja** tu)

| Kipengele | Thamani |
|-----------|---------|
| Jina la UI | `Safari Hub 360 – Production` |
| Folder / DB | `equsfzucxz` |
| Domain | `https://www.safarihub.space` |
| Path SSH | `~/applications/equsfzucxz/public_html` |
| Document root | `public/` (Laravel) |
| Git repo | `safari-hub-backend` branch `main` |

**Usifungue app ya pili ya “frontend”.** React inaishi ndani ya `backend/public/` baada ya build.

```
Lokal: badilisha code
   → prepare-for-cloudways.sh (jenga React → backend/public)
   → push frontend (source) + backend (Laravel + assets)
   → Cloudways: Start Deployment (pull backend)
   → SSH: composer / migrate / cache (inapohitajika)
```

---

## 2. Setup ya kwanza (mara moja)

### 2.1 Lokal — clone / path

```bash
cd ~/Documents/APPS/safari-hub   # au path yako
```

Backend na frontend ni git repos tofauti **ndani** ya folders:

```bash
# Backend
cd backend
git remote -v
# origin → git@github.com:mchaurembo/safari-hub-backend.git

# Frontend
cd ../frontend
git remote -v
# origin → git@github.com:mchaurembo/safari-hub-frontend.git
```

### 2.2 Cloudways — Application

1. Unda **PHP** app (Laravel).
2. **Domain Management** → ongeza `safarihub.space` + `www.safarihub.space`.
3. **SSL Certificate** → weka SSL kwa domains zote.
4. **Application Settings** → document root = `public`.
5. **Deployment via Git**:
   - Generate SSH key → ongeza kwenye GitHub (`safari-hub-backend` → Settings → Deploy keys).
   - Repo: `git@github.com:mchaurembo/safari-hub-backend.git`
   - Branch: `main`
   - **Save** → **Start Deployment**

### 2.3 SSH — .env ya kwanza

```bash
ssh master_XXXXX@143.198.16.65
cd ~/applications/equsfzucxz/public_html
```

Andaa `.env` (chukua DB kutoka **Access Details**):

```bash
# Kama .env haipo:
cp .env.cloudways.example .env   # au unda .env mpya
nano .env
```

Viwanja muhimu:

```env
APP_NAME="Safari Hub 360"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://www.safarihub.space

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=equsfzucxz
DB_USERNAME=equsfzucxz
DB_PASSWORD=********

LOGIN_DEBUG=false
```

Kisha:

```bash
# Composer 2 (Cloudways inaweza kuwa na composer 1 — tumia composer.phar)
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php --install-dir=. --filename=composer.phar
php composer.phar install --no-dev --optimize-autoloader

php artisan key:generate
php artisan migrate --force
php artisan db:seed --force          # mara ya kwanza / staging pekee
php artisan storage:link
php artisan config:cache
php artisan route:cache
chmod -R 775 storage bootstrap/cache
```

> **PHP:** server ni **8.2**. `composer.lock` lazima ilingane (platform 8.2).  
> **Seeder:** password ni `password` (plain) — User model ina cast `hashed`. Usitumie `Hash::make` kwenye seeder.

### 2.4 Akaunti za majaribio (baada ya seed)

Kawaida:

| Email | Password (default seed) |
|-------|-------------------------|
| `admin@safarihub360.com` | `password` |
| `owner@safarihub360.com` | `password` |
| `driver@safarihub360.com` | `password` |
| `customer@safarihub360.com` | `password` |

Badilisha password production:

```bash
php artisan tinker --execute="
\Illuminate\Support\Facades\DB::table('users')
  ->where('email','admin@safarihub360.com')
  ->update(['password' => bcrypt('YOUR_STRONG_PASSWORD')]);
"
```

---

## 3. Deploy ya kila siku (mtiririko kamili)

### Hatua A — Badilisha code lokal

Fanya mabadiliko kwenye `frontend/` na/au `backend/`.  
**Parity:** feature mpya → sasisha **frontend + mobile** (mobile si Cloudways).

### Hatua B — Jenga React ndani ya Laravel `public/`

**Lazima** kila unapobadilisha UI ya web:

```bash
cd ~/Documents/APPS/safari-hub
chmod +x scripts/prepare-for-cloudways.sh
./scripts/prepare-for-cloudways.sh
```

Hii:

1. `npm install` / build frontend  
2. `VITE_API_URL=/api` (same-origin)  
3. Nakili `frontend/dist/*` → `backend/public/` (`index.html` + `assets/`)

Thibitisha:

```bash
grep -o 'index-[^"]*\.js' backend/public/index.html
# mfano: index-fhuv5ooO.js
```

### Hatua C — Commit + push **Frontend** (source)

```bash
cd ~/Documents/APPS/safari-hub/frontend
git status
git add -A
git commit -m "Describe frontend change"
git push origin main
```

### Hatua D — Commit + push **Backend** (API + SPA assets)

```bash
cd ~/Documents/APPS/safari-hub/backend
git status
git add -A
# Hakikisha public/index.html + public/assets/ zipo kwenye commit
git commit -m "Describe backend / SPA build change"
git push origin main
```

### Hatua E — Cloudways Deploy

1. Cloudways → **Safari Hub 360 – Production** (`equsfzucxz`)  
2. **Deployment via Git** → **Start Deployment**  
3. Subiri iishe (Cloudways **haiachi** folder `.git` — usitarajie `git pull` kwenye SSH)

### Hatua F — Baada ya deploy (SSH, inapohitajika)

```bash
cd ~/applications/equsfzucxz/public_html

# Dependencies mpya?
php composer.phar install --no-dev --optimize-autoloader
# au: php /usr/local/bin/composer … kama Composer 2 ipo

# Migrations mpya?
php artisan migrate --force

# Cache
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Hatua G — Thibitisha production

```bash
# SPA
curl -sS https://www.safarihub.space/ | grep -o 'index-[^"]*\.js'

# API
curl -sS https://www.safarihub.space/api/routes | head -c 200
echo

# Login (badilisha password)
curl -sS -X POST https://www.safarihub.space/api/login \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"identifier":"admin@safarihub360.com","password":"YOUR_PASSWORD"}'
```

Browser: Incognito → https://www.safarihub.space/login

---

## 4. Amri za haraka (cheat sheet)

### Deploy UI + API (kamili)

```bash
cd ~/Documents/APPS/safari-hub
./scripts/prepare-for-cloudways.sh

cd frontend && git add -A && git commit -m "Frontend update" && git push origin main
cd ../backend && git add -A && git commit -m "Backend + SPA build" && git push origin main
```

Kisha Cloudways → **Start Deployment**.

### Backend pekee (hakuna mabadiliko ya React)

```bash
cd ~/Documents/APPS/safari-hub/backend
git add -A
git commit -m "Backend API change"
git push origin main
```

Cloudways → **Start Deployment** → SSH migrate/cache kama inahitajika.

### Frontend source pekee (bila ku-deploy Cloudways bado)

```bash
cd ~/Documents/APPS/safari-hub/frontend
git add -A
git commit -m "Frontend source"
git push origin main
```

> Cloudways **haitaona** hii hadi uendeshe `prepare-for-cloudways.sh` na u-push backend.

### Sasisha SPA kwa mkono (kama Deploy haivuti assets)

```bash
cd ~/applications/equsfzucxz/public_html

TMP=/tmp/sh360-spa
rm -rf "$TMP" && mkdir -p "$TMP"
curl -fsSL -o /tmp/sh360-backend.tar.gz \
  https://github.com/mchaurembo/safari-hub-backend/archive/refs/heads/main.tar.gz
tar -xzf /tmp/sh360-backend.tar.gz -C "$TMP" --strip-components=1

cp "$TMP/public/index.html" public/index.html
cp -R "$TMP/public/assets/." public/assets/ || true

ls public/assets/index-*.js
grep -o 'index-[^"]*\.js' public/index.html
```

---

## 5. Composer kwenye Cloudways

Server inaweza kuwa na Composer **1.x** (shinda Symfony 8 / Laravel 12). Tumia Composer 2:

```bash
cd ~/applications/equsfzucxz/public_html
php composer.phar install --no-dev --optimize-autoloader
php composer.phar update --no-dev   # kwa tahadhari tu
```

PHP version: **8.2.x**. Epuka packages zinazohitaji PHP 8.4 kwenye `composer.lock`.

---

## 6. Migrations & database

```bash
# Normal (production)
php artisan migrate --force

# Staging / reset kamili (FUTA DATA YOTE — usitumie production bila backup)
php artisan migrate:fresh --force --seed
```

Baada ya `migrate:fresh --seed`, hakiki password (cast `hashed`):

```bash
php artisan tinker --execute="
echo Hash::check('password', \App\Models\User::where('email','admin@safarihub360.com')->first()?->getRawOriginal('password')) ? 'OK' : 'FAIL';
"
```

---

## 7. Domain, SSL, Cloudflare

1. Domains zote mbili: `safarihub.space` + `www.safarihub.space`  
2. SSL kwa zote  
3. Cloudflare SSL mode: **Full (strict)**  
4. Jaribu kila mara `https://www.safarihub.space` (apex inaweza kuwa na SSL tofauti)

Usifute app ya Cloudways yenye DB `equsfzucxz`. App tupu nyingine (`gvsctvjnta` n.k.) inaweza kufutwa **baada** ya kuthibitisha domain iko kwenye production pekee.

---

## 8. Checklist kabla ya kusema “imeisha”

- [ ] `prepare-for-cloudways.sh` imeendeshwa (kama UI imebadilika)
- [ ] Frontend pushed → `safari-hub-frontend`
- [ ] Backend pushed → `safari-hub-backend` (pamoja na `public/assets`)
- [ ] Cloudways **Start Deployment** imefanikiwa
- [ ] `composer install` (kama `composer.lock` imebadilika)
- [ ] `php artisan migrate --force`
- [ ] `config:cache` / `route:cache`
- [ ] `/` inaonyesha React; `/api/...` inarudisha JSON
- [ ] Login inafanya kazi (Incognito)

---

## 9. Matatizo ya kawaida

| Tatizo | Sababu | Suluhisho |
|--------|--------|-----------|
| Browser bado UI ya zamani | Deploy/assets/cache | Start Deployment; `ls public/assets/index-*.js`; Incognito |
| `Invalid credentials` | Password mbaya / autofill / double-hash seeder | Curl login; badilisha password kwa `bcrypt`; Incognito |
| `500` / missing tables | Migrations | `php artisan migrate --force` |
| Composer fail PHP 8.4 | Lock isiyo sahihi | Regenera lock kwa PHP 8.2; push |
| `git pull` fail kwenye SSH | Cloudways haina `.git` | Tumia **Deployment via Git** UI |
| API → `localhost:8000` | Build bila `/api` | `./scripts/prepare-for-cloudways.sh` tena + push |
| Migration order fail | Soft-delete/OTP kabla ya create | Hakikisha timestamps za migration ziko sawa kwenye repo |
| Connection reset baada ya kufuta app | Domain/SSL / umefuta app sahihi | Thibitisha `equsfzucxz` bado ipo; SSL apex+www |
| `405` kwenye `/api/login` kwenye browser | GET badala ya POST | Login ni POST tu; tumia fomu au curl |

### Test login (SSH au lokal)

```bash
curl -sS -X POST https://www.safarihub.space/api/login \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"identifier":"admin@safarihub360.com","password":"YOUR_PASSWORD"}'
```

---

## 10. Mobile (si Cloudways)

```bash
cd ~/Documents/APPS/safari-hub/mobile
# Weka API URL ya production kwenye env ya Expo
# mfano: EXPO_PUBLIC_API_URL=https://www.safarihub.space/api
```

Deploy ya mobile ni Expo / store — si Git → Cloudways.

---

## 11. Muhtasari wa amri (copy-paste)

```bash
# === LOKAL: build + push zote ===
cd ~/Documents/APPS/safari-hub
./scripts/prepare-for-cloudways.sh

cd frontend
git add -A && git commit -m "Update frontend" && git push origin main

cd ../backend
git add -A && git commit -m "Update backend and SPA build" && git push origin main

# === CLOUDWAYS UI ===
# Application → Deployment via Git → Start Deployment

# === SSH (baada ya deploy) ===
cd ~/applications/equsfzucxz/public_html
php composer.phar install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:clear && php artisan config:cache && php artisan route:cache
```

---

## 12. Marejeleo ya faili

| Faili | Madhumuni |
|-------|-----------|
| `scripts/prepare-for-cloudways.sh` | Build React → `backend/public/` |
| `backend/.env.cloudways.example` | Template ya `.env` production |
| `backend/routes/web.php` | SPA fallback (React) |
| `frontend/.env.production.example` | `VITE_API_URL=/api` |
| `docs/DEPLOY_CLOUDWAYS.md` | Hati hii |

Maswali / mabadiliko ya domain: sasisha `APP_URL` na SSL, kisha `php artisan config:cache`.
