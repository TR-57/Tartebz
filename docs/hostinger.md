# Running the shop on Hostinger

The shop needs a Hostinger plan with **SSH, Git and Composer** (Business or Cloud). Everything else is in the repository.

## Folders on the server
```
~/domains/<domain>/tartebz-app/    this repository: code, backend/.env   (not web-visible)
~/domains/<domain>/public_html/    pages, /admin, /uploads, /img, api.php, .htaccess   (written by deploy.sh)
```

## First install
1. hPanel → **Advanced → PHP Configuration**: choose **PHP 8.3**. In *PHP extensions* make sure these are ticked:
   `pdo_mysql, mbstring, openssl, curl, fileinfo, gd, tokenizer, ctype, xml, bcmath`.
   In *PHP options*: `memory_limit 256M`, `upload_max_filesize 16M`, `post_max_size 16M`.
2. hPanel → **Databases**: create a MySQL database and user; note the names and password.
3. hPanel → **SSL**: install the free certificate for the domain (the site must run on https).
4. SSH in, then:
   ```bash
   cd ~/domains/<domain>
   git clone <repository-url> tartebz-app
   cd tartebz-app/backend && cp .env.example .env
   ```
   In `backend/.env` fill: `APP_URL`, `STORE_URL` (https://your-domain), `ADMIN_URL`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`,
   `UPLOAD_DIR=…/public_html/uploads`, `THUMB_DIR=…/public_html/img`. Then `php artisan key:generate` (once – never change it later:
   it locks the saved Tap and SMSA keys).
5. `bash ~/domains/<domain>/tartebz-app/deploy/hostinger/deploy.sh`
6. `cd backend && php artisan store:create-admin you@example.com "Your Name" owner` – the first owner account (it asks for the password, 10+ characters).
7. hPanel → **Advanced → Cron Jobs** → *Custom*, every minute (`* * * * *`):
   ```
   /usr/bin/php /home/<user>/domains/<domain>/tartebz-app/backend/artisan schedule:run >> /dev/null 2>&1
   ```
   deploy.sh prints the exact line for your server. Without cron, missed card payments and refunds are not re-checked,
   expired reservations are not cleared and failed emails are not retried. The admin dashboard warns when cron has stopped.

## Every update
```bash
bash ~/domains/<domain>/tartebz-app/deploy/hostinger/deploy.sh
```
It pulls the code, updates the database, rebuilds the public files and finishes with **`php artisan store:doctor`**.

## store:doctor
Run it any time: `cd tartebz-app/backend && php artisan store:doctor`. Every line is OK, WARN or FAIL, with the fix:

| Check | Fix |
|---|---|
| PHP 8.3, extensions, WebP, memory, upload size | hPanel → PHP Configuration |
| APP_KEY, debug off, STORE_URL is https | `backend/.env` |
| Database connection, updates applied | `.env` database lines / `php artisan migrate --force` |
| Folders writable (storage, labels, uploads, img) | File Manager → Permissions 755 |
| Free disk space | delete old backups |
| api.php and .htaccess in public_html | run deploy.sh |
| Scheduled tasks ran in the last 15 minutes | the cron job above |
| Email, Tap, SMSA | Admin → Settings |

## Good to know
- Hostinger's own CDN must be **off** if you use Cloudflare (see `cloudflare.md`).
- Photos in `public_html/uploads` are never touched by deploys. Back them up with hPanel → Files → Backups.
- Roll back a bad update: deploy.sh prints the previous version; run `git checkout <it> && bash deploy/hostinger/deploy.sh`.
