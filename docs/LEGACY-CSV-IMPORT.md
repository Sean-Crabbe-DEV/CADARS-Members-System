# One-time legacy CSV import

This script imports legacy exported CSV files into the live CADARS Members System database.

Supported files:

- `Event_export.csv`
- `Asset_export.csv`
- `Attendance_export.csv`

The importer is designed for one-time migration from the previous club management system.

## What gets imported

### Events

Legacy columns used:

- `id`
- `title`
- `type`
- `date`
- `end_date`
- `description`
- `notes`
- `location`
- `google_calendar_event_id`

The old legacy event ID is stored in the `legacy_import_map` table so attendance can be linked to the correct imported event.

### Assets/equipment

Legacy columns used:

- `id`
- `name`
- `condition`
- `cost`
- `notes`
- `description`
- `location`
- `serial_number`
- `purchase_date`

The legacy `cost` field is imported as both purchase amount and value where known.

### Attendance

Legacy columns used:

- `event_id`
- `user_email`
- `user_name`
- `guest_name`
- `is_guest`
- `guest_notes`
- `present`
- `id`

Attendance is matched to imported events using the old legacy event ID.

Members are matched by email first, then name. If a member cannot be matched, the row is imported as a guest/visitor with a note, so the attendance data is not lost.

## Recommended process

Upload the CSV files to the server, for example:

```text
/root/import/Event_export.csv
/root/import/Asset_export.csv
/root/import/Attendance_export.csv
```

Back up the live system:

```bash
APP=/var/www/html/CADARS-Members-System
mkdir -p /root/cadars-members-backups
tar -czf /root/cadars-members-backups/pre-legacy-import-$(date +%F-%H%M).tar.gz "$APP/database" "$APP/storage"
```

Run a dry run first:

```bash
cd /var/www/html/CADARS-Members-System

php scripts/import-legacy-csv.php \
  --events="/root/import/Event_export.csv" \
  --assets="/root/import/Asset_export.csv" \
  --attendance="/root/import/Attendance_export.csv" \
  --timezone=Europe/London \
  --dry-run
```

If the dry run looks good, run it for real:

```bash
php scripts/import-legacy-csv.php \
  --events="/root/import/Event_export.csv" \
  --assets="/root/import/Asset_export.csv" \
  --attendance="/root/import/Attendance_export.csv" \
  --timezone=Europe/London
```

## Import order

When importing all files together, the script imports in this order:

1. events
2. assets
3. attendance

Attendance depends on events being imported first.

## Duplicate protection

The script creates and uses:

```text
legacy_import_map
```

This prevents duplicate imported events/assets where possible.

Running the importer again updates existing imported records by default.

Use this to avoid updates:

```bash
--no-update
```

## Unmatched attendance members

By default, unmatched member attendance rows are imported as guest/visitor records with a note.

To skip unmatched members instead:

```bash
--skip-unmatched-members
```
