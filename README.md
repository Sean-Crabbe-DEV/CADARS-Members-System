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

## User admin and officer roles

Added officer roles:

- Chair
- Vice Chair
- Secretary

The Users admin page has been redesigned into a more spacious card layout with clearer role chips, role management panels, invite/reset actions and user summary stats.

## User admin layout

Invite new user and Create manual user have been moved to their own pages. The Users page now has top action buttons for those workflows, keeping the user admin list cleaner and less cramped.
