# CADARS Members System

**V2 Club Management and Information System**  
Created and maintained by **Sean Crabbe** — <sean@defenderonfrequency.uk>

A lightweight, self-hosted, web-based membership and club management system for a ham radio society.

## Main features

- First-time installer with admin setup
- Multi-role users and admin role management
- Member database and self-service profiles
- GDPR/data consent fields
- Internal member directory opt-in
- Programme/events with attendance register
- Guest attendance and attendance statistics
- Equipment/assets and maintenance tickets
- Committee action/task tickets
- Brickworks tracking and management
- Spreadsheet exports
- Email composer with Resend API, BCC bulk sending and read/open tracking
- User invite/password reset links
- Audit logging with Cloudflare Tunnel IP support
- Mobile usability improvements
- Performance improvements for faster loading

## Requirements

- Ubuntu 22.04/24.04
- Nginx
- PHP 8.1+
- php-fpm, php-sqlite3, php-mbstring, php-curl, php-cli
- unzip, rsync, git

```bash
apt update
apt install nginx php-fpm php-sqlite3 php-mbstring php-curl php-cli unzip rsync git -y
```

## Web root

Point Nginx to:

```text
/var/www/html/CADARS-Members-System/public
```

Do not expose the repository root directly.

## Runtime files not committed

```text
/database/app.sqlite
/storage/app_config.php
/storage/installed.lock
/storage/schema_version.txt
/storage/private/*
```

## Updating

```bash
chmod +x scripts/update-from-github.sh
scripts/update-from-github.sh
```

The updater preserves live `database/` and `storage/`.

## Docs

See `docs/`.

## Licence

MIT.
