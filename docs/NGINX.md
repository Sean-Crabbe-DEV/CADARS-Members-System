# Nginx Configuration

Example server block:

```nginx
server {
    listen 80;
    server_name members.example.org;

    root /var/www/html/CADARS-Members-System/public;
    index index.php;

    client_max_body_size 25M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
    }

    location ~ /\. {
        deny all;
    }

    location ~* /(database|storage|docs|scripts)/ {
        deny all;
    }
}
```

Test and reload:

```bash
nginx -t
systemctl reload nginx
```

## HTTPS

Use Certbot or a reverse proxy such as Nginx Proxy Manager/Cloudflare Tunnel.

The application supports Cloudflare IP headers in audit logs.
