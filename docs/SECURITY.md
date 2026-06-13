# Security Hardening

Recommended:

- HTTPS only
- keep Ubuntu updated
- use unprivileged CT where possible
- restrict SSH
- use strong admin passwords
- keep `/database`, `/storage`, `/docs`, `/scripts` blocked from web access
- back up regularly
- store backups securely
- use Cloudflare Tunnel or a reverse proxy if preferred
- review audit logs
- keep at least two trusted admins

## File permissions

Recommended:

```bash
chown -R www-data:www-data /var/www/html/CADARS-Members-System
find /var/www/html/CADARS-Members-System -type d -exec chmod 750 {} \;
find /var/www/html/CADARS-Members-System -type f -exec chmod 640 {} \;
```

## Uploads

Private uploads are stored in:

```text
/storage/private/
```

They should never be served directly by Nginx.
