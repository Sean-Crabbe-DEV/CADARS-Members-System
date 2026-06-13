# Installation

```bash
apt update
apt install nginx php-fpm php-sqlite3 php-mbstring php-curl php-cli unzip rsync git -y
cd /var/www/html
git clone https://github.com/Sean-Crabbe-DEV/CADARS-Members-System.git
chown -R www-data:www-data CADARS-Members-System
find CADARS-Members-System -type d -exec chmod 750 {} \;
find CADARS-Members-System -type f -exec chmod 640 {} \;
```

Set Nginx root to `public/`, then browse to the site and create the first admin.
