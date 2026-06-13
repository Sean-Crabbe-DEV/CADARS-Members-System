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

## Update notes - directory, membership numbers, join dates and consents

This build updates the member/profile workflow:

- The internal directory now shows **Name** then **Callsign** only.
- Members opt into the internal directory using one simple profile checkbox.
- Admin users only can manually set or change membership numbers.
- Member DB users can still view membership numbers, but cannot edit them unless they are also Admin.
- Member record fields now have labels above the inputs.
- Member profiles show membership number and date joined.
- Member records now support **Joined before system / date not on record**.
- Consents have been simplified to:
  - Email communications
  - Text messages
  - WhatsApp community opt-in
- Members can edit their own consents from My Profile.
- Member DB users and Admins can view/edit consents from the membership database.
- Email sending now only includes active members who have consented to email communications.

When updating a live install, keep using the existing rsync method and exclude `database/` and `storage/`. The app will add the new `joined_before_system` database column automatically after login.
