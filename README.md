# Aloha Social server

The standalone host for [`alohasocial/core`](https://github.com/SchBenedikt/core).
It turns the host-neutral core into a running instance: accounts, sessions,
login and CSRF, the database and its migrations, the instance key, the HTTP
entry point and the worker.

The core owns the social behaviour and the route manifest; this repository
owns everything a process needs. Built against
[the server blueprint](https://github.com/SchBenedikt/core/blob/main/docs/guides/server-architecture.md)
and [the standalone host checklist](https://github.com/SchBenedikt/core/blob/main/docs/operations/standalone-host.md).

**Status: phases 0–3 of the blueprint** — the "server exists" milestone.

| Phase | Delivered | Check |
| --- | --- | --- |
| 0 | Composer project, core as a VCS dependency, `public/index.php` | `/healthz` answers, `composer check` (PHPUnit) green |
| 1 | Config, PDO, migration runner, every core schema | fresh install creates the tables, a second run is a no-op |
| 2 | Accounts, sessions, login, CSRF, `LocalActorDirectory` | login/logout, session rotation, CSRF rejection |
| 3 | `Application::forHost()` and route parity | served routes equal `Routes::manifest()`; a post is written and read over HTTP |
| 3b | Host hardening: declared rate limits, media upload/serving, cleanup job, password CLI | 429 with `Retry-After`; image upload and fetch; worker drains `cleanup` |

Not implemented yet (later blueprint phases): federation smoke tests with a
real peer, AT Protocol account linking, the shared Vue UI mount,
installer/container and password reset by email (the CLI resets a password;
sending mail needs a transport this server does not ship).

## Requirements

- PHP 8.3 or newer with `pdo_sqlite` or `pdo_pgsql`, `gd`, `mbstring`,
  `curl`, `openssl` and `json`
- `upload_max_filesize` and `post_max_size` at least the media limit (5 MiB
  by default); PHP rejects a larger upload before the application sees it
- Composer 2
- PostgreSQL (preferred for a real instance) or SQLite (small installs, tests)

## Quick start

```shell
composer install
cp .env.example .env          # adjust the instance URL and database
php bin/migrate.php           # creates the core and host schema
php bin/create-account.php alice alice@example.com --admin
php -S 127.0.0.1:3000 -t public public/index.php
```

Open `http://127.0.0.1:3000/`, sign in, and the dashboard links the actor
document, `/api/v1/instance` and the public timeline.

For a production instance, point the web server at `public/` and run PHP-FPM;
serve every request through `public/index.php` (the core route manifest lives
at the root, including `/@handle` and `/.well-known/webfinger`).

### Worker and maintenance

```shell
php bin/worker.php            # supervised loop, SIGTERM stops between batches
php bin/worker.php --once     # one batch, for cron
php bin/cleanup.php           # queue the bounded retention job
php bin/cleanup.php --now     # run it once, without the queue
php bin/set-password.php bob  # change a password and revoke its sessions
```

Cron alternatives:

```cron
* * * * * php /srv/aloha/bin/worker.php --once
0 3 * * * php /srv/aloha/bin/cleanup.php
```

### Route parity

`php bin/routes.php` prints the routes the booted server actually serves. The
parity test compares them with the core's manifest, so a missing or renamed
route fails the build instead of showing up in production.

## Configuration

Precedence: defaults < `config/server.php` < environment (and `.env`).
`.env` is never committed.

| Variable | Default | Meaning |
| --- | --- | --- |
| `ALOHASOCIAL_INSTANCE_URL` | `http://localhost:3000` | Public origin; actor ids and WebFinger derive from it |
| `ALOHASOCIAL_INSTANCE_NAME` | `Aloha Social` | Display name |
| `ALOHASOCIAL_INSTANCE_DESCRIPTION` | empty | Public description |
| `ALOHASOCIAL_CONTACT_EMAIL` | empty | Public admin contact |
| `ALOHASOCIAL_DB_DSN` | `sqlite:var/server.sqlite` | PDO DSN (`pgsql:host=…;dbname=…` for PostgreSQL) |
| `ALOHASOCIAL_DB_USER` / `ALOHASOCIAL_DB_PASSWORD` | empty | Credentials |
| `ALOHASOCIAL_SECRET_KEY` | empty | Base64 32-byte key for the secret box |
| `ALOHASOCIAL_SECRET_KEY_FILE` | `var/secret.key` | Generated on first boot when the env key is empty |
| `ALOHASOCIAL_VAR_DIRECTORY` | `var` | Runtime state (SQLite file, key) |
| `ALOHASOCIAL_BLOB_DIRECTORY` | `var/blobs` | Media originals and thumbnails |
| `ALOHASOCIAL_SECURE_COOKIES` | on for https | `Secure` flag on the session cookie |
| `ALOHASOCIAL_SESSION_LIFETIME` | `604800` | Session lifetime in seconds |
| `ALOHASOCIAL_REMOTE_MEDIA` | `true` | `social/media.remote_fetch` |
| `ALOHASOCIAL_MASTODON_API` | `false` | Enables the compatibility prefix |
| `ALOHASOCIAL_MASTODON_PREFIX` | `/api/mastodon/v1` | Prefix for the compatibility routes |
| `ALOHASOCIAL_DEBUG` | `false` | Debug messages in error responses; never enable in production |

Secrets do not belong in `config/server.php` or in the repository. The instance
private key is sealed with `OpenSslSecretBox` and stored in `instance_key`; the
encryption key stays in the environment or in `var/secret.key` (`0600`).

## Architecture

```
public/index.php          one entry point: boot, authenticate, dispatch
bin/migrate.php           migration runner
bin/worker.php            core worker loop (`runOnce`)
bin/cleanup.php           queue or run the bounded retention job
bin/create-account.php    account creation (installer step, CLI)
bin/set-password.php      password reset by CLI, revokes the account's sessions
bin/routes.php            served route manifest

src/Bootstrap/            boot sequence 1-9: config, PDO, keys, ports, assembly
src/Config/               host config + the `social/*` config the core reads
src/Db/                   PDO factory (sqlite/pgsql)
src/Migrations/           schema_migration runner + core schema adoption
src/Accounts/             account table, core LocalActorDirectory/Search/Count
src/Auth/                 sessions, rotation, CSRF, password hashing, throttle
src/Http/                 plain-PHP views, front controller, rate limiter, globals adapter
resources/views/          login, dashboard, error pages
tests/                    migrations, auth, rate limits, media, worker and route parity
```

Request flow:

```
Browser → public/index.php → HostConfig → ApplicationFactory::boot()
        → FrontController (session cookie → Request::$userId, CSRF)
        → /, /login, /logout  (host pages)
        → $app->kernel->handle(Request)   (the core's 84 routes)
```

The core is a Composer VCS dependency pinned to the `0.7.x` line
(`src/Version.php` is the authority). Nothing from the core is copied here.

## Design notes

- **No framework, no ORM.** PDO plus the core's ports, as the blueprint
  requires. `PdoConnection` implements both `Connection` and
  `TransactionRunner`, so post writes and their side rows are atomic.
- **Accounts are host-owned** (ADR 0006). The core only receives
  `Request::$userId`; the table is named `account` instead of `user` because
  `user` is reserved in PostgreSQL.
- **The account id is the handle**, because `ActivityFactory::actorId()` builds
  the public actor URL from it (`/@alice`). Handles are lower-case and immutable
  in this slice.
- **Sessions** are random 32-byte values stored as SHA-256 hashes. The session
  id rotates on login (fixation), cookies are `HttpOnly`, `SameSite=Lax` and
  `Secure` on https. Five failed logins per handle and address in fifteen
  minutes are tolerated.
- **CSRF** is enforced for host forms and for every mutating core route when
  the caller is authenticated by a session cookie. The core's JSON API clients
  that send `X-CSRF-Token` work unchanged; peer-to-peer `/inbox` requests carry
  no session and are authenticated by HTTP signatures instead.
- **Rate limits** are the ones the core declares per route (`30/900` and so
  on). The host enforces them per account, or per client address for anonymous
  requests, and answers `429` with `Retry-After`.
- **Migrations** record version, timestamp and checksum. Editing an applied
  migration is refused; add a new one. The server refuses to boot with a
  pending migration instead of failing on the first request that needs it.
- **Relative paths** in the configuration resolve against the project root, so
  `sqlite:var/server.sqlite` and `var/blobs` behave the same under PHP-FPM
  (working directory `public/`) and in the CLI.

## Tests

```shell
composer test
```

The suite boots the real `Application::forHost()` assembly on a throwaway
SQLite database and drives the real front controller: migrations twice,
login/rotation/CSRF/logout/throttle, route budgets, a post written and read
over HTTP, media upload/serving/attachment, actor document and WebFinger, the
cleanup job through the worker, and route parity against the core manifest.

The PostgreSQL path is exercised separately: migrations twice, account
creation, login, post write/read, media upload/serve and WebFinger against a
real PostgreSQL server. The automated suite stays on SQLite so it runs without
a database service.

## License

AGPL-3.0-or-later, like the core.
