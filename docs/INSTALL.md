# Installation Guide

## 1. Install packages

```bash
apt update
apt install nginx php-fpm php-sqlite3 php-mbstring php-curl php-cli unzip rsync git -y
```

## 2. Clone the repository

```bash
cd /var/www/html
git clone https://github.com/Sean-Crabbe-DEV/CADARS-Members-System.git
cd CADARS-Members-System
```

## 3. Set ownership and permissions

```bash
chown -R www-data:www-data /var/www/html/CADARS-Members-System
find /var/www/html/CADARS-Members-System -type d -exec chmod 750 {} \;
find /var/www/html/CADARS-Members-System -type f -exec chmod 640 {} \;
```

## 4. Configure Nginx

Your web root must be:

```text
/var/www/html/CADARS-Members-System/public
```

Do not expose the repository root directly.

## 5. Open the site

Visit the site in a browser.

The first-time installer will ask you to create the first admin user.

## 6. Finish setup

After setup, the installer is locked using:

```text
/storage/installed.lock
```

Do not delete this unless you are intentionally reinstalling.
