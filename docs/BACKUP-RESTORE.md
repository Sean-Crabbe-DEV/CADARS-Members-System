# Backup and Restore

## Important files

Back up:

```text
/database/app.sqlite
/storage/app_config.php
/storage/installed.lock
/storage/private/
```

## Backup command

```bash
APP=/var/www/html/CADARS-Members-System
mkdir -p /root/cadars-members-backups

tar -czf /root/cadars-members-backups/cadars-members-$(date +%F-%H%M).tar.gz \
  "$APP/database" \
  "$APP/storage"
```

## Restore command

```bash
tar -xzf /root/cadars-members-backups/backup-file.tar.gz -C /
chown -R www-data:www-data /var/www/html/CADARS-Members-System
systemctl reload nginx
systemctl restart php8.1-fpm
```

## Encryption warning

If `storage/app_config.php` is lost, encrypted data may become unreadable.

Always back it up securely.
