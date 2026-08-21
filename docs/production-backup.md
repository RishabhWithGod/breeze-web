# Production backup & restore

Two things need backing up: the MySQL database and the private storage
disk (`storage/app/private` — uploaded drawings, generated previews,
annotated PDFs, documents, response JSON). Nothing else is stateful;
`storage/logs`, `bootstrap/cache`, and the built frontend are all
reproducible from the repository and are not backed up.

## What to back up

| What | Where | Why it matters |
| --- | --- | --- |
| MySQL database | `DB_DATABASE` | Every business record — jobs, time entries, invoices, security events, everything |
| Private storage disk | `storage/app/private` (`FILESYSTEM_DISK=local`) | Uploaded drawings, AI Takeoff artefacts, Documents, job/task attachments — losing this loses the actual files the database's rows point at |
| `.env` | server, not the repo | Not itself a backup target in the usual sense (it's config, not data) — but losing it without a copy means regenerating `APP_KEY`, which makes every encrypted session/cookie unreadable. Keep a copy in the secrets manager the deploy process already uses, not in a database backup |

Not backed up (correctly): `storage/logs/*.log` (operational, not business data), `bootstrap/cache/*` (rebuilt by `config:cache`/`route:cache`), `public/build` (rebuilt by `npm run build`), `node_modules`/`vendor` (rebuilt by `npm ci`/`composer install`).

## Daily backup

```bash
# Database — a single consistent snapshot, safe to run against a live app
# (--single-transaction avoids locking InnoDB tables for the duration).
mysqldump \
  --single-transaction \
  --routines \
  --triggers \
  -h "$DB_HOST" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" \
  | gzip > "/var/backups/breeze/db-$(date +%Y%m%d-%H%M%S).sql.gz"

# Storage — an incremental sync, not a full copy every time.
rsync -a --delete \
  /var/www/breeze/storage/app/private/ \
  /var/backups/breeze/storage/
```

Run both once a day via cron or the hosting platform's scheduled-job
equivalent, offset from the app's own scheduler (`schedule:run`) so they
don't compete for I/O:

```cron
# Database + storage backup, 02:15 daily — clear of the AI-processing
# rush hours and the 08:00 approval-reminder job.
15 2 * * * /var/www/breeze/deploy/backup.sh >> /var/log/breeze-backup.log 2>&1
```

`deploy/backup.sh` should wrap the two commands above and push the result
to off-host storage (S3, a backup appliance, whatever the hosting
environment already provides) — a backup that lives on the same disk as
the database it protects doesn't protect against a disk failure.

## Retention

- **Daily backups**: keep 14 days.
- **Weekly** (e.g. the Sunday daily): keep 8 weeks.
- **Monthly** (first of the month): keep 12 months.

Adjust to whatever the business's actual compliance/retention requirement
is — this is a reasonable floor, not a maximum.

## Restore procedure

**Database:**

```bash
gunzip -c /var/backups/breeze/db-20260101-021500.sql.gz \
  | mysql -h "$DB_HOST" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE"
```

**Storage:**

```bash
rsync -a /var/backups/breeze/storage/ /var/www/breeze/storage/app/private/
```

**After restoring either:**

```bash
php artisan optimize:clear   # stale cached config/routes must not survive a restore
php artisan queue:restart    # workers must not process jobs against the old state
php artisan reverb:restart
```

Restoring the database and the storage disk from **different points in
time** will leave rows pointing at files that don't exist (or files with
no row referencing them) — always restore both from backups taken in the
same run, not two backups from different days.

## A backup that has never been restored is not verified

Schedule an actual restore drill — not a "the file exists and isn't
zero-length" check, a real restore into a throwaway environment,
verified by:

1. `php artisan migrate:status` — confirm the schema matches.
2. Log in as a real (restored) user and open a Job Detail page — confirm
   real data renders, not an empty state.
3. Open a Document/AI Takeoff drawing that existed before the backup —
   confirm the file itself opens, not just the database row.
4. `php artisan tinker` → spot-check a row count against what's expected.

Do this **before** the first real incident, and periodically after
(quarterly is a reasonable cadence) — restore steps drift as the schema
and storage layout change, and a backup strategy that was correct six
months ago can silently stop being restorable without anyone noticing
until it's needed.
