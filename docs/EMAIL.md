# Email Setup

The system includes a built-in email composer.

## Features

- Select individual members
- Send to all eligible active members
- Bulk messages use BCC
- Resend API support
- Basic PHP mail support
- SMTP settings page scaffold
- Attachments
- Open/read tracking pixel support
- Invite emails
- Password reset emails

## Resend API

1. Go to Committee > Emails.
2. Click Email system config.
3. Set method to Resend API.
4. Enter the API key.
5. Set From email to a verified Resend sender/domain.
6. Save.

Install PHP cURL:

```bash
apt install php-curl -y
systemctl restart php8.1-fpm
```

## Open/read tracking

Open tracking uses a small image pixel.

Limitations:

- image blocking may stop opens being tracked
- Apple Mail Privacy Protection may preload images
- security scanners may trigger false opens

Treat the status as **tracking image loaded**, not guaranteed proof of reading.
