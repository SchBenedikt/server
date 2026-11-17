# Aloha Social server

The standalone host for [`alohasocial/core`](https://github.com/SchBenedikt/core).
It turns the host-neutral core into a running instance: accounts, sessions,
login and CSRF, the database and migrations, the instance key, the HTTP entry
point and the worker.

The core owns social behaviour and the route manifest. This repository owns
everything a process needs. The architecture is described in
[Development](docs/development.md) and the
[server blueprint](https://github.com/SchBenedikt/core/blob/main/docs/guides/server-architecture.md).

**Status: release 0.11.2.** The server signs, delivers and accepts ActivityPub,
serves WebFinger and NodeInfo, runs the shared Vue UI, resets passwords by
email and keeps accounts, keys and media across updates. See
[Features](docs/features.md) for the full surface and
[CHANGELOG.md](CHANGELOG.md) for release history.

## Documentation

| Guide | Contents |
| --- | --- |
| [Docker](docs/docker.md) | Compose stack, ports, PostgreSQL overlay, reverse proxy, backups and updates |
| [Installation](docs/installation.md) | Installer script, local SQLite setup, production PostgreSQL/Nginx/PHP-FPM, first account |
| [Configuration](docs/configuration.md) | Environment variables, config-file keys, secrets, uploads and mail |
| [Operations](docs/operations.md) | Backups, restore, upgrades, worker, monitoring, security and troubleshooting |
| [Features](docs/features.md) | Shared UI, host API extensions, Kokua, reading and scheduling, remote profiles, Bluesky, administration and moderation |
| [Development](docs/development.md) | Architecture, request flow, core/host workflow, design rules and tests |
| [Frontend hosting](docs/frontend.md) | Vue app, `HostBridge` boundary and the committed bundle |
| [Documentation index](docs/README.md) | A short route through all guides |
| [Changelog](CHANGELOG.md) | Release history and versioning rules |

## Requirements

- PHP 8.3+ with `pdo_sqlite` or `pdo_pgsql`, `gd`, `mbstring`, `curl`,
  `openssl` and `json`
- `upload_max_filesize` and `post_max_size` at least the media limits: 5 MiB
  images, 20 MiB video, 10 MiB audio and up to ten attachments
- Composer 2
- PostgreSQL for a real instance, or SQLite for small installs and tests
- Node 20+ only to rebuild the shared UI; the bundle in `public/ui/` is
  committed

## Quick start

### Docker

The default stack uses SQLite inside a named volume and publishes the app on
host port **3000**. Inside the container Apache listens on port **80**; only
the app service is published, never the database.

```shell
git clone https://github.com/SchBenedikt/server.git aloha
cd aloha
cp .env.example .env          # optional; the defaults use SQLite
docker compose up -d          # pulls ghcr.io/schbenedikt/server:latest
# or build the same tag from this checkout:
docker compose up -d --build
```

Open `http://localhost:3000/login`. Set `ALOHASOCIAL_ADMIN_HANDLE`,
`ALOHASOCIAL_ADMIN_EMAIL` and `ALOHASOCIAL_ADMIN_PASSWORD` in `.env` to create
the first admin on boot, or create it afterwards with:

```shell
docker compose exec app php bin/create-account.php alice alice@example.com --admin
```

`ALOHASOCIAL_PORT` changes the published host port; change
`ALOHASOCIAL_INSTANCE_URL` to the same origin. The PostgreSQL overlay, reverse
proxy examples and the full Docker reference are in [Docker](docs/docker.md).

### Installer or manual

```shell
git clone https://github.com/SchBenedikt/server.git aloha
cd aloha
scripts/install.sh            # interactive; URL, storage, database, mail, admin
php -S localhost:3000 -t public public/index.php
```

Pass the answers as options for automation:

```shell
scripts/install.sh --url=http://localhost:3000 \
  --handle=alice --email=alice@example.com
```

The manual path is:

```shell
composer install
cp .env.example .env          # set the instance URL and database
php bin/update.php            # creates the schema; backs up before upgrades
php bin/create-account.php alice alice@example.com --admin
php -S localhost:3000 -t public public/index.php
```

Open `http://localhost:3000/`, sign in, then open `/app` for the shared Vue
interface. Registration is disabled by design; create each account with
`bin/create-account.php`, which prompts for a password unless `--password=…` is
passed. Without SMTP, password-reset mail is written to `var/mail/*.eml`.

## Operating the instance

```shell
php bin/reset.php             # backup, empty the instance, print the setup URL
php bin/update.php            # backup, migrate and verify accounts
php bin/doctor.php            # data path, schema, key and backup diagnostics
php bin/worker.php            # supervised loop; --once for cron
php bin/backup.php            # verified database backup
```

`reset` keeps `.env`, the database connection and the instance key, and prints
a one-time `/setup?token=…` URL. `update` refuses to continue when the safety
backup fails or the account count drops. On a bare-metal host keep runtime
state outside the release directory with
`scripts/install.sh --data-dir=/srv/aloha-data`. Full procedures are in
[Installation](docs/installation.md) and [Operations](docs/operations.md).

## Configuration

Precedence is: defaults < `config/server.php` < environment (and `.env`).
`.env` is never committed. The complete variable reference, secrets, upload
limits and production examples are in [Configuration](docs/configuration.md).

The most important values are:

| Variable | Default | Meaning |
| --- | --- | --- |
| `ALOHASOCIAL_INSTANCE_URL` | `http://localhost:3000` | Public origin; actor ids, WebFinger and cookies derive from it |
| `ALOHASOCIAL_DB_DSN` | `sqlite:var/server.sqlite` | PDO DSN; use `pgsql:host=…;dbname=…` for PostgreSQL |
| `ALOHASOCIAL_VAR_DIRECTORY` | `var` | Runtime state (SQLite file, key) |
| `ALOHASOCIAL_PORT` | `3000` | Docker only: published host port |
| `ALOHASOCIAL_MAIL_TRANSPORT` | `log` | `log` writes `.eml` files; `smtp` sends them |
| `ALOHASOCIAL_SECURE_COOKIES` | on for https | `Secure` session cookie |

## Tests

```shell
composer test                 # PHPUnit against a throwaway SQLite database
composer smoke:federation     # two real instances, signed Follow, Accept and Create
composer check                # test + federation smoke
```

The core checkout in `vendor/alohasocial/core` is loaded directly by the host.
Changes to the core must be committed and pushed there first, then pinned here
with `composer update alohasocial/core`. See
[Development](docs/development.md) for the full workflow and test coverage.

The PostgreSQL path is exercised separately: migrations twice, account
creation, login, post write/read, media upload/serve and WebFinger against a
real PostgreSQL server. The regular suite stays on SQLite so it runs without a
database service.

## License

AGPL-3.0-or-later, like the core.
