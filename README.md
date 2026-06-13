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

## User invites and password reset emails

The Users page now supports:

- Send invite to an existing user
- Invite new user with secure password setup link
- Send password reset email
- Manual temporary password reset as a fallback

Invite/reset links expire after 48 hours and ask the user to set their own password.
Manual temporary passwords force the user to change password after login.

Email delivery uses the configured mail method under Email system config.

## UI tweaks

The Users page role selector uses inline checkbox cards so the checkbox and role name line up clearly.

## Deleting members and users

Admins can delete user accounts from the Users page. The system prevents deleting your own logged-in account and prevents deleting the last active admin.

Member DB users can delete member records where safe. If a member is linked to a user account, an admin is required because the linked user account must be deleted too. Deleting a member removes linked membership data, attendance records, consents, payments, directory preferences and Brickworks progress/evidence references.

## Admin deletion safety

User/member deletion now enforces:

- Only admins can delete user accounts.
- Admin users can only be deleted by another admin.
- The last active admin cannot be deleted.
- Removing roles cannot leave the system with zero active admins.
