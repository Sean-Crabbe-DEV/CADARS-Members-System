# Performance Notes

The system includes the following speed improvements:

- Request-level app config cache
- Request-level current user cache
- Request-level role and permission cache
- SQLite WAL mode
- SQLite busy timeout
- SQLite normal synchronous mode
- SQLite memory temp store
- Larger SQLite cache
- Automatic indexes for common searches/joins
- Schema/migration/seed checks only run when schema version changes
- CSS route sends cache headers and ETag
- CSS link includes a version query string

## First load after update

The first page load after a new performance/schema version may be slower because indexes are created.
After that, the system writes:

```text
/storage/schema_version.txt
```

and skips expensive schema setup on normal page loads.

## Manual reset

To force schema/index checks again:

```bash
rm /var/www/html/CADARS-Members-System/storage/schema_version.txt
```

Then reload the site.
