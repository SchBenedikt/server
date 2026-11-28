# Operations

This page covers the tasks that keep a running instance healthy: accounts,
workers, cleanup, backups, upgrades, monitoring and troubleshooting.

## Daily commands

Run these from the application root as the application user:

```shell
php bin/worker.php --once          # process one queue batch
php bin/cleanup.php                # queue the retention cleanup
php bin/cleanup.php --now          # run retention cleanup immediately
php bin/routes.php                 # inspect the served route manifest
php bin/set-password.php alice     # reset a password and revoke sessions
php bin/backup.php                 # verified database backup
php bin/update.php                 # backup, migrate and verify accounts
php bin/setup-token.php            # first-run setup URL; never deletes data
php bin/reset.php                  # back up, empty the instance, print setup URL
php bin/doctor.php                 # paths, schema, key, database and mail
```

`GET /readyz` is the readiness probe: it checks the database, the Core schema
fingerprint, the worker heartbeat and queue depth, and returns `503` when the
database or schema is not ready. `GET /healthz` remains the dependency-free
liveness probe.

`php bin/worker.php` without `--once` is the supervised long-running worker.
Stop it with `SIGTERM` or `Ctrl+C`; it finishes the current batch first.

## Accounts

Accounts are operator-managed. Registration is disabled by design
(`instance.registrations=false`).

```shell
# Create a normal account. The password is read from the prompt.
php bin/create-account.php alice alice@example.com

# Create an admin account with a display name.
php bin/create-account.php admin admin@example.com --admin --display-name='Admin'

# Non-interactive provisioning (avoid shell history for real secrets).
php bin/create-account.php bob bob@example.com --password='replace-me'
```

Handles are lower-case, immutable, and 2–30 characters. The account ID is the
handle. Deleting accounts is not exposed as a CLI operation in this host; plan
the account lifecycle before creating public identities.

### Password reset by email

The login page links to `/forgot-password`. A request stores only a SHA-256
hash of a single-use token and queues the mail in `mail_outbox` inside the
same transaction; the worker delivers it with bounded retries, so an SMTP
outage delays the link instead of losing it or failing the request. Links
expire after one hour, a completed reset revokes every session, and the
dispatcher cancels queued mail whose token was used, rotated or expired, so
an obsolete link is never delivered. Terminal outbox rows are pruned after
seven days. The token is claimed atomically inside the reset transaction,
each account has at most one live link, and a link is bound to the credential
generation it was issued for. A password changed by an administrator or the
CLI therefore invalidates older emailed links instead of being overwritten
by them.

Development (`ALOHASOCIAL_MAIL_TRANSPORT=log`):

```shell
ls var/mail/
# open the newest .eml and use the reset link
```

The `log` transport writes complete messages — including live reset tokens —
to `var/mail/` as plaintext `.eml` files. Use it only on development
machines; on any reachable instance configure SMTP.

Production should use SMTP. If the worker cannot deliver, messages stay
pending with backoff and `last_error` records the failure class; check
`mail_outbox` and the worker journal instead of lowering the transport to
`log` on a public instance.

To reset a password without email:

```shell
php bin/set-password.php alice
```

This also revokes all existing sessions for the account.

## Administration and moderation

The admin interface is part of the host app and lives at `/admin`, which
redirects to `/app#/admin`. Only accounts with the `admin` role can open it or
call `/api/v1/host/admin/*`. Another account gets `403`; a signed-out request
gets `401`.

```shell
# Bootstrap the first administrator when no admin account exists yet.
php bin/create-account.php admin admin@example.com --admin

# After that, use the admin UI:
#   Accounts -> role toggle, display name, password reset, session revoke,
#   disable/enable, create account.
```

An operator can always create a new admin account from the CLI when locked out,
but the UI is the normal path because it writes an audit entry. Every mutation
is recorded in `admin_audit` with actor, action, target, details and time; the
**Audit log** section reads that table.

Disabling a member revokes browser and native PDS sessions, cancels that
account's queued or reserved background jobs, and prevents linked Bluesky or
native PDS identities from resolving for publication. Re-enabling restores
the stored linked account, but does not recreate deliveries cancelled during
the disabled period.

### Communication and runtime errors

The **Errors** tab lists warnings and failures recorded by the worker and the
federation layer: outbound delivery errors, remote fetch problems, media
failures and other background work. It also lists dead jobs that exhausted
their retries. The runtime logger writes the same messages to the PHP error log,
so shell access still gives the operational picture without the admin UI.
Both sinks pass through the same redaction boundary: URL credentials, query
strings and fragments, authorization/cookie values, tokens, passwords and
unknown context fields are removed before a message is written or stored. Only
allowlisted operational fields such as the job id, status, domain, error code
and sanitized URL are retained; peer response bodies are reduced to a stable
error code.

Do not treat every warning as an outage. A remote server returning `404` or a
blocked media fetch is a peer problem; repeated failures for the same domain,
a growing dead-job count, or failures immediately after a domain change point
to local configuration. Check the worker and the public origin first, then the
peer's HTTP response.

### Reports

Any signed-in reader can report a post or a profile. Reports are stored in
`moderation_report` with one of five categories and an optional comment. The
admin **Meldungen** view shows the target preview, reporter, category, state and
the internal resolution note.

- **Open** reports are the working queue.
- **Resolved** means the case was reviewed and handled.
- **Dismissed** means no action was taken.
- A duplicate open report by the same reporter for the same target answers
  `409`.
- Remote posts and profiles may be reported by canonical URL, `at://` or
  `did:` before they are cached. They appear with scope `remote` and a link to
  the origin; deleting them only tombstones a cached row, never the remote
  object.
- Deleting a local post and disabling a local account from the report view are
  audited. Disabling keeps posts and follower edges and can be reversed with
  **Aktivieren**.

Moderation is not a substitute for federation policy. Serve only over HTTPS,
keep the admin role small, and review the audit log after an incident.

## Worker and queue

The worker handles delivery jobs and queued cleanup:

```shell
php bin/worker.php          # loop
php bin/worker.php --once   # one batch, suitable for cron
```

Use one of these models:

- **systemd service** (recommended): the unit in
  [Installation](installation.md#6-worker-service), restarted automatically.
- **cron**: one `--once` batch per minute, for example
  `* * * * * aloha cd /srv/aloha && /usr/bin/php bin/worker.php --once`.

Do not run both a service and cron for the same queue. A backlog is visible in
worker output/journal; an empty queue makes the loop sleep instead of busy
spinning.

### Cleanup

`php bin/cleanup.php` queues a bounded retention job; `--now` runs it directly.
The default `all` cleanup also purges one bounded batch of technical error-log
rows older than `ALOHASOCIAL_RUNTIME_ERROR_RETENTION_DAYS` (90 days by default).
Use `--dry-run` to preview the matching error-log rows. Administrator audit
records are deliberately excluded and remain available for accountability.

```shell
php bin/cleanup.php --now --dry-run   # inspect without deleting
php bin/cleanup.php --now             # run the configured cleanup
php bin/cleanup.php --kind=all --limit=100
```

Keep a daily cron for cleanup, or let a monitoring task queue it. Media
retention and blob deletion are part of this job; do not delete files from
`var/blobs/` by hand while the app is running.

## Backups

Back up all state that is not reproducible from Git:

- the database (SQLite file or PostgreSQL dump)
- `var/secret.key` (or the `ALOHASOCIAL_SECRET_KEY` value)
- `var/installation.json` (binds the data directory to the database)
- `var/blobs/` (uploaded originals and thumbnails)
- `.env` (configuration, including SMTP and database secrets)
- optionally `var/mail/` if it contains messages you must retain

The update command creates and verifies a database backup automatically:

```shell
php bin/backup.php                 # var/backups/, 10 newest kept
php bin/backup.php --keep=30
php bin/backup.php --json          # machine-readable path and account count
php bin/backup.php --verify=FILE   # re-check an existing backup's checksum
php bin/backup.php --verify-manifest=FILE  # re-check blobs + DB against the manifest
```

SQLite backups use `VACUUM INTO`, so they are consistent while WAL is active.
PostgreSQL backups use `pg_dump` in custom format; install the matching client
on the host. Each backup has a `.json` sidecar with the driver, account count
and schema version, plus a SHA-256 checksum, the creating core version and a
fingerprint of the instance secret key. `--verify=FILE` recomputes the checksum
of an existing backup (a pre-checksum backup is reported as unverifiable, not
trusted) — run it when copying backups off-host or before a restore rehearsal.

Every backup also writes a `<name>.manifest.json` next to it: the full-instance
inventory — database checksum and driver, every media blob with size and (for
the local store) SHA-256, the secret-key fingerprint, and the core/schema
versions. It is the anchor for the encrypted off-host export and the restore
rehearsal; copy the `.sqlite`/`.dump`, `.json`, `.manifest.json` and your
secret key together. `--verify-manifest` re-hashes every recorded blob and the
database file against the manifest (read-only; S3 objects are reported as
unchecked). The backup directory lives inside `var/`, so move it to a
second disk or copy it off-host as part of your normal backup job.

### Encrypted off-host export

`bin/export.php` bundles the newest backup set, its manifest, the secret key
and every local media blob into one encrypted file (XChaCha20-Poly1305
secretstream, Argon2id key derivation):

```shell
export ALOHASOCIAL_EXPORT_PASSPHRASE='a long random passphrase'
php bin/export.php --output=/mnt/offhost/instance-$(date +%F).alohasocial-export
php bin/export.php --decrypt=instance-….alohasocial-export --output=/srv/restore-staging
```

The passphrase may also come from `--passphrase-file`; never put it in argv.
Store the passphrase separately from the export — together they are the whole
instance. Decryption writes the bundle contents (`backup/…`, `blobs/…`,
`secret.key`) into the target directory for a restore rehearsal; it does not
touch the running instance. Remote (S3) media is not included in the bundle —
copy the bucket separately.

`bin/restore.php` is the matching rehearsal tool: it decrypts the export into
a staging directory and runs a read-only preflight — manifest presence,
database checksum, every recorded blob hash, the secret-key fingerprint and
the instance origin — against this instance's configuration. A failed check
(for example a foreign key or origin) means the export does not belong on
this instance; nothing is written to the live database or blob store.

`--apply` turns the rehearsal into a real restore **onto an empty target**
(one with no accounts):

```shell
php bin/restore.php --export=instance-….alohasocial-export \
  --staging=/srv/restore-staging --apply
# verify the instance, then:
php bin/restore.php --confirm
```

Apply re-runs the full preflight, refuses populated targets and driver
mismatches, installs the database (SQLite file swap or `pg_restore
--clean` for PostgreSQL dumps), local blobs and — when the target has no
key yet — the exported `secret.key`. It then writes `var/restore.pending`,
which pauses the worker: no outbound federation or mail delivery and no
heartbeat until `--confirm` releases it, so nothing federates from
unverified restored state. Restoring over a populated instance is
intentionally unsupported — there is no merge path.

The key and the database belong together: the instance signing key in the
database is sealed with the secret key. Restoring one without the other leaves
the instance unable to use its federation identity.

### Scheduled backups

`bin/backup.php` is safe to run from cron while the instance serves traffic
(SQLite uses `VACUUM INTO`, PostgreSQL shells out to `pg_dump`). A daily
schedule keeping the newest 30 snapshots is a reasonable default:

```cron
# /etc/cron.d/aloha-backup — database snapshot + manifest, 30 kept
17 3 * * * aloha cd /srv/aloha && php bin/backup.php --keep=30 >> /var/log/aloha-backup.log 2>&1
```

Pair it with a weekly `bin/export.php` to off-host storage for the encrypted
full-instance bundle, and run `bin/backup.php --verify-manifest` on a copy
before trusting a snapshot in an incident. `bin/update.php` also takes a
verified backup before every migration.

### SQLite

Use SQLite's backup command rather than copying a live database file:

```shell
sudo -u aloha sqlite3 /srv/aloha/var/server.sqlite \
  ".backup '/srv/backups/server-$(date +%F).sqlite'"
```

Then archive the blobs, key and installation identity:

```shell
sudo tar -C /srv/aloha -czf /srv/backups/aloha-files-$(date +%F).tgz \
  var/blobs var/secret.key var/installation.json .env
```

If `ALOHASOCIAL_SECRET_KEY` is supplied through the environment, store it in
your secret manager; it is not in the files above.

### PostgreSQL

```shell
sudo -u postgres pg_dump -Fc aloha > /srv/backups/aloha-$(date +%F).dump
sudo tar -C /srv/aloha -czf /srv/backups/aloha-files-$(date +%F).tgz \
  var/blobs var/secret.key var/installation.json .env
```

Keep at least one off-host copy, encrypt backups at rest, and test a restore
on a separate host. A backup that has never been restored is only a hope.

### Restore checklist

For an export bundle, prefer the guarded path: `bin/restore.php --apply`
onto an empty target installs database, blobs and key, then holds the
worker until `--confirm`. The manual path below remains for bare database
dumps:

1. Stop the worker and PHP-FPM (or put the site in maintenance mode).
2. Restore the database.
3. Restore `var/secret.key`, `var/installation.json`, `var/blobs/` and `.env`
   from the same backup with the same ownership and permissions.
4. Run `php bin/doctor.php`; if a migration is pending, run
   `php bin/update.php`.
5. Start PHP-FPM and the worker.
6. Verify `/healthz` (liveness) and `/readyz` (database, schema, worker and queue), sign in, load `/app`, and open an existing image.
7. Remove or rotate any credentials that were exposed during the incident.

## Upgrades

```shell
sudo systemctl stop aloha-worker
# Optional but recommended: an additional off-host copy of var/ and .env.
cd /srv/aloha
git pull --ff-only
composer install --no-dev --classmap-authoritative
php bin/update.php                 # backup, migrate, account check
# Only when the UI changed (dev checkout; the committed bundle is enough
# for a normal deployment). ui/shared-ui/ is committed, no Core checkout needed:
npm --prefix ui ci
npm --prefix ui run build
sudo systemctl restart php8.3-fpm
sudo systemctl start aloha-worker
php bin/doctor.php                 # schema current, key present, paths writable
```

`bin/update.php` writes a verified backup before applying migrations, then
compares the account count. If the backup fails it refuses to migrate; if the
count drops it prints the restore command. `bin/migrate.php` remains the
low-level migration runner for development and tests, but the update command
is the supported operator path.

Migrations are serialized across processes: PostgreSQL takes a session
advisory lock, MySQL a named `GET_LOCK`, and SQLite runs the whole pending
set inside one write transaction (which also makes the batch atomic). A
second migrator started while another holds the lock fails fast with a
"database is locked"/"another migration is running" error instead of
interleaving DDL; simply retry once the first run finishes. The web app and
worker never migrate on boot — they refuse to serve while `schema_migration`
lags `Migrations::all()`, so an upgrade in progress is visible via
`php bin/doctor.php` rather than half-applied state.

`bin/update.php` also runs preflight checks before the safety backup:
warnings for a missing/invalid secret key on populated installs, an
unwritable blob directory or low disk space; an unwritable data directory
stops the run. Each applied `schema_migration` row records the Core
version and commit reference that ran it (`core_version`,
`core_reference`; NULL for rows applied before provenance tracking), so a
post-upgrade `SELECT version, core_version, core_reference FROM
schema_migration ORDER BY version DESC LIMIT 5` shows exactly which build
produced the current schema.

### Interrupted upgrades

An interrupted `bin/update.php` run is recoverable in every supported state:

- **Killed before or during the backup**: nothing changed; rerun the command.
- **Killed during migrations**: the migration lock is released with the
  process, and each migration commits inside its own transaction on
  PostgreSQL/SQLite (on SQLite the whole pending batch is one transaction,
  so it is all-or-nothing). `schema_migration` therefore never claims a
  version whose DDL rolled back. Rerun `php bin/update.php`; already-applied
  versions are skipped by checksum.
- **Migration reported failure**: the output names the failing version and
  the backup path. Do not retry blindly — inspect with `php bin/doctor.php`
  and the database, and restore the backup if `schema_migration` and the
  live schema disagree (the update command already verifies the account
  count and prints the restore command when it drops).
- **App or worker booting mid-upgrade**: they refuse to serve while
  `schema_migration` lags the code's `Migrations::all()` — requests fail
  fast instead of touching missing tables. Finish the upgrade, or roll the
  code back to the version matching the applied schema.

`php bin/doctor.php` is the single place to check `schema_installed`,
`schema_version`, `pending_migrations`, `core_version` and
`core_reference` before and after an upgrade.

### Dead queue jobs and federation delivery failures

When `/readyz` reports `queue: warn` with `dead > 0`, diagnose before
touching anything:

1. Open the administration queue (`/admin` → Queue, or the app's admin
   section). Each job row shows type, state, attempts, availability, the
   sanitized `last_error` (`ExceptionClass: message`, never payloads or
   credentials) and, for dead jobs, when it died.
2. Match the failure to its cause: `remote_delivery_unreachable` or HTTP
   4xx/5xx in `last_error` points at the remote inbox or network; a local
   exception class points at this instance. A single historical dead job
   does not make the service unhealthy — `/readyz` stays `ok` while
   pending/running queues drain.
3. Retry **one** dead job at a time from the queue view (audited as
   `job.retry`). Only dead jobs are retryable; pending and running jobs
   are refused because there is nothing to recover. Bulk "retry dead" is
   available for correlated outages (for example after the remote instance
   comes back), is audited as `job.retry_all`, and re-checks job
   applicability when the handler runs — an obsolete delivery whose
   subject was deleted or cancelled is skipped by the handler, not
   replayed blindly.
4. Verify the fix end-to-end: the retried job leaves `dead`, `attempts`
   resets, and the remote side shows the activity. If it dies again with
   the same `last_error`, the cause is still present — do not keep
   retrying; fix the remote reachability, signature clock or payload
   problem first.

Never regenerate keys, reset the installation or wipe `social_job` to fix
a delivery failure: the durable queue and replay log exist precisely so a
dead job can be inspected and retried safely.

### Core release upgrade and rollback

For a tagged Core release, select **Release** and the exact Core version in
the admin settings, then run `php bin/core-version.php` on a bare-metal
installation. Review the resulting `composer.json` and `composer.lock` before
deploying; they record the version and commit that will be installed. In
Docker, set the release version in the build configuration and rebuild the
image instead of changing `vendor/` in a running container. Keep the previous
image or code release and both Composer files until the upgrade is verified.

Before a production upgrade, make and verify an off-host backup of the
database, `.env`, `var/secret.key` and `var/blobs/`. Stop the worker, deploy
the new code/image, install its locked dependencies, run `php bin/update.php`,
and check `php bin/doctor.php`, `/readyz`, sign-in, the feed and existing media.
`bin/update.php` also creates its own database backup before applying pending
migrations when accounts exist.

If verification fails, stop the web and worker processes, restore the database
and files from the same pre-upgrade backup, restore the previous code/image and
its `composer.json`/`composer.lock`, then restart and run `php bin/doctor.php`.
Do not run the previous Core against a database migrated by the failed
upgrade: the schema fingerprint deliberately refuses that mixed state. If the
database backup cannot be restored, keep the instance stopped and repair the
new release forward rather than mixing code and schema versions.

Read the migration output. The server refuses to boot with a pending
migration rather than failing on the first request that touches a missing
table. Restart PHP-FPM after code changes so opcache picks them up.

### Keeping accounts across code changes

An account consists of rows in the database plus the instance key. Neither
lives in Git, but both can be lost by moving or deleting the wrong directory:

- Keep `ALOHASOCIAL_VAR_DIRECTORY` (and the blob, key and mail paths) outside
  the release directory. `scripts/install.sh --data-dir=/srv/aloha-data` sets
  all four paths. A new checkout, a `git clean -fdx` or a replaced release
  directory then cannot touch the running data.
- In Docker, never run `docker compose down -v` on an instance you want to
  keep: `-v` deletes the named volumes, including accounts and media. Plain
  `docker compose down` and `docker compose up -d` keep them.
- Do not delete `var/secret.key` while the database still contains accounts;
  the sealed instance key becomes unreadable and federation identity is lost.
- Keep `var/installation.json` with the database. It records the installation
  id, public origin and signing-key fingerprint. A mismatched pair, wrong key
  or changed origin on a populated instance stops boot with a readable error.
- Run `php bin/doctor.php` after every update. It reports account count,
  pending migrations, key validity, writable database, blob and mail paths
  and the newest backup without printing secrets.

The core is a pinned Composer dependency. Update it deliberately, review the
changelog, and run `composer test` before deploying if you have a development
checkout with dev dependencies installed.

## Monitoring

- **Liveness:** `GET /healthz` returns without touching the database. It
  answers even if the schema is missing, so do not use it as a full readiness
  check.
- **Readiness:** `GET /api/v1/instance` exercises the database and the core
  assembly. `GET /app` checks that the mount page is served.
- **Worker:** check `systemctl status aloha-worker` or the cron mail. The worker
  writes a heartbeat to `runtime_state` (`worker`) every loop and in
  `--once`; **Administration → Overview** and **Instance** show it as online
  when it is younger than 90 seconds. A growing job queue means delivery or
  cleanup is not making progress.
- **Disk:** watch `var/blobs/`, database size and backup storage. Uploads and
  federation media are the usual growth sources.
- **Logs:** PHP-FPM/error log for web errors, worker journal for jobs. Keep
  `ALOHASOCIAL_DEBUG=false` in production. Boot failures stay generic in web responses; use the error log or `php bin/doctor.php` for diagnostic details.
- **TLS and proxy:** monitor certificate expiry and make sure the public
  origin matches `ALOHASOCIAL_INSTANCE_URL`.

The unread-notification badge is polled by the UI every 60 seconds. It is a
convenience, not a monitoring signal.

### Prometheus metrics

`GET /metrics` serves the Prometheus text exposition format (`0.0.4`). The
endpoint is **disabled when `ALOHASOCIAL_METRICS_TOKEN` is empty** — it
answers 404 so scrapers cannot probe for it. Set the token and either send
`Authorization: Bearer <token>` from the scraper or sign in as an admin;
requests without a credential get 401.

Expose it to the monitoring stack only, not to the public internet — the
bearer token authenticates, but a public scrape URL still leaks endpoint
existence to scanners. Restrict by network (firewall, reverse-proxy ACL on
the `/metrics` location) or keep it on an internal listener.

Series provided:

- `aloha_http_requests_total{method,route,status}` and
  `aloha_http_request_duration_seconds{method,route}` — per-route counters
  and latency histograms. Route labels are templated (`/api/v1/posts/{id}`)
  so high-cardinality paths do not explode the series count.
- `aloha_queue_jobs_pending`, `aloha_queue_jobs_running`,
  `aloha_queue_jobs_dead` — `social_job` backlog; `dead` should be zero.
- `aloha_queue_oldest_pending_age_seconds` — age of the oldest job already
  due but not yet reserved. Growth means the worker is stalled or
  backlogged even while `pending` looks small.
- `aloha_storage_free_bytes`, `aloha_storage_total_bytes` — local blob
  disk headroom (omitted for S3-backed storage, which reports its own
  metrics). Alert below ~10% free.
- `aloha_mail_outbox_pending`, `aloha_mail_outbox_failed` — queued mail;
  `failed` grows only when SMTP is unreachable past the retry budget.
- `aloha_worker_heartbeat_age_seconds` — seconds since the worker's last
  heartbeat. Alert when above ~90 seconds (the UI's "online" threshold).
- `aloha_push_delivery_pending`, `aloha_engagement_refresh_pending` —
  queued Web Push deliveries and remote engagement refreshes waiting for
  the worker.
- `aloha_atproto_sync_last_attempt_age_seconds{source}`,
  `aloha_atproto_sync_stale{source}`, `aloha_atproto_sync_error{source}` —
  per-source inbound sync health (#170). `stale=1` means cached remote
  content is being served past its freshness window; `error=1` means the
  last poll attempt failed. `last_attempt_age` climbing while `stale=0`
  means sync is merely idle.
- `aloha_metrics_collection_errors` — non-zero when a scrape-time gauge
  could not be read (database hiccup); the HTTP metrics still render.

Suggested alerts: `aloha_queue_jobs_dead > 0` for longer than one worker
tick, `aloha_queue_oldest_pending_age_seconds > 300` for 10 minutes,
`aloha_worker_heartbeat_age_seconds > 90` for 5 minutes,
`aloha_storage_free_bytes / aloha_storage_total_bytes < 0.1` for 15
minutes,
`aloha_atproto_sync_stale == 1` or `aloha_atproto_sync_error == 1` for
10 minutes (per source), `aloha_push_delivery_pending` growing for an
hour, `aloha_http_request_duration_seconds` p99 above a few seconds
sustained.

## Verifying federation end-to-end

When a remote Like, reply, follow or boost fails, work through this
checklist before changing configuration:

1. **Discovery from outside.** From a shell that is *not* the server:
   `curl -s 'https://your.domain/.well-known/webfinger?resource=acct:alice@your.domain'`
   must return the actor URL; `curl -sI https://your.domain/nodeinfo/2.0`
   must answer 200. If these fail, the problem is DNS, TLS or the reverse
   proxy — federation peers cannot even find the instance. Check that the
   proxy forwards `Host` unchanged and does not cache `/.well-known/*` or
   `/users/*` responses.
2. **Local health.** `php bin/doctor.php` reports `core_version` and
   `core_reference` (the exact Core build), `schema_version`,
   `pending_migrations`, `worker` heartbeat and `secret_key_valid`. A
   pending migration or invalid key breaks signing and delivery even when
   pages load.
3. **Queue state.** `/readyz` distinguishes liveness from a degraded
   queue (`dead > 0` is `warn`, not `fail`). In the admin queue view, the
   `last_error` column names the sanitized failure —
   `remote_delivery_unreachable` means DNS/TLS/inbox reachability,
   HTTP 4xx/5xx labels mean the remote rejected or is down, local
   exception classes mean this instance. See "Dead queue jobs and
   federation delivery failures" above for the retry workflow.
4. **Full round trip in a disposable environment.**
   `php bin/federation-smoke.php` boots two real servers with separate
   databases, keys and blob stores and runs a signed follow → accept →
   post → remote delivery → like → notification round trip through the
   production code path (no mocks). It exits non-zero with the failing
   step named; `FEDERATION SMOKE PASS` means discovery, signatures,
   inbox, queue and worker all work on this build. Run it after Core or
   proxy changes, not against production data.
5. **Version match.** `php bin/doctor.php` prints `core_version` and
   `core_reference` — the exact Core build serving requests. When
   reporting a federation bug, include both plus the deployed UI bundle
   (`public/ui`): behaviour differs between Core builds and a stale UI
   bundle can hide working backend fixes.

## Troubleshooting

| Symptom | Likely cause | Action |
| --- | --- | --- |
| `The database schema is not installed` or `Pending migrations` | Migrations were skipped or a new version was deployed without migrating. | Run `php bin/update.php`; inspect output; restart PHP-FPM/worker. |
| Accounts seem to be gone after an update | Wrong data directory, a lost Docker volume, or a release directory that was replaced. | Stop services; check `php bin/doctor.php` for the database path; restore the newest `var/backups/*.sqlite` or the PostgreSQL dump with `var/secret.key` and `var/installation.json`; set `--data-dir` for the next install. Never use `docker compose down -v`. |
| `The data directory and database belong to different installations` | A new deployment attached an existing `var/` volume to another database, or only half of a backup was restored. | Restore `var/installation.json` and the database from the same backup, or start the new instance with an empty data directory. Do not merge the two installations. |
| `This populated installation is missing var/installation.json` | A worker or new app was started with an empty data volume while using the existing database. | Restore the file from the database's backup and mount the correct volume for every service. Do not recreate the file by hand. |
| `Changing the public origin on a populated instance` | `ALOHASOCIAL_INSTANCE_URL` no longer matches the origin recorded by setup. | Restore the old origin. Actor ids and WebFinger subjects derive from it; changing it after accounts exist is not supported. Use a new instance with an empty volume for a different domain. |
| `The instance signing key does not match this installation identity` | `var/secret.key`, `instance_key` and the identity record came from different backups. | Restore the matching database, secret key and installation file as one set. Do not regenerate the key on a populated instance. |
| `Migration stopped: the safety backup could not be created` | `var/backups` is not writable, or `pg_dump` is missing/incompatible. | Fix permissions or install the matching PostgreSQL client; run `php bin/backup.php`; only then retry. `--allow-unbacked` is a last resort. |
| `/healthz` is green but pages return 500 | Database, key or schema problem. | Check the PHP error log and run `php bin/doctor.php`; do not enable debug mode on a public instance. |
| Browser shows **Server is not ready** | Boot failed, usually a pending migration, missing schema or an invalid secret key. | Run `php bin/doctor.php`, then `php bin/update.php`; the public page stays generic while the PHP log and doctor output give the cause. Restart PHP-FPM/worker. |
| Web setup is locked | The one-time token is missing, expired or was already used. | On an empty instance run `php bin/setup-token.php`; on an installed instance, use `php bin/reset.php` only if you deliberately want to wipe it. Read `var/setup.token` as the application user. |
| Federation links still use the old domain after web setup | Long-running FPM workers or the worker still hold the old environment. | Restart PHP-FPM and the worker after setup; verify `/api/v1/instance` reports the new domain. Changing the domain after accounts exist is not supported. |
| Login succeeds but the next request is signed out | Origin mismatch or secure-cookie policy. | Use the exact `ALOHASOCIAL_INSTANCE_URL` origin; use HTTPS in production; check proxy/forwarding. |
| `csrf_token_invalid` from the UI | The page has no CSRF token or the session changed. | Reload `/app`; ensure cookies are enabled; check that the app is served from the same origin as the API. |
| Upload returns `413` | PHP or web-server body limit is too small. | Raise `upload_max_filesize`, `post_max_size` and the Nginx/Apache body limit. |
| Uploaded image, video or audio is missing after reload | Blobs are not readable by PHP, or the instance URL/media origin is wrong. | Check `var/blobs/` permissions, `ALOHASOCIAL_BLOB_DIRECTORY`, and media URLs in `/api/v1/instance`/post responses; video and audio have no generated thumbnail, so the player loads the original. |
| A video upload returns `413` although the file is under 20 MiB | `post_max_size` or the web-server body limit is below the multi-file total. | Raise `upload_max_filesize`, `post_max_size` and the Nginx/Apache body limit as described in [Configuration](configuration.md). |
| Blank `/app` page | Missing bundle, CSP rejection, or a failed API request. | Open the browser console/network panel; check `/ui/app.js` and `/ui/app.css` return 200; rebuild `public/ui/`; inspect the CSP response header. |
| `429 Too Many Requests` | A declared per-route rate limit was hit. | Respect `Retry-After`; do not disable limits. Check for loops or scrapers. The counter is reserved atomically in the database, so several PHP workers share one exact budget. A database outage fails closed (the request errors); it never admits an unbounded request. |
| Password reset mail missing | `log` transport is active or SMTP is misconfigured. | Look in `var/mail/` for development; check SMTP host, port, TLS and credentials for production. |
| Worker exits immediately | Boot/schema/config error. | Run `php bin/worker.php --once` in the foreground and read the error. |
| Federation delivery is delayed | Worker is not running or SMTP/HTTP outbound access is blocked. | Start the worker; inspect `journalctl`; check DNS and firewall egress. |
| A remote instance cannot find the handle or posts | The public origin is wrong, `/.well-known/webfinger` or the post URLs are not served through PHP, or the worker never delivered the post. | Verify `ALOHASOCIAL_INSTANCE_URL` is the public HTTPS origin; check WebFinger, the actor, `/api/v1/posts/{id}` with an ActivityPub `Accept`, and the activity URL; inspect the job queue and worker heartbeat. |
| Posts exist locally but never arrive on a follower's instance | No follower edge was accepted, or federation jobs are still pending/dead. | Check **Administration → Federation** and **Errors**, then run `php bin/worker.php --once`; confirm the peer can reach the public inbox. |
| Login page loads but styling is stale after deploy | Browser or opcache served an old build. | Rebuild `public/ui/`, reload with cache disabled, restart PHP-FPM. |

For a failure that is not listed here, reproduce it with the relevant CLI
command first (`migrate`, `worker --once`, `routes`) and read the first error
line. The server fails loudly during boot by design.

## Security checklist

- Serve only over HTTPS and set `ALOHASOCIAL_SECURE_COOKIES=true`.
- Keep `ALOHASOCIAL_DEBUG=false` in production.
- Keep `.env`, `var/secret.key`, the database and backups out of the web root
  and out of Git.
- Run PHP-FPM and the worker as a dedicated, unprivileged user.
- Use a separate database role with only the rights it needs.
- Keep SMTP credentials and the instance secret in a secret manager or a
  tightly permissioned file.
- Restrict SSH and database access; do not expose PostgreSQL to the internet.
- Keep the worker and PHP dependencies patched; subscribe to core releases.
- Review the rate limits and proxy logs after abuse reports.
- Test the restore procedure, not just the backup command.

## Related documents

- [Installation and setup](installation.md)
- [Configuration reference](configuration.md)
- [Frontend hosting decision](frontend.md)
- [Project README](../README.md)

## Monitoring and alert thresholds

`GET /healthz` returns `200` only when the database is reachable and the schema
is current; any other answer is `503` with the failing check named. The admin
overview shows the same checks plus queue depth, the oldest pending job and the
worker heartbeat.

Suggested thresholds for an external monitor:

| Signal | Warning | Critical |
| --- | --- | --- |
| `/healthz` status | non-200 for 2 minutes | non-200 for 5 minutes |
| Queue pending | > 100 for 10 minutes | > 1000 or oldest job > 30 minutes |
| Dead jobs | any new dead job | > 10 dead jobs |
| Worker heartbeat | older than 2 minutes | older than 10 minutes |
| ATProto stream lag | > 1 hour | > 24 hours (`bin/atproto-status.php`) |
| Federation failures | > 5 in 15 minutes | > 50 in 15 minutes |

Alert on the dead-job count and the worker heartbeat, not only on HTTP: a
worker that stopped still serves pages, so liveness alone hides a stalled
federation and a growing outbox.
