# Production hardening checklist

## Must-do before real member data

- Enable HTTPS.
- Restrict Nginx root to `/public` only.
- Confirm `/storage` and `/database` cannot be downloaded from the browser.
- Use strong admin passwords.
- Add 2FA for admin, member DB users, committee users and reviewers.
- Set up encrypted off-container backups.
- Test restore from backup.
- Configure proper SMTP with SPF/DKIM/DMARC.
- Add file upload limits and malware scanning.
- Add rate limiting for login and email open endpoints.
- Add privacy notice and consent wording agreed by the society.
- Decide retention policy for former members, audit logs, payments and Brickworks evidence.

## Recommended later improvements

- Convert to Laravel for long-term maintainability.
- Add migrations.
- Add background queue for emails.
- Add recurring renewal/subs reminders.
- Add CSV import/export with audit logging.
- Add search and pagination for large member lists.
- Add formal subject access request export workflow.
- Add tamper-evident audit log hash chain.
