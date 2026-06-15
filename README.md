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

## Committee actions and assets UI update

The same modern UI pass has been applied to:

- Equipment / assets
- Asset details and maintenance tickets
- Committee actions

This adds cleaner hero sections, modern cards, summary stats, better ticket styling and mobile-only responsive improvements.
