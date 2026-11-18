# Development

How the host and the host-neutral core fit together, how to work on both repositories and what the test gates cover.

## Architecture

```
public/index.php          one entry point: boot, authenticate, dispatch
bin/update.php            backup, migrate and verify accounts
bin/reset.php             backup, empty the instance and print the setup URL
bin/backup.php            verified database backup with metadata
bin/doctor.php            data-path, schema, key and backup diagnostics
bin/migrate.php           low-level migration runner
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
src/Support/              database backup and operator support
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

The core is a Composer VCS dependency (`alohasocial/core: dev-main` from the
VCS repository) pinned by `composer.lock`. The installed core reports its
release line in `vendor/alohasocial/core/src/Version.php` (currently `0.7.0`).
Nothing from the core is copied here.

### Working on the core and host together

Composer installs the core as a git source checkout, so
`vendor/alohasocial/core` is the core working tree and is loaded directly by
the host. Use this checkout instead of a second clone; a second clone would
not be autoloaded. Keep both repositories on `main` and make the host lock
follow the core commit:

```shell
# 1. Change, test, commit and push the core first.
git -C vendor/alohasocial/core status -sb
composer --working-dir=vendor/alohasocial/core install
composer --working-dir=vendor/alohasocial/core check
git -C vendor/alohasocial/core push origin main

# 2. Point the host lock at that core commit and run its checks.
composer update alohasocial/core
composer test

# 3. Commit the host changes, including composer.lock, and push server main.
```

A normal `composer install` with a dist archive is enough: the frontend uses
the committed `ui/shared-ui/` package and does not read Core's `ui/` directory.
The host's deployed version is only correct when `composer.lock` names the core
commit it expects.

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

## Core protocol coverage

The core already owns the protocol implementation that both hosts share:

- **ActivityPub:** actors, WebFinger, inbox/outbox, HTTP signatures,
  Follow/Accept, Create/Update/Delete, Announce, reactions, polls, stories,
  quotes, blocks and the remote-post store. This host adds NodeInfo and the
  dereferenceable Note/Create URLs the core advertises but does not route.
- **AT Protocol:** record and repository primitives, deterministic TIDs,
  posts/replies/facets/media/likes/reposts, publication rows and remote delete,
  an AppView read port, replay-safe inbound relay/Jetstream import, the native
  PDS and XRPC surface, the authorization-code/PAR/revocation **client**, DPoP,
  and OAuth session refresh. Account linking UI, the browser callback, the
  short-lived flow store and the event transport are host adapters by design.
- **Mastodon compatibility:** 53 core routes under `/api/mastodon/v1`.

Two protocol surfaces are deliberately not claimed as complete here:

- The core has no OAuth **authorization server** for third-party Mastodon
  clients: client registration, consent, authorization-code/device flows,
  token rotation and revocation are not core routes. The Nextcloud host has
  its own implementation, but it depends on host-owned consent pages and
  token storage, so it cannot be copied in without a core port and schema.
- The Nextcloud app's 656 documented routes include Nextcloud-only integration
  (Files, Talk, Deck, dashboard, Activity, push, admin settings and the full
  ATProto AppView/OAuth-server stack). Those are host adapters, not features
  the standalone host can silently inherit.

This distinction is intentional: the core is host-neutral and the host owns
accounts and login. A future OAuth server needs a `ClientRepository`,
`AuthorizationRepository`, consent UI and bearer-token authentication port in
this host before it can be implemented rather than faked.

## Tests

```shell
composer test
```

The suite boots the real `Application::forHost()` assembly on a throwaway
SQLite database and drives the real front controller: migrations twice,
login/rotation/CSRF/logout/throttle, route budgets, a post written and read
over HTTP, media upload/serving/attachment, batched host interactions, likes,
profile updates, post hydration, password reset by mailed token, the Kokua
board and its offers, the UI mount and its bundle, actor document and WebFinger,
the cleanup job through the worker, ActivityPub post and NodeInfo discovery,
and route parity against the core manifest. The bundle test also checks
the markers for the sidebar popover, the mobile sheet, video/audio rendering,
the local account connection endpoint and the new quote glyph. `VersionTest`
keeps `composer.json`, `ui/package.json` and the newest changelog heading on the
same release number.

```shell
composer smoke:federation      # or: php bin/federation-smoke.php
```

The smoke starts two real `php -S` instances with separate databases and keys,
lets bob follow alice, runs each instance's worker for the queued Follow and
Accept, checks the follower edge on alice's instance, posts as alice and
asserts that bob receives the signed `Create` in his home timeline. It runs
with `PHP_CLI_SERVER_WORKERS=4` so the two servers can fetch each other's
actor documents. The smoke uses a globally routable local address when one
exists, so the production SSRF guard stays active and the delivery path is the
real one; set `ALOHASOCIAL_SMOKE_HOST` to choose an address explicitly. On a
host without a public address it falls back to loopback and marks the delivery
step as blocked. `--keep` keeps the temporary directories for inspection.

The PostgreSQL path is exercised separately: migrations twice, account
creation, login, post write/read, media upload/serve and WebFinger against a
real PostgreSQL server. The automated suite stays on SQLite so it runs without
a database service.

