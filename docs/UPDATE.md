# Updating from GitHub

Use the included updater script.

```bash
cd /var/www/html/CADARS-Members-System
chmod +x scripts/update-from-github.sh
scripts/update-from-github.sh
```

The updater:

- pulls the latest GitHub code
- backs up the live system
- preserves `/database`
- preserves `/storage`
- updates app files
- fixes ownership/permissions
- reloads Nginx/PHP-FPM

## Manual update

```bash
APP=/var/www/html/CADARS-Members-System
SRC=/opt/CADARS-Members-System-source

git -C "$SRC" fetch --all --prune
git -C "$SRC" reset --hard origin/main

rsync -av --delete \
  --exclude='database/' \
  --exclude='storage/' \
  "$SRC/" "$APP/"

chown -R www-data:www-data "$APP"
nginx -t
systemctl reload nginx
systemctl reload php8.1-fpm
```

Never overwrite live `database/` or `storage/`.
