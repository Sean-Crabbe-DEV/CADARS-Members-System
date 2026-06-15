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

## Legacy CSV import

Added `scripts/import-legacy-csv.php` for one-time import of old Events, Assets/Equipment, and Attendance CSV exports.

See `docs/LEGACY-CSV-IMPORT.md`.

## Programme filtering

The Programme page now has Current & future and Past tabs.

- Current & future events show nearest first.
- Past events show most recent first.
- Events can be searched by title, description or location.
- Events can be filtered by event type.

## Programme UI, mobile and performance update

- Programme page has a modern event-card layout while keeping the existing workflow.
- Mobile-only CSS has been expanded for Programme, Users, tables, forms, modals, email and attendance screens.
- Users page Role guide is now a clear table with each role and what access it gives.
- SQLite performance tuning added: WAL mode, busy timeout, cache size, temp memory store, normal sync and targeted indexes.
- Runtime seed/index setup now runs only when the internal setup version changes instead of on every page load.
