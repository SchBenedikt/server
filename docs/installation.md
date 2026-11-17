# Installation and setup

This guide takes a fresh checkout to a running Aloha Social instance: the PHP
host, its database, an account, the Vue app at `/app`, and the background
worker.

For the short version see the [README](../README.md#quick-start). For every
setting see [Configuration](configuration.md). For backups, upgrades and
troubleshooting see [Operations](operations.md).

## Fast path: Docker

The image contains PHP, Apache, both PDO drivers and the worker; the host only
needs Docker:

```shell
git clone https://github.com/SchBenedikt/server.git aloha
cd aloha
cp .env.example .env
docker compose up -d
```

This pulls the published image `ghcr.io/schbenedikt/server:latest`; add
`--build` to build it from the checkout instead. Migrations run on start, and
setting `ALOHASOCIAL_ADMIN_HANDLE`,
`ALOHASOCIAL_ADMIN_EMAIL` and `ALOHASOCIAL_ADMIN_PASSWORD` in `.env` creates
the first admin account. Use the
[PostgreSQL overlay](docker.md#postgresql) for a database container instead of
SQLite, and read [Docker installation](docker.md) for reverse proxies,
backups, updates and troubleshooting.

## Fast path: installer script

On a host with PHP 8.3+ and Composer, the script performs the manual steps
below and is safe to run again:

```shell
git clone https://github.com/SchBenedikt/server.git aloha
cd aloha
scripts/install.sh
```

Started on a terminal without arguments, the script opens an interactive
setup and asks for the public URL, the instance name, the data directory,
SQLite or PostgreSQL, the mail transport and the first admin account. It also
offers the systemd/Nginx templates. Every question has a default, so pressing
Enter walks through the whole installation; on an existing instance those
defaults are read from `.env`, and pressing Enter keeps the running URL,
database and paths unchanged. Nothing is written before the final
confirmation, and the script validates the answers before it touches `.env`
or the database.

After the migrations and account creation the installer runs
`bin/doctor.php` and fails if the instance is not usable. `--no-verify` skips
that last check for provisioning systems that run their own checks.

For unattended installs pass the answers as options (or use
`--non-interactive`):

```shell
scripts/install.sh \
  --url=https://social.example \
  --db-dsn='pgsql:host=127.0.0.1;port=5432;dbname=alohasocial' \
  --db-user=alohasocial --db-password='…' \
  --handle=admin --email=admin@example.com
```

`scripts/install.sh --help` lists every option, including `--interactive` to
force the wizard, `--non-interactive` to never prompt, `--data-dir=DIR` to
keep the database, key and media outside the checkout, the `--mail-*` and
`--smtp-*` options for unattended mail setup, and `--write-service-files` for a
matching systemd unit and Nginx server block. Without `--handle` and without
the wizard it leaves account creation to you. The script does not start
PHP-FPM, Nginx or the worker; the sections below cover those services.

## Reset and reconfigure in the browser

A running instance can be returned to an empty installation without losing its
database connection or instance key. Stop the worker and PHP-FPM first (or put
the site in maintenance mode) so no request writes to the tables while they are
dropped:

```shell
sudo systemctl stop aloha-worker php8.3-fpm
php bin/reset.php              # asks for the word RESET
php bin/reset.php --yes        # controlled scripts; still backs up first
php bin/reset.php --keep-media --yes
```

When accounts exist, the command creates and verifies a database backup in
`var/backups/` before dropping anything. It then drops every application table,
rebuilds the current schema, removes uploaded media and mail files (unless
`--keep-media` is given), and writes a one-time `var/setup.token`.

It prints a URL like `https://social.example/setup?token=…`. Open it and fill in:

- the final public HTTPS origin (for example `https://social.example`),
- the instance name, description and federation contact address,
- the first administrator's handle, email and password.

The setup writes only these values to `.env` and closes the token after success.
It cannot be opened on a normal running instance, and an empty database alone
is not enough: the token is required. Restart PHP-FPM after setup so every
worker process reads the new origin, then start `bin/worker.php` again:

```shell
sudo systemctl start php8.3-fpm
sudo systemctl start aloha-worker
``` The
actor IDs and WebFinger links of the new accounts use the new domain.

If the setup token is lost, run `php bin/reset.php` again or read
`var/setup.token` as the application user. Never publish that file or paste the
setup URL into a chat.

## What you are installing

| Part | Where | Required to run |
| --- | --- | --- |
| PHP host (accounts, sessions, API, dashboard) | this repository | yes |
| Core social logic | Composer package `alohasocial/core` | yes |
| Vue app | committed bundle in `public/ui/` | yes |
| Worker | `bin/worker.php` | recommended |
| Node.js | `ui/` | only to rebuild the UI |

The compiled frontend is committed. A deployment does **not** need Node.js
unless you change the UI.

## Requirements

- **PHP 8.3+** with `pdo_sqlite` or `pdo_pgsql`, `gd`, `mbstring`, `curl`,
  `openssl`, and `json`
- **Composer 2**
- **PostgreSQL** for a real instance, or **SQLite** for local development and
  small installations
- **Node.js 20+** only if you rebuild the UI
- A web server for production. Examples below use Nginx with PHP-FPM.

Check PHP before you start:

```shell
php -v
php -m | grep -E 'pdo_sqlite|pdo_pgsql|gd|mbstring|curl|openssl'
```

Uploads are limited by the application to 5 MiB per image, 20 MiB per video and
10 MiB per audio file, with at most ten attachments per post. PHP must accept at
least the largest single file; a post with several files needs room for their
sum:

```ini
upload_max_filesize = 21M
post_max_size = 64M
max_execution_time = 60
```

If video is not needed, the smaller image limits (`6M` / `8M`) are enough. The
web server's request-body limit must also match the chosen value (see the Nginx
`client_max_body_size` example below).

## Local installation (SQLite)

These steps install into the current directory and run on
`http://localhost:3000`.

### 1. Get the code and dependencies

```shell
git clone https://github.com/SchBenedikt/server.git aloha
cd aloha
composer install
```

For a production install without the test dependencies use
`composer install --no-dev`.

### 2. Configure the instance

```shell
cp .env.example .env
```

Edit `.env` and set at least:

```dotenv
ALOHASOCIAL_INSTANCE_URL=http://localhost:3000
ALOHASOCIAL_INSTANCE_NAME="Aloha Social"
ALOHASOCIAL_DB_DSN=sqlite:var/server.sqlite
```

The instance URL is the public origin. Actor IDs, WebFinger links, media URLs
and cookies are derived from it. Use the exact scheme, host and port that
users type into the browser. A trailing slash is removed automatically, but
prefer writing the value without one.

Never commit `.env`. It is already excluded by `.gitignore`.

### 3. Create the database schema

```shell
php bin/update.php
```

This creates the core and host tables, records the migration versions and, on
an existing installation, creates a verified backup before changing anything.
Running it twice is safe and prints `Nothing to migrate.` `php bin/doctor.php`
checks the result. `bin/migrate.php` remains available as the low-level runner
for development; operators should use `bin/update.php`.

On first application boot, `var/secret.key` is generated. It encrypts the
instance signing key stored in the database. **Back it up with the database.**
Alternatively, provide a base64-encoded 32-byte key in
`ALOHASOCIAL_SECRET_KEY`; see [Configuration](configuration.md).

### 4. Create the first account

Registration is intentionally disabled on this host
(`instance.registrations=false`). Accounts are created by an operator:

```shell
php bin/create-account.php alice alice@example.com --admin
```

The command asks for a password (minimum 8 characters). In a provisioning
script you can pass `--password='…'` and `--display-name='Alice'`, but avoid
putting secrets in shell history:

```shell
php bin/create-account.php alice alice@example.com \
  --display-name='Alice' --password='replace-with-a-strong-secret'
```

The handle is the account ID and is immutable. It must be 2–30 lower-case
characters (`a-z`, `0-9`, `_`, `-`). `--admin` stores the admin role; account
roles do not replace the deployment's own access controls.

For a host that will be updated in place, keep runtime state outside the
release directory before creating accounts:

```shell
scripts/install.sh --data-dir=/srv/aloha-data …
```

This sets `ALOHASOCIAL_VAR_DIRECTORY`, `ALOHASOCIAL_BLOB_DIRECTORY`,
`ALOHASOCIAL_SECRET_KEY_FILE`, `ALOHASOCIAL_MAIL_LOG_DIR` and, for SQLite,
`ALOHASOCIAL_DB_DSN` to the new directory. A later release directory,
`git clean` or checkout replacement then cannot delete accounts, the secret
key or uploaded media. Back up that directory with the database.

Running `--data-dir` on an existing SQLite installation copies the database
with a consistent `VACUUM INTO` snapshot, the secret key, uploads and mail to
the new directory before switching `.env`. The old files are left untouched,
so the operation can be checked before they are removed. If a database exists
at both locations, or a key exists with different contents, the installer
stops instead of choosing one silently.

### 5. Start the development server

```shell
php -S localhost:3000 -t public public/index.php
```

Open:

- `http://localhost:3000/login` — sign in
- `http://localhost:3000/app` — the social UI
- `http://localhost:3000/healthz` — liveness probe

The PHP built-in server is for development. It is single-threaded and is not a
production web server.

### 6. Start the worker (optional locally, recommended everywhere)

The worker delivers federation jobs, sends queued work and runs cleanup jobs:

```shell
php bin/worker.php
```

Use another terminal. Stop it with `Ctrl+C` or `SIGTERM`; it exits between
batches. For a one-off batch use `php bin/worker.php --once`.

### 7. Verify the installation

```shell
curl -fsS http://localhost:3000/healthz
curl -fsS http://localhost:3000/api/v1/instance
curl -fsS -o /dev/null -w '%{http_code}\n' http://localhost:3000/app
```

Then sign in and check the UI: create a post, reply to it, like and boost it,
upload an image, edit your display name, search, open notifications, and log
out. The reference instance used for verification exercised exactly these flows;
the application does not ship fixed demo credentials or fixture data. Create
the accounts you need with `bin/create-account.php`.

## Production installation (PostgreSQL + Nginx)

A production install uses HTTPS, PostgreSQL and PHP-FPM. The example path is
`/srv/aloha`; adjust it consistently.

### 1. System packages and account

```shell
sudo apt install php8.3-fpm php8.3-cli php8.3-pgsql php8.3-gd \
  php8.3-mbstring php8.3-curl php8.3-xml composer nginx postgresql
sudo adduser --system --group --home /srv/aloha aloha
sudo -u aloha git clone https://github.com/SchBenedikt/server.git /srv/aloha
```

If `/srv/aloha` already exists as the service home, clone into it or place a
release there and keep the same layout (`public/`, `bin/`, `var/`).

Make the application files readable by the web server and writable only where
runtime state lives:

```shell
sudo chown -R aloha:www-data /srv/aloha
sudo chmod -R u=rwX,g=rX,o= /srv/aloha
sudo install -d -o aloha -g www-data -m 770 /srv/aloha/var
sudo install -d -o aloha -g www-data -m 770 /srv/aloha/var/blobs
```

Run PHP-FPM, the CLI commands and the worker as the **same unprivileged
application user** (`aloha` here). The `var/secret.key` file is created `0600`
by that user, and PHP-FPM must be able to read it. Nginx itself only needs read
access to `public/`; it does not need write access to `var/`.

If your PHP-FPM pool has to stay on `www-data`, do not use `0600` for the key
and `.env`; make them group-readable by the FPM group and keep that group
small. The rest of this guide assumes the dedicated-user setup.

### 2. PostgreSQL

Create a dedicated role and database. Choose a real password:

```shell
sudo -u postgres psql
```

```sql
CREATE ROLE aloha LOGIN PASSWORD 'replace-with-a-strong-secret';
CREATE DATABASE aloha OWNER aloha;
\q
```

Create the environment file from the example, then set the connection:

```shell
sudo -u aloha cp /srv/aloha/.env.example /srv/aloha/.env
```

```dotenv
ALOHASOCIAL_DB_DSN=pgsql:host=127.0.0.1;port=5432;dbname=aloha
ALOHASOCIAL_DB_USER=aloha
ALOHASOCIAL_DB_PASSWORD=replace-with-a-strong-secret
```

SQLite is supported, but PostgreSQL is the better default for concurrent
traffic and backups. Whichever database you choose, keep `var/secret.key` and
`var/blobs/` in the same backup set.

### 3. Configure the public origin and mail

```dotenv
ALOHASOCIAL_INSTANCE_URL=https://social.example
ALOHASOCIAL_INSTANCE_NAME="Aloha Social"
ALOHASOCIAL_CONTACT_EMAIL=admin@example
ALOHASOCIAL_DEBUG=false
ALOHASOCIAL_SECURE_COOKIES=true
```

After editing, hand the file to the application user and restrict it:

```shell
sudo chown aloha:www-data /srv/aloha/.env
sudo chmod 600 /srv/aloha/.env
```

Continue with the mail settings:

```dotenv

ALOHASOCIAL_MAIL_TRANSPORT=smtp
ALOHASOCIAL_MAIL_FROM=no-reply@social.example
ALOHASOCIAL_SMTP_HOST=mail.example
ALOHASOCIAL_SMTP_PORT=587
ALOHASOCIAL_SMTP_USER=aloha
ALOHASOCIAL_SMTP_PASSWORD=replace-with-a-strong-secret
ALOHASOCIAL_SMTP_ENCRYPTION=starttls
```

Use the real public origin. Changing the instance URL after posts and actors
exist changes public IDs and is not a supported migration. If you are still
setting up, decide the final URL before creating accounts and content.

The `log` mail transport writes complete `.eml` files to `var/mail/`; it is the
development default. Use `smtp` in production so password resets reach users.

### 4. Install and migrate

```shell
sudo -u aloha -H bash -lc 'cd /srv/aloha && composer install --no-dev --classmap-authoritative'
sudo -u aloha -H bash -lc 'cd /srv/aloha && php bin/update.php'
sudo -u aloha -H bash -lc 'cd /srv/aloha && php bin/create-account.php admin admin@example --admin'
```

Run migrations as the same OS user that owns the application and can write
`var/`. Do not run two migrations at the same time. On first boot this user
creates `var/secret.key`; PHP-FPM must run as this user, not as `www-data`.

### 5. Nginx and PHP-FPM

Point the document root at `public/`, never at the repository root. Every
request that is not a real static file must go through `public/index.php`.

```nginx
server {
    listen 443 ssl http2;
    server_name social.example;

    root /srv/aloha/public;
    index index.php;
    client_max_body_size 6m;

    ssl_certificate     /etc/letsencrypt/live/social.example/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/social.example/privkey.pem;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Configure the PHP-FPM pool to run as the application user. For the default
Debian/Ubuntu pool (`/etc/php/8.3/fpm/pool.d/www.conf`):

```ini
[www]
user = aloha
group = www-data
```

Add a separate port-80 server that redirects to HTTPS. Set PHP limits in the
pool (`upload_max_filesize = 21M`, `post_max_size = 64M` for video, or
`6M` / `8M` if only images are used, `max_execution_time = 60`). The UI bundle is served as static files from
`public/ui/`; no Node process or asset server is needed.

Restart PHP-FPM after changing the pool or deployment files:

```shell
sudo systemctl restart php8.3-fpm
```

With Apache, set `DocumentRoot /srv/aloha/public` and use
`FallbackResource /index.php` in the directory block, with PHP-FPM or
`mod_php` configured for `.php`. Do not enable directory listing.

### 6. Worker service

Create `/etc/systemd/system/aloha-worker.service`:

```ini
[Unit]
Description=Aloha Social worker
After=network.target

[Service]
Type=simple
User=aloha
Group=www-data
WorkingDirectory=/srv/aloha
ExecStart=/usr/bin/php /srv/aloha/bin/worker.php
Restart=always
RestartSec=3
NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
```

```shell
sudo systemctl daemon-reload
sudo systemctl enable --now aloha-worker
sudo systemctl status aloha-worker
journalctl -u aloha-worker -f
```

If you prefer cron instead of a service, run one batch per minute; do not run
both a service and cron for the same queue:

```cron
* * * * * aloha cd /srv/aloha && /usr/bin/php bin/worker.php --once
0 3 * * * aloha cd /srv/aloha && /usr/bin/php bin/cleanup.php
```

### 7. Production verification

```shell
curl -fsS https://social.example/healthz
curl -fsS https://social.example/api/v1/instance
curl -fsS -o /dev/null -w '%{http_code}\n' https://social.example/app
curl -fsS -o /dev/null -w '%{http_code}\n' https://social.example/ui/app.js
sudo -u aloha php /srv/aloha/bin/routes.php >/dev/null
```

Then verify in a browser over the real HTTPS origin:

1. Sign in.
2. Create a post with text and an image; reload and confirm the image remains.
3. Create a post with a short video and one with audio; confirm both play inline
   and in the lightbox.
4. Reply, like, boost, quote, and remove the like.
5. Open your profile and both follower/following lists.
6. Edit the display name on your profile.
7. Search, open notifications, and log out.
8. At mobile width, open the **More** sheet and check that every destination is
   reachable without horizontal scrolling; repeat in both themes.

## Updating an installation

1. Put the instance in maintenance mode or stop the worker.
2. Back up the database, `var/secret.key` and `var/blobs/`.
3. Fetch the new code and run `composer install --no-dev`.
4. Run `php bin/doctor.php`; if a migration is pending, run
   `php bin/update.php` and inspect the applied versions.
5. Rebuild the UI only if the frontend changed:
   `npm --prefix ui ci && npm --prefix ui run build`.
6. Restart PHP-FPM and the worker.
7. Check `/healthz`, `/app`, and the flows above.

The committed UI bundle means a server-only update normally needs no Node.
If you changed the shared UI source, rebuild the shared package first as
described in the [frontend hosting note](frontend.md).

## Related documents

- [Configuration reference](configuration.md)
- [Operations, backups and troubleshooting](operations.md)
- [Frontend hosting decision](frontend.md)
- [Project README](../README.md)
