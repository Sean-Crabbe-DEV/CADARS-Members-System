# CADARS Members System

Self-hosted membership system for Chepstow & District Amateur Radio Society.

## Email sending

The Email system config page supports:

- PHP mail()
- Resend API
- SMTP settings stored for future SMTP wiring

For Resend API:

1. Go to Committee > Emails.
2. Click Email system config as an admin.
3. Set Mail method to Resend API.
4. Enter the Resend API key.
5. Set the From email to an address/domain allowed in Resend.
6. Save and send a test email.

Bulk emails with more than one recipient are sent using BCC for member privacy.

## Dashboard events update

Dashboard now shows only the next three events within the next month. User roles and attendance summary have been removed from the dashboard to keep it cleaner.

## Dashboard layout

Dashboard order adjusted so Notifications appear in the top dashboard grid and Next events appears below.

## Login and password recovery

The login page has been restyled with a responsive centred sign-in card. Users can now use **Forgot password?** to request a self-service password reset link. Reset links use the existing secure token system and expire after 48 hours.

## User linked member update

The Users admin page now allows admins to change which member record is linked to an existing user account, or unlink the user from a member record. Changes are audit logged with old and new linked member values.

## Wallet settings page

Added Admin > Wallet settings. Admins can upload Apple Wallet pass certificate files, private key, WWDR certificate, certificate password, and Google/Android Wallet issuer/API details including service account JSON. Uploaded credential files are stored under the private storage folder rather than the public web folder.

## SMTP sending and test email

- Implemented SMTP sending with STARTTLS/TLS, SSL/SMTPS, or trusted unencrypted SMTP.
- Supports SMTP AUTH LOGIN with AUTH PLAIN fallback.
- SMTP sends support HTML email, BCC envelope recipients, Reply-To, and attachments.
- Email settings now have a Send test email button which saves the current configuration and sends a test using the selected transport.
- SMTP/Resend secret fields no longer display saved secrets; leaving them blank retains the existing value.
