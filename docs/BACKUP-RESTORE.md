# Backup and Restore

Back up:

```text
/database/app.sqlite
/storage/app_config.php
/storage/installed.lock
/storage/private/
```

```bash
APP=/var/www/html/CADARS-Members-System
tar -czf /root/cadars-members-backups/cadars-members-$(date +%F-%H%M).tar.gz "$APP/database" "$APP/storage"
```
