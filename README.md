# CADARS Members System

**V2 Club Management and Information System**  
Created and maintained by **Sean Crabbe** — <sean@defenderonfrequency.uk>

A lightweight, self-hosted, web-based membership and club management system for a ham radio society.

The system is designed for an Ubuntu CT/VPS with Nginx and PHP-FPM. It uses a simple PHP application with SQLite storage for easy deployment and low maintenance.

## Main features

- First-time installer with admin account setup
- No default admin account
- Login system
- Multi-role users
- Admin role management
- Member database
- Member self-service profile
- Emergency contact fields
- GDPR/data consent fields
- Internal member directory with opt-in
- Programme/events calendar and list view
- Attendance register
- Guest/visitor attendance
- Attendance statistics
- Equipment/assets register
- Maintenance tickets/history per asset
- Committee action/task tickets
- Brickworks Scheme tracking
- Brickworks management matrix
- Evidence uploads
- Spreadsheet exports
- Email composer
- Member recipient selection
- Bulk BCC sending
- Resend API support
- Open/read tracking pixels
- User invite links
- Password reset links
- Audit logging with Cloudflare Tunnel IP support
- Mobile usability improvements
- Footer with policy links

## Requirements

Recommended:

- Ubuntu 22.04/24.04 CT or VM
- Nginx
- PHP 8.1+
- PHP extensions:
  - sqlite3
  - mbstring
  - curl
  - sodium
- unzip
- rsync
- git

Install packages:

```bash
apt update
apt install nginx php-fpm php-sqlite3 php-mbstring php-curl php-cli unzip rsync git -y
```

## Quick install

Clone the repo:

```bash
cd /var/www/html
git clone https://github.com/Sean-Crabbe-DEV/CADARS-Members-System.git
chown -R www-data:www-data CADARS-Members-System
find CADARS-Members-System -type d -exec chmod 750 {} \;
find CADARS-Members-System -type f -exec chmod 640 {} \;
```

Nginx should point the web root to:

```text
/var/www/html/CADARS-Members-System/public
```

Then open the site in a browser and complete the first-time installer.

## Important runtime files

These are created on the live server and must **not** be committed to GitHub:

```text
/database/app.sqlite
/storage/app_config.php
/storage/installed.lock
/storage/private/*
```

The `.gitignore` protects these files.

## Updating live system

Use the included script:

```bash
chmod +x scripts/update-from-github.sh
scripts/update-from-github.sh
```

It preserves live `database/` and `storage/` while updating app files.

## Documentation

See the `docs/` folder:

- [Installation](docs/INSTALL.md)
- [Nginx config](docs/NGINX.md)
- [Updating from GitHub](docs/UPDATE.md)
- [Permissions and roles](docs/ROLES.md)
- [Email setup](docs/EMAIL.md)
- [Data protection notes](docs/DATA-PROTECTION.md)
- [Backup and restore](docs/BACKUP-RESTORE.md)
- [Security hardening](docs/SECURITY.md)
- [Project structure](docs/PROJECT-STRUCTURE.md)
- [Changelog](docs/CHANGELOG.md)

## Licence

MIT.
