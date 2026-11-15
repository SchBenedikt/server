# Aloha Social server

The standalone host for [`alohasocial/core`](https://github.com/SchBenedikt/core).
It turns the host-neutral core into a running instance: accounts, sessions,
login and CSRF, the database and its migrations, the instance key, the HTTP
entry point and the worker.

The core owns the social behaviour and the route manifest; this repository
owns everything a process needs. Built against
[the server blueprint](https://github.com/SchBenedikt/core/blob/main/docs/guides/server-architecture.md)
and [the standalone host checklist](https://github.com/SchBenedikt/core/blob/main/docs/operations/standalone-host.md).

## Documentation

| Guide | Contents |
| --- | --- |
| [Installation and setup](docs/installation.md) | Local SQLite setup, production PostgreSQL/Nginx/PHP-FPM, accounts, worker and verification |
| [Configuration](docs/configuration.md) | Every environment variable, config-file key, secrets and upload/mail settings |
| [Operations](docs/operations.md) | Backups, restore, upgrades, monitoring, security and troubleshooting |
| [Frontend hosting](docs/frontend.md) | Where the Vue app lives, how it is built and the HostBridge boundary |
| [Documentation index](docs/README.md) | A short route through all guides |

**Status: phases 0–3 of the blueprint plus account mail, the shared UI and a
federation smoke test** — a standalone instance that signs, delivers and
accepts ActivityPub, resets passwords by email and serves the shared Vue app.

| Phase | Delivered | Check |
| --- | --- | --- |
| 0 | Composer project, core as a VCS dependency, `public/index.php` | `/healthz` answers, `composer check` (PHPUnit) green |
| 1 | Config, PDO, migration runner, every core schema | fresh install creates the tables, a second run is a no-op |
| 2 | Accounts, sessions, login, CSRF, `LocalActorDirectory` | login/logout, session rotation, CSRF rejection |
| 3 | `Application::forHost()` and route parity | served routes equal `Routes::manifest()`; a post is written and read over HTTP |
| 3b | Host hardening: declared rate limits, media upload/serving, cleanup job, password CLI | 429 with `Retry-After`; image upload and fetch; worker drains `cleanup` |
| 4 | Password reset by email | hashed single-use token, mail via log/SMTP, sessions revoked |
| 5 | Shared Vue UI mounted at `/app` | `HostBridge` page, timeline, composer, notifications; built assets committed |
| 6 | Federation smoke against a real peer | two instances, signed Follow, follower edge, delivered Create |

Not implemented yet (later blueprint phases): AT Protocol account linking
(Bluesky profiles and posts are readable, but posting and following need
ATProto OAuth), installer/container, and the Nextcloud host. The federation
smoke also shows a core-level gap: an inbound `Accept` is not in the core's
supported activity list, so the `Accept` a core instance queues for an inbound
`Follow` is retried by the worker. The follower edge and the `Create` delivery
are unaffected; the fix belongs in the core package, not in this host.

## Requirements

- PHP 8.3 or newer with `pdo_sqlite` or `pdo_pgsql`, `gd`, `mbstring`,
  `curl`, `openssl` and `json`
- `upload_max_filesize` and `post_max_size` at least the media limit (5 MiB
  by default); PHP rejects a larger upload before the application sees it
- Composer 2
- PostgreSQL (preferred for a real instance) or SQLite (small installs, tests)
- Node 20+ only to rebuild the shared UI; the built bundle in `public/ui/` is
  committed, so a deployment does not need Node

## Quick start

For the complete local and production procedure use
[Installation and setup](docs/installation.md). The short path is:

```shell
composer install
cp .env.example .env          # adjust the instance URL and database
php bin/migrate.php           # creates the core and host schema
php bin/create-account.php alice alice@example.com --admin
php -S localhost:3000 -t public public/index.php
```

Open `http://localhost:3000/`, sign in, and the dashboard links the actor
document, `/api/v1/instance` and the public timeline. `/app` mounts the shared
Vue interface; without SMTP the password-reset mail is written to
`var/mail/*.eml`.

Registration is disabled by design (`instance.registrations=false`). Create
each account with `bin/create-account.php`; the command prompts for a password
unless `--password=…` is supplied.

For a production instance, point the web server at `public/` and run PHP-FPM;
serve every request through `public/index.php` (the core route manifest lives
at the root, including `/@handle` and `/.well-known/webfinger`). Use
`ALOHASOCIAL_INSTANCE_URL` with the exact public HTTPS origin.

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

### Password reset mail

The reset flow lives in the host (accounts are host-owned). A request creates a
single-use token, stores only its SHA-256 and mails a link that expires after
one hour; a completed reset revokes every session of the account.

```shell
# Development default: complete messages as files, no mail server needed.
ALOHASOCIAL_MAIL_TRANSPORT=log
php bin/cleanup.php --now --dry-run   # unrelated, but the CLI is the same boot
ls var/mail/                          # open the .eml and use the link

# Production: SMTP with STARTTLS and AUTH LOGIN.
ALOHASOCIAL_MAIL_TRANSPORT=smtp
ALOHASOCIAL_MAIL_FROM=aloha@example.org
ALOHASOCIAL_SMTP_HOST=mail.example.org
ALOHASOCIAL_SMTP_PORT=587
ALOHASOCIAL_SMTP_USER=aloha
ALOHASOCIAL_SMTP_PASSWORD=secret
ALOHASOCIAL_SMTP_ENCRYPTION=starttls
```

`ALOHASOCIAL_SMTP_ENCRYPTION` also accepts `tls` (implicit TLS, usually port
465) and `none`. A mail that cannot be handed off answers `503`, so the
operator sees the failure instead of a silent drop.

### Shared UI

The page at `/app` is a small host app that mounts `@alohasocial/ui` through
the standalone `HostBridge` (`src` under `ui/`). The bridge attaches the session
cookie and `X-CSRF-Token`, and the shared screens consume only `/api/v1`.

```shell
# Only needed after changing the shared package or the host app. The core's
# Composer dist export-ignores ui/, so check out the matching core source once
# (use the reference from composer.lock) before building the shared package.
git clone --depth 1 https://github.com/SchBenedikt/core.git /tmp/aloha-core
mkdir -p vendor/alohasocial/core/ui
cp -a /tmp/aloha-core/ui/. vendor/alohasocial/core/ui/
(cd vendor/alohasocial/core/ui && npm ci && npm run build)
(cd ui && npm ci && npm run build)   # writes public/ui/app.js + app.css
```

The bundled `public/ui/` files are committed, so a deployment only needs the
PHP host and never runs Node. The hosting decision is recorded in
[docs/frontend.md](docs/frontend.md).

### Host UI extensions

The shared screens need several things the core's anonymous v1 timeline does not
expose directly. The host adds them as same-origin routes; they are not part of
the core route contract:

| Route | Purpose |
| --- | --- |
| `GET /api/v1/host/interactions?ids=…` | Batched favourite/boost/reply counts and the viewer's own like/boost state. |
| `GET /api/v1/host/posts?ids=…` | Full post records for batch hydration, including attachments and reply parents. |
| `GET /api/v1/host/lookup?handle=…&cursor=…` | Resolve and page a public Fediverse or Bluesky profile for the profile view. |
| `GET /api/v1/host/remote-post?id=…` | Read one remote post with its parent, replies and engagement counts. |
| `GET /api/v1/host/remote-connections?handle=…&type=followers\|following&cursor=…` | Page a remote profile's public followers or following list. |
| `PATCH /api/v1/host/profile` | Update the display name of the signed-in account. |
| `POST` / `DELETE /api/v1/posts/{id}/like` | Idempotent like/unlike for the signed-in account. |

The post menu on your own posts opens the composer in edit mode. Saving sends
the core's `PATCH /api/v1/posts/{id}` with the `revision` the reader loaded;
a concurrent edit answers `409` instead of overwriting it, and the updated
record is broadcast to every open timeline and detail view. Edits can keep or
replace attachments, but cannot widen a post's audience.

All writes require the session cookie and `X-CSRF-Token`. Reads only reveal
posts the viewer may already see; private posts still go through the core's
visibility rules. The implementation lives in `src/Interactions/` and
`FrontController`.

### Reading, organising and scheduling

The core's Mastodon-compatible API at `/api/mastodon/v1` is enabled by default
in `HostConfig` (`mastodon_api`), and the standalone UI uses it for the features
Mastodon clients expect instead of duplicating federation logic in the host:

| Screen | Route | What it uses |
| --- | --- | --- |
| Lesezeichen | `#/bookmarks` | `GET /bookmarks`, `POST /statuses/{id}/bookmark` and `/unbookmark`. Bookmark state is also rendered on every post. |
| Suchen | `#/search` | `GET /search` for local accounts, statuses and hashtags; an `@handle` additionally resolves through the remote profile lookup. |
| Listen | `#/lists`, `#/l/<id>` | List CRUD and membership through `/lists`, and the list timeline through `/timelines/list/{id}`. Only local accounts can be members. |
| Stummgeschaltet | `#/muted` | `GET /mutes`, mute/unmute per account. Profiles can mute and unmute directly. |
| Blockiert | `#/blocked` | `GET /api/v1/blocks` and block/unblock per actor URL. Profiles can block and unblock directly. |
| Filter | `#/filters` | Full filter rules through `/api/v1/filters` with action, contexts, keywords and optional expiry. |
| Geplante Beiträge | `#/scheduled` | `/api/v1/scheduled-posts`; a schedule can be re-timed or cancelled, but not edited after queueing. |
| Benachrichtigungen | `#/notifications` | `GET /api/mastodon/v1/notifications` with actor and status previews, plus the core dismiss/clear actions. |

The composer can attach a poll (2–4 options, multiple choice, hide totals,
expiry) or schedule a post. Polls are created through
`POST /api/mastodon/v1/statuses` because the neutral create route has no poll
field; poll voting uses `GET /polls/{id}` and `POST /polls/{id}/votes`. A poll
cannot be combined with scheduling, because a schedule stores no poll. First-party
sessions receive the `['*']` scope, so these routes work without a separately
issued bearer token; bearer clients keep their own scopes.

### Remote profiles and Bluesky

`#/u/<handle>` opens a profile that is not hosted here. The handle may be an
`@user@server` address, a profile URL, a Bluesky handle (`name.bsky.social`) or
a `bsky.app/profile/…` URL. The lookup is outbound and read-only:

- **ActivityPub**: WebFinger resolves the handle, the actor document supplies
  the profile and the actor's public outbox supplies the posts. An instance
  that enables authorized fetch answers `401`/`403` to an unsigned actor
  request; for reading, the host then falls back to that instance's public
  Mastodon-compatible API (`can_follow` stays `false`).
- **Bluesky**: the unauthenticated public AppView supplies the profile and the
  author feed. Posting, liking and following would need ATProto OAuth, so the
  profile view links out to Bluesky instead.

A remote post opens in the same detail view as a local post. The canonical
ActivityPub object id or AT Protocol URI is encoded into `#/p/…`; the host
fetches the post live and shows its parent and replies. Mastodon instances are
read through the public status and context API, other ActivityPub servers
through the object and its `replies` collection, and Bluesky through
`app.bsky.feed.getPostThread`. The view shows like, reply and boost counts
alongside a link to the original. Opening a remote profile's follower or
following count loads that list page by page: the Mastodon API when available,
otherwise the actor's ActivityPub collection, or `app.bsky.graph.getFollowers`
and `getFollows` for Bluesky. Remote profile and post counts are shown exactly
as the origin reports them, and replies, followers and following remain
read-only because acting on them needs a local federation or ATProto identity.

Every remote document is checked by `FederationTargetGuard` (SSRF, redirects,
DNS) and fetched with a byte limit; remote HTML is converted to plain text
before it reaches Vue, which does not use `v-html` for remote content. Remote
lookup and connections are limited to 30 requests per minute per session or
address, and remote post detail to 60.
The implementation is `src/Federation/` plus the host route in
`FrontController`.

The bundle is committed, so a fresh clone serves `/app` without Node. The
production web server serves `public/`; the PHP built-in server serves the
bundle through the router fallback in `public/index.php`.

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
| `ALOHASOCIAL_SESSION_COOKIE_NAME` | `alohasocial_session` | Session cookie name |
| `ALOHASOCIAL_REMOTE_MEDIA` | `true` | `social/media.remote_fetch` |
| `ALOHASOCIAL_MASTODON_API` | `false` | Enables the compatibility prefix |
| `ALOHASOCIAL_MASTODON_PREFIX` | `/api/mastodon/v1` | Prefix for the compatibility routes |
| `ALOHASOCIAL_MAIL_TRANSPORT` | `log` | `log` writes `.eml` files; `smtp` sends them |
| `ALOHASOCIAL_MAIL_FROM` | `no-reply@<instance host>` | Envelope and `From` address |
| `ALOHASOCIAL_MAIL_LOG_DIR` | `var/mail` | Directory of the `log` transport |
| `ALOHASOCIAL_SMTP_HOST` / `ALOHASOCIAL_SMTP_PORT` | empty / `587` | SMTP server |
| `ALOHASOCIAL_SMTP_USER` / `ALOHASOCIAL_SMTP_PASSWORD` | empty | AUTH LOGIN credentials; empty skips AUTH |
| `ALOHASOCIAL_SMTP_ENCRYPTION` | `starttls` | `starttls`, `tls` or `none` |
| `ALOHASOCIAL_DEBUG` | `false` | Debug messages in error responses; never enable in production |

Secrets do not belong in `config/server.php` or in the repository. The instance
private key is sealed with `OpenSslSecretBox` and stored in `instance_key`; the
encryption key stays in the environment or in `var/secret.key` (`0600`).

See [Configuration](docs/configuration.md) for the full reference, precedence
rules and production examples.

## Architecture

```
public/index.php          one entry point: boot, authenticate, dispatch
bin/migrate.php           migration runner
bin/worker.php            core worker loop (`runOnce`)
bin/cleanup.php           queue or run the bounded retention job
bin/create-account.php    account creation (installer step, CLI)
bin/set-password.php      password reset by CLI, revokes the account's sessions
bin/routes.php            served route manifest
bin/federation-smoke.php  two real instances exchanging a signed Follow + Create
ui/                       host Vue app and HostBridge for @alohasocial/ui
public/ui/                committed bundle served at /app

src/Bootstrap/            boot sequence 1-9: config, PDO, keys, ports, assembly
src/Config/               host config + the `social/*` config the core reads
src/Db/                   PDO factory (sqlite/pgsql)
src/Migrations/           schema_migration runner + core schema adoption
src/Accounts/             account table, core LocalActorDirectory/Search/Count
src/Auth/                 sessions, rotation, CSRF, password hashing, reset, throttle
src/Mail/                 log and SMTP transports
src/Http/                 plain-PHP views, front controller, rate limiter, globals adapter
src/Interactions/         host-side likes, boost/read counts and post hydration
resources/views/          login, dashboard, password reset, shared-app mount
tests/                    migrations, auth, rate limits, media, worker and route parity
```

Request flow:

```
Browser → public/index.php → HostConfig → ApplicationFactory::boot()
        → FrontController (session cookie → Request::$userId, CSRF)
        → /, /login, /logout, /forgot-password, /reset-password, /app  (host pages)
        → /api/v1/host/*                                      (host UI extensions)
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
- **Password reset** stores only a token hash; the plain token exists solely in
  the mail. The request form answers the same for unknown identifiers, so it
  cannot enumerate accounts, and five requests per address and fifteen minutes
  are tolerated.
- **The shared UI** talks to the host through one `HostBridge`; the host page
  injects the handle and CSRF token as data attributes, never into
  `localStorage`. Signed-out visitors get the public timeline, signed-in ones
also get the composer and notifications.

## Tests

```shell
composer test
```

The suite boots the real `Application::forHost()` assembly on a throwaway
SQLite database and drives the real front controller: migrations twice,
login/rotation/CSRF/logout/throttle, route budgets, a post written and read
over HTTP, media upload/serving/attachment, batched host interactions, likes,
profile updates, post hydration, password reset by mailed token, the UI mount
and its bundle, actor document and WebFinger, the cleanup job through the
worker, and route parity against the core manifest.

```shell
composer smoke:federation      # or: php bin/federation-smoke.php
```

The smoke starts two real `php -S` instances with separate databases and keys,
lets bob follow alice, checks the follower edge on alice's instance, posts as
alice and asserts that bob receives the signed `Create` in his home timeline.
It runs on loopback with `PHP_CLI_SERVER_WORKERS=4`, because the synchronous
Follow makes each server fetch the other's actor document. `--keep` keeps the
temporary directories for inspection.

The PostgreSQL path is exercised separately: migrations twice, account
creation, login, post write/read, media upload/serve and WebFinger against a
real PostgreSQL server. The automated suite stays on SQLite so it runs without
a database service.

## License

AGPL-3.0-or-later, like the core.
