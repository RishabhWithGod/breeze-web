# Production deployment

Everything needed to take this repository from a git checkout to a
running production instance, and to redeploy safely afterward.

## 1. Server requirements

| Component | Version | Notes |
| --- | --- | --- |
| PHP | 8.2+ (this app is built/tested on 8.4) | `composer.json` requires `^8.2` |
| Node.js | 20+ (built/tested on 24) | Only needed to run `npm ci && npm run build` — not required at runtime once `public/build` exists |
| MySQL | 8.0+ | This app is MySQL-only by design (see README — SQLite was tried and rejected: a takeoff's single-transaction write blocked the polling reader) |
| Redis | not required | Cache/queue/session all use MySQL (`database` driver) in this app's configuration; Reverb's own scaling mode (multi-instance) would need it, single-instance does not |

### Required PHP extensions

The Laravel 12 baseline: `ctype`, `curl`, `dom`, `fileinfo`, `filter`,
`hash`, `mbstring`, `openssl`, `pcre`, `pdo`, `session`, `tokenizer`,
`xml`. Plus, specific to this app:

- `pdo_mysql` — the only supported database driver.
- `zip` — `openspout/openspout` (Estimate/Job Costing/Timesheet XLSX
  exports) requires it.
- `gd` or `imagick` — not required by application code directly
  (`getimagesize()` used for preview sizing is core PHP), but keep one
  installed since PHP distributions commonly bundle it and some hosting
  images assume it's present.

### Required system packages

| Package | Used for | Verify with |
| --- | --- | --- |
| `poppler-utils` (provides `pdftoppm`) | Rendering PDF page previews for the AI Takeoff review screen (`ArtefactStore::renderPreviews`) | `pdftoppm -v` |
| `qpdf` | Pre-decompressing a PDF before annotating it (`AnnotatedPdfWriter::stampOriginal`) | `qpdf --version` |

**Both are optional at the code level and degrade gracefully if
missing** (this was verified during the Phase 10 audit — `qpdf` is not
installed in this repository's own development sandbox, and the
annotated-PDF export already falls back to the raw source file rather
than failing). But a production deployment should still install both:
without `pdftoppm`, the AI review screen loses page-image previews;
without `qpdf`, some more complex original PDFs may fail FPDI's import
and fall back to annotating page-preview images instead of the original
vector PDF. Neither missing binary crashes the app, but both visibly
degrade a feature.

```bash
# Debian/Ubuntu
apt-get install -y poppler-utils qpdf

# macOS (dev only)
brew install poppler qpdf
```

If either binary lives somewhere not on `PATH`, set `PDFTOPPM_BINARY`
(there is no equivalent override for `qpdf` today — it's called by its
bare name; add one the same way if a deployment needs it).

## 2. Deploying the code

```bash
# 1. Fetch the release
cd /var/www/breeze
git fetch origin
git checkout <tag-or-commit>

# 2. .env — copy the server's real .env into place if this is a fresh
#    checkout; otherwise it already exists and is untouched by git.
#    Never copy .env.example values verbatim into production — every
#    secret-shaped value there is a blank placeholder on purpose.

# 3. PHP dependencies (no dev packages, optimized autoloader)
composer install --no-dev --optimize-autoloader

# 4. Frontend dependencies + build
npm ci
npm run build

# 5. Storage permissions — the web server user must own these
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# 6. The public/storage symlink (only needed if this deployment ever
#    serves anything from the `public` disk — today nothing does, but
#    running it is harmless and future-proofs a Documents/Uploads
#    feature that starts using public visibility)
php artisan storage:link

# 7. Migrate — see the migration procedure below before running this
#    against a database that already has data.
php artisan migrate --force

# 8. Cache configuration/routes/views for production
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 9. Restart the long-running processes so they pick up the new code
php artisan queue:restart
php artisan reverb:restart

# 10. Verify
curl -f https://your-domain.com/health/live
curl -f https://your-domain.com/health/ready
```

`php artisan optimize:clear` is the inverse of steps 8 — run it first if
re-deploying over a previous cache (or just run it unconditionally
before re-caching; caching over stale cached values is harmless in
Laravel but clearing first removes any doubt).

**Route caching is confirmed compatible** — verified during the Phase 10
audit: `routes/web.php`/`routes/api.php` contain zero closure-based route
definitions (`routes/channels.php`'s closures are broadcast-channel
authorization callbacks, not HTTP routes, and are unaffected by
`route:cache`), so `php artisan route:cache` succeeds cleanly.

## 3. `.env` — production values

See `.env.example` for the full list with inline documentation. The
values that must differ from the example/local defaults:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

DB_HOST=<real host>
DB_DATABASE=<real database>
DB_USERNAME=<real user>
DB_PASSWORD=<real, generated password>

SESSION_SECURE_COOKIE=true

REVERB_APP_ID=<generated>
REVERB_APP_KEY=<generated>
REVERB_APP_SECRET=<generated — never reuse the dev value>
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
REVERB_HOST=your-domain.com
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_APP_ALLOWED_ORIGINS=your-domain.com
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"

SANCTUM_TOKEN_EXPIRATION_MINUTES=43200

ALLOW_DEMO_SEEDING=false   # never true in production
```

Everything else (queue/cache/session drivers, AI engine settings, mail,
time-tracking business defaults) is either already correct for
production as shipped or is a business decision the ops/product owner
makes per environment — not a security-relevant default.

## 4. Storage permissions — read/write, never execute

The application user (`www-data`, or equivalent) must be able to read
and write `storage/app/private` (uploads, AI artefacts, documents) and
`storage/logs`, but the web server must never be configured to execute
anything from inside `storage/`. This app already keeps every upload
disk private (`local`, i.e. `storage/app/private`, never `public`) and
generates a random storage filename for every upload rather than trusting
the client's original filename — confirmed during the Phase 10 audit, no
upload path is reachable from a publicly-executable location. Keep it
that way: never point `FILESYSTEM_DISK` (or any future upload feature) at
a disk whose root sits inside `public/`.

## 5. Nginx

See [`docs/realtime.md`](realtime.md#reverse-proxy-nginx--httpswss) for
the exact Reverb/WSS proxy block. The rest of the app is a standard
Laravel + Vite-built-assets Nginx config:

```nginx
server {
    listen 443 ssl http2;
    server_name your-domain.com;

    root /var/www/breeze/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/your-domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;

    client_max_body_size 512M; # matches public/.user.ini's upload ceiling

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Never serve dotfiles, storage internals, or the private disk directly.
    location ~ /\. { deny all; }
    location ^~ /storage/app/ { deny all; }

    # Reverb — see docs/realtime.md for the full WSS block.
    location /app/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_read_timeout 60s;
    }
}

server {
    listen 80;
    server_name your-domain.com;
    return 301 https://$host$request_uri;
}
```

## 6. Cron / scheduler

```cron
* * * * * cd /var/www/breeze && php artisan schedule:run >> /dev/null 2>&1
```

Currently the only thing this actually runs is
`time-tracking:send-approval-reminders`, daily at 08:00
(`routes/console.php`). Adding a scheduled task in the future needs no
separate cron entry — it only needs a `Schedule::` line in
`routes/console.php`; the one cron entry above already covers it.

## 7. Long-running processes

Both are required, both are supervised — see
[`deploy/supervisor/breeze.conf`](../deploy/supervisor/breeze.conf) (from
Phase 8) for the exact `queue:work`/`reverb:start` commands, restart
policy, and log paths. **A Supervisor config file existing is not the
same as it working** — after installing it, verify both processes are
actually running and actually processing:

```bash
supervisorctl status breeze-reverb:* breeze-queue:*
# Both should show RUNNING, not FATAL/STOPPED/BACKOFF.

# A real end-to-end check, not just "the process exists":
php artisan tinker --execute="event(new App\Events\JobStatusChanged(App\Models\Job::first(), 'a', 'b'));"
# then confirm the corresponding job row in `jobs` moves from queued to
# gone within a few seconds (`SELECT COUNT(*) FROM jobs;` before and after).
```

## 8. Health checks

- `GET /health/live` — the process can answer at all. No dependency
  checked; a load balancer uses this to decide "is this instance alive."
- `GET /health/ready` — checks the database connection and the queue's
  own table are actually reachable, returning `503` with
  `{"status":"degraded","checks":{...}}` if either isn't — a load
  balancer uses this to decide "should this instance receive traffic."
- `GET /up` — Laravel's own built-in check (unrelated to the two above,
  left as Laravel's default).

None of the three expose a secret, a stack trace, or an internal path —
verified directly:

```bash
curl -s https://your-domain.com/health/ready | python3 -m json.tool
# {"status": "ok", "checks": {"database": true, "queue": true}}
```

## 9. Rollback

See [Rollback](#rollback-1) below — kept as its own section since it's
the thing most likely to be needed under time pressure.

### Code rollback

```bash
git checkout <previous-tag-or-commit>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
php artisan reverb:restart
```

### Migration rollback — never blind

**Do not** run `php artisan migrate:rollback` as a default rollback
strategy. It reverses migrations by running their `down()` methods in
order, which for an *additive* migration (this project's convention —
see Phase 8/9/10's own migrations, all additive) usually means dropping
a column/table/index that the *previous* code version never expected to
lose, but that doesn't mean it's free of risk:

- A migration that dropped a redundant index, changed a foreign key's
  `onDelete` behavior, or backfilled data has a `down()` that reverses
  the *schema* change but cannot restore data that changed shape or was
  deleted in between.
- If the rolled-back code is now running against a schema one migration
  *ahead* of what it expects (because a rollback of the LATEST migration
  was skipped, or ran against the wrong environment), you get silent
  data corruption, not a clean error.

**The safe rollback procedure:**

1. Identify exactly which migrations ran *after* the last known-good
   deploy (`php artisan migrate:status`).
2. For each one, read its `down()` method and confirm by hand that
   reversing it is safe *given the current data* — not just "it has a
   `down()` method."
3. Only then run `php artisan migrate:rollback --step=N` for the exact
   count identified in step 1 — never an unbounded `migrate:rollback`.
4. If any migration in that set is not safely reversible (most schema
   *additions* are; drops/renames/type-changes usually aren't once real
   data exists), the correct rollback is restoring the pre-deploy
   database backup (see
   [`docs/production-backup.md`](production-backup.md)) instead of
   rolling migrations backward.

### Asset / frontend rollback

`public/build/` is regenerated by `npm run build`; rolling back is
rebuilding from the previous git commit, not restoring a backup of the
`public/build` directory. Keep the previous release's checkout around
(a `releases/<timestamp>` directory + a `current` symlink, the standard
Capistrano-style layout) so a rollback is "point the symlink back," not
"rebuild under time pressure."

### Queue / Reverb after any rollback

Always `php artisan queue:restart` and `php artisan reverb:restart`
after a code rollback — a running worker holds the *old* (about to be
replaced) code in memory until you tell it to stop; without a restart,
half the fleet processes jobs with new code and half with old.
