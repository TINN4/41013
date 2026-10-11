# Database storage (MySQL / MariaDB)

## What changed
Until now every table was a JSON file in `session-system/data/`. A container redeploy replaced that folder, so accounts and records
were lost. The app now stores everything in the database when it is configured, and keeps the JSON files only as a development fallback.

| What | Where (database mode) | Where (file mode) |
|---|---|---|
| Records (users, sessions, members, ...) | table `ssms_store` (one JSON row per logical table) | `data/<table>.json` |
| Audit log (hash chain) | table `ssms_audit` | `data/audit.log` |
| Login sessions | table `ssms_sessions` | PHP session files |
| Backups made by the tools | table `ssms_backup` | `data/backups/` |

The tables are created automatically on first use (`CREATE TABLE IF NOT EXISTS`), so the database user needs the CREATE privilege.
Other tables already in the database (for example from an older version) are not touched: all names start with `ssms_`.

## Turning it on
Nothing to do on HostForge: it injects `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` into the running container,
and the app uses the database as soon as `DB_HOST` and `DB_DATABASE` exist. `SSMS_STORAGE=file` forces files, `SSMS_STORAGE=mysql` forces the database.

## Settings that must be real environment variables (they no longer live in the container)
`SSMS_APP_KEY` (generate once: `openssl rand -base64 32`), `SSMS_ENV`, `SSMS_APP_URL`, `SSMS_SMTP_HOST`, `SSMS_SMTP_PORT`, `SSMS_SMTP_ENCRYPTION`,
`SSMS_SMTP_USER`, `SSMS_SMTP_PASS`, `SSMS_MAIL_FROM`. Environment variables win over `config/.env`.

## Checking it
    php tools/migrate_to_db.php --check     # prints "storage: mysql (...)" and the table counts

## Moving existing JSON data in
    php tools/migrate_to_db.php --import               # tables not yet in the database
    php tools/migrate_to_db.php --import --overwrite   # replace database tables with the files

## Limits
Each logical table is one JSON document, so a write rewrites that whole table. That is fine for a council-sized deployment (hundreds
to a few thousand rows per table) and is safe under concurrency, but it is not a relational schema you can query with SQL.
Back up the database with HostForge Backups.
