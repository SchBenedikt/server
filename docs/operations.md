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
```

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
hash of a single-use token, sends a link that expires after one hour, and a
completed reset revokes every session.

Development (`ALOHASOCIAL_MAIL_TRANSPORT=log`):

```shell
ls var/mail/
# open the newest .eml and use the reset link
```

Production should use SMTP. If delivery fails, the endpoint answers `503`; fix
the SMTP configuration instead of lowering the transport to `log` on a public
instance.

To reset a password without email:

```shell
php bin/set-password.php alice
```

This also revokes all existing sessions for the account.

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
- `var/blobs/` (uploaded originals and thumbnails)
- `.env` (configuration, including SMTP and database secrets)
- optionally `var/mail/` if it contains messages you must retain

The key and the database belong together: the instance signing key in the
database is sealed with the secret key. Restoring one without the other leaves
the instance unable to use its federation identity.

### SQLite

Use SQLite's backup command rather than copying a live database file:

```shell
sudo -u aloha sqlite3 /srv/aloha/var/server.sqlite \
  ".backup '/srv/backups/server-$(date +%F).sqlite'"
```

Then archive the blobs and key:

```shell
sudo tar -C /srv/aloha -czf /srv/backups/aloha-files-$(date +%F).tgz \
  var/blobs var/secret.key .env
```

If `ALOHASOCIAL_SECRET_KEY` is supplied through the environment, store it in
your secret manager; it is not in the files above.

### PostgreSQL

```shell
sudo -u postgres pg_dump -Fc aloha > /srv/backups/aloha-$(date +%F).dump
sudo tar -C /srv/aloha -czf /srv/backups/aloha-files-$(date +%F).tgz \
  var/blobs var/secret.key .env
```

Keep at least one off-host copy, encrypt backups at rest, and test a restore
on a separate host. A backup that has never been restored is only a hope.

### Restore checklist

1. Stop the worker and PHP-FPM (or put the site in maintenance mode).
2. Restore the database.
3. Restore `var/secret.key`, `var/blobs/` and `.env` with the same ownership
   and permissions.
4. Run `php bin/migrate.php` to confirm the schema is current.
5. Start PHP-FPM and the worker.
6. Verify `/healthz`, sign in, load `/app`, and open an existing image.
7. Remove or rotate any credentials that were exposed during the incident.

## Upgrades

```shell
sudo systemctl stop aloha-worker
# back up database, key and blobs
cd /srv/aloha
git pull --ff-only
composer install --no-dev --classmap-authoritative
php bin/migrate.php
# Only when the UI changed:
npm --prefix ui ci
npm --prefix ui run build
sudo systemctl restart php8.3-fpm
sudo systemctl start aloha-worker
```

Read the migration output. The server refuses to boot with a pending
migration rather than failing on the first request that touches a missing
table. Restart PHP-FPM after code changes so opcache picks them up.

The core is a pinned Composer dependency. Update it deliberately, review the
changelog, and run `composer test` before deploying if you have a development
checkout with dev dependencies installed.

## Monitoring

- **Liveness:** `GET /healthz` returns without touching the database. It
  answers even if the schema is missing, so do not use it as a full readiness
  check.
- **Readiness:** `GET /api/v1/instance` exercises the database and the core
  assembly. `GET /app` checks that the mount page is served.
- **Worker:** check `systemctl status aloha-worker` or the cron mail. A growing
  job queue means delivery or cleanup is not making progress.
- **Disk:** watch `var/blobs/`, database size and backup storage. Uploads and
  federation media are the usual growth sources.
- **Logs:** PHP-FPM/error log for web errors, worker journal for jobs. Keep
  `ALOHASOCIAL_DEBUG=false` in production; enable it only while diagnosing.
- **TLS and proxy:** monitor certificate expiry and make sure the public
  origin matches `ALOHASOCIAL_INSTANCE_URL`.

The unread-notification badge is polled by the UI every 60 seconds. It is a
convenience, not a monitoring signal.

## Troubleshooting

| Symptom | Likely cause | Action |
| --- | --- | --- |
| `The database schema is not installed` or `Pending migrations` | Migrations were skipped or a new version was deployed without migrating. | Run `php bin/migrate.php`; inspect output; restart PHP-FPM/worker. |
| `/healthz` is green but pages return 500 | Database, key or schema problem. | Check the PHP error log; set `ALOHASOCIAL_DEBUG=true` temporarily. |
| Login succeeds but the next request is signed out | Origin mismatch or secure-cookie policy. | Use the exact `ALOHASOCIAL_INSTANCE_URL` origin; use HTTPS in production; check proxy/forwarding. |
| `csrf_token_invalid` from the UI | The page has no CSRF token or the session changed. | Reload `/app`; ensure cookies are enabled; check that the app is served from the same origin as the API. |
| Upload returns `413` | PHP or web-server body limit is too small. | Raise `upload_max_filesize`, `post_max_size` and the Nginx/Apache body limit. |
| Uploaded image is missing after reload | Blobs are not readable by PHP, or the instance URL/media origin is wrong. | Check `var/blobs/` permissions, `ALOHASOCIAL_BLOB_DIRECTORY`, and media URLs in `/api/v1/instance`/post responses. |
| Blank `/app` page | Missing bundle, CSP rejection, or a failed API request. | Open the browser console/network panel; check `/ui/app.js` and `/ui/app.css` return 200; rebuild `public/ui/`; inspect the CSP response header. |
| `429 Too Many Requests` | A declared per-route rate limit was hit. | Respect `Retry-After`; do not disable limits. Check for loops or scrapers. |
| Password reset mail missing | `log` transport is active or SMTP is misconfigured. | Look in `var/mail/` for development; check SMTP host, port, TLS and credentials for production. |
| Worker exits immediately | Boot/schema/config error. | Run `php bin/worker.php --once` in the foreground and read the error. |
| Federation delivery is delayed | Worker is not running or SMTP/HTTP outbound access is blocked. | Start the worker; inspect `journalctl`; check DNS and firewall egress. |
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
