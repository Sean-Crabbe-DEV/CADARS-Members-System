# Ham Radio Society Membership System - Starter Build

This is a working starter web application for a small ham radio society membership system.

It is intentionally dependency-light: native PHP 8.2+ with SQLite, so it can be dropped onto an Ubuntu LTS container quickly. It includes the core structure you asked for and is suitable as an MVP/prototype before hardening or converting to Laravel.

## Included features

- First-time install wizard
- No default admin login
- Initial admin user creation
- Multi-role users
- Role/permission-based access control
- Admin user creation and password resets
- Member self-service profile editing
- GDPR-style preferences and consent tracking fields
- Internal callsign directory with opt-in visibility
- Member database restricted to Admin / Member DB users
- Payment/subs history
- Date joined / renewal date / status
- Role history
- Event management and attendance signup
- Attendance percentage calculations
- Equipment database for committee/equipment users
- Brickworks Scheme participant signup
- Brickworks criteria progress statuses:
  - Not completed
  - In progress / Pending approval
  - Complete - date complete
- Brickworks evidence upload and reviewer comments
- Email communication module scaffold:
  - rich/basic HTML body
  - attachment upload/storage
  - send-to-active-members draft/send flow
  - opt-in open tracking pixel
  - open count reporting
- Full audit logging for views, edits, permission denials, email actions, profile actions, directory access, events, equipment, Brickworks, and member database activity
- Sensitive fields encrypted using PHP Sodium where available
- Private uploads stored outside public web root

## Important limitations in this starter

This is a starter MVP, not a finished production SaaS app.

Before live production use, you should add or improve:

- SMTP sending with proper MIME attachment support. The current starter stores attachments and uses PHP `mail()` for sending.
- Two-factor authentication.
- Password reset email links.
- Better role management UI.
- Pagination/search everywhere.
- Stronger file validation and malware scanning.
- Database migration tooling.
- Off-container encrypted backups.
- Rate limiting at Nginx or app level.
- Proper queue worker for bulk email.
- More complete Brickworks criteria list if required by your society.

## Suggested Ubuntu CT requirements

- Ubuntu 24.04 LTS or 22.04 LTS
- 2 vCPU
- 2 GB RAM minimum
- 20-40 GB disk minimum
- Static IP
- HTTPS via reverse proxy or Certbot

## Install packages

```bash
sudo apt update
sudo apt install nginx php-fpm php-cli php-sqlite3 php-mbstring php-xml php-curl php-zip unzip
```

Sodium is normally included with modern PHP. Check with:

```bash
php -m | grep sodium
```

## Deploy

Copy the project to:

```bash
sudo mkdir -p /var/www/ham-membership-system
sudo cp -r ham-membership-system/* /var/www/ham-membership-system/
sudo chown -R www-data:www-data /var/www/ham-membership-system
sudo find /var/www/ham-membership-system -type d -exec chmod 750 {} \;
sudo find /var/www/ham-membership-system -type f -exec chmod 640 {} \;
```

The only public web root should be:

```text
/var/www/ham-membership-system/public
```

Do **not** point Nginx at the project root.

## Example Nginx config

Create:

```bash
sudo nano /etc/nginx/sites-available/ham-membership-system
```

Example:

```nginx
server {
    listen 80;
    server_name members.example.org;

    root /var/www/ham-membership-system/public;
    index index.php;

    client_max_body_size 20M;

    location / {
        try_files $uri /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\. {
        deny all;
    }
}
```

Adjust the PHP-FPM socket for your PHP version:

```bash
ls /run/php/
```

Enable site:

```bash
sudo ln -s /etc/nginx/sites-available/ham-membership-system /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

Then put HTTPS in front using either Certbot or your existing reverse proxy/Cloudflare Tunnel.

## First run

Open the site in your browser. The installer will ask for:

- Society name
- Base URL
- First admin name
- Admin callsign
- Email
- Password

Once the first admin is created, the installer locks itself using:

```text
storage/installed.lock
```

## Data locations

SQLite database:

```text
database/app.sqlite
```

App config and encryption key:

```text
storage/app_config.php
```

Private uploads:

```text
storage/private/
```

Back these up securely. The app key is required to decrypt encrypted fields.

## Security notes

- Keep `storage/app_config.php` private.
- Keep `database/app.sqlite` private.
- Do not expose `/storage`, `/database`, or the project root through Nginx.
- Use HTTPS only.
- Put admin accounts behind strong passwords.
- Add 2FA before production use.
- Keep Ubuntu and PHP patched.

## Recommended backup command

Example daily backup target:

```bash
sudo tar -czf /backup/ham-membership-system-$(date +%F).tar.gz \
  /var/www/ham-membership-system/database \
  /var/www/ham-membership-system/storage
```

For production, encrypt the backup and copy it off the CT.

## Default permissions model

Members can:

- View/edit their own profile
- Manage directory/email preferences
- View events
- Sign up to events
- Join Brickworks
- Submit Brickworks evidence
- View the internal callsign directory

Committee can:

- Manage events
- Track attendance
- View/edit equipment database

Member DB users can:

- View/edit membership database
- Manage subscriptions/payments
- Send member/subs emails
- View member audit logs

Admin can:

- Manage users
- Reset passwords
- Manage roles/permissions
- View audit logs
- Access all modules

## Next recommended development step

The next step should be replacing the basic email sender with a real SMTP/MIME mailer and improving the role-management UI, so admins can assign/remove multiple roles directly in the app.

## Audit logging detail

Audit logs now capture expanded metadata for data changes, including old and new values where available. This is applied to member/profile edits, role changes, event edits, attendance register updates, committee action updates, equipment maintenance tickets, and Brickworks review/status changes. Audit log details are visible from the Audit logs page.

## Member spreadsheet imports

The Membership database now includes an Import members page. It supports CSV and XLSX uploads in either the system export format or the CADARS spreadsheet format with headers such as Member Number, Full Name, Email, Phone, Callsign, License Class, Society Role, Payment Status, Payment Date, Membership Start, Active, Emergency Contact and Emergency Phone.

XLSX import requires the PHP zip extension:

```bash
apt install php-zip -y
systemctl restart php8.1-fpm
```

## Dashboard, Directory and Brickworks UI update

The same modern UI pass used on Programme has been applied to:

- Dashboard
- Internal Directory
- Brickworks

This includes cleaner card layouts, improved summary panels, better mobile-only responsiveness and clearer action areas.
