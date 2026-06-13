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
