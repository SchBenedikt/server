# Docker installation

The repository ships a production-oriented image and a Compose stack. A fresh
checkout starts with one command; migrations and the first admin account are
created by the container entrypoint.

Docker runs the same PHP code as the manual installation. The image contains
Apache with `mod_php`, the compiled Vue bundle, both PDO drivers and the
worker command. Node.js is only needed to rebuild the UI, never to run it.

The image is published to GitHub Container Registry on every push to `main`:

```shell
docker pull ghcr.io/schbenedikt/server:latest
```

## Quick start (SQLite)

```shell
git clone https://github.com/SchBenedikt/server.git aloha
cd aloha
cp .env.example .env
docker compose up -d
```

`docker-compose.yml` references `ghcr.io/schbenedikt/server:latest`, so the
stack starts from the published image without a local build. Use
`docker compose up -d --build` to build the same tag from this checkout
instead, for example after changing PHP code.

Open <http://localhost:3000/login>.

The defaults use the SQLite file in the `aloha-var` volume and map container
port 80 to host port 3000. Create the first admin either in `.env` before the
first start:

```dotenv
ALOHASOCIAL_ADMIN_HANDLE=admin
ALOHASOCIAL_ADMIN_EMAIL=admin@example.com
ALOHASOCIAL_ADMIN_PASSWORD=replace-with-a-strong-secret
ALOHASOCIAL_ADMIN_DISPLAY_NAME=Admin
```

or afterwards:

```shell
docker compose exec app php bin/create-account.php admin admin@example.com --admin
```

`ALOHASOCIAL_ADMIN_*` only bootstraps the account. The entrypoint is
idempotent: a restart with the same values prints
`Account @admin already exists; nothing to do.` The account is created as an
admin (`role=admin`) and can sign in at `/login`.

Check the stack:

```shell
docker compose ps
curl -fsS http://localhost:3000/healthz
curl -fsS http://localhost:3000/api/v1/instance
docker compose logs -f app worker
```

Both services should report `healthy`/`running`. The worker waits for the app
healthcheck before it starts, so migrations never run twice.

## Images and tags

| Tag | Source |
| --- | --- |
| `latest` | Newest push to `main` |
| `sha-<commit>` | Immutable image for one commit |
| `vX.Y.Z`, `vX.Y` | A `vX.Y.Z` git tag |

Pin a version on a production host by setting the tag in `.env`. The current
server release is `v0.8.0`; use a `sha-…` tag when an exact commit is needed:

```dotenv
ALOHASOCIAL_IMAGE_TAG=v0.8.0
```

The workflow in `.github/workflows/docker.yml` builds `linux/amd64` and
`linux/arm64` and authenticates with the repository's `GITHUB_TOKEN`, so no
separate registry secret is required. A private image needs
`docker login ghcr.io` with a personal access token that has `read:packages`.

## Choosing the public URL

`ALOHASOCIAL_INSTANCE_URL` is the origin users type into the browser. Actor
IDs, WebFinger subjects, media URLs and cookies derive from it. Set it before
creating accounts or content; changing it later changes public IDs.

If host port 3000 is taken, change both values:

```dotenv
ALOHASOCIAL_PORT=8080
ALOHASOCIAL_INSTANCE_URL=http://localhost:8080
```

For a public HTTPS deployment terminate TLS in a reverse proxy and point
`ALOHASOCIAL_INSTANCE_URL` at the public origin, for example
`https://social.example`. The container itself keeps listening on HTTP; the
host derives secure-cookie defaults from the configured URL.

Example Caddy:

```caddy
social.example {
    reverse_proxy 127.0.0.1:3000
}
```

Example Nginx:

```nginx
server {
    listen 443 ssl http2;
    server_name social.example;
    client_max_body_size 6m;

    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
```

When TLS terminates in the proxy, set `ALOHASOCIAL_SECURE_COOKIES=true` only
if the proxy also forwards to the container over HTTPS; otherwise the instance
URL scheme already controls the cookie default.

## PostgreSQL

The overlay adds a `db` service and points app and worker at it:

```shell
docker compose -f docker-compose.yml -f docker-compose.postgres.yml up -d
```

Set the password in `.env` first:

```dotenv
ALOHASOCIAL_DB_PASSWORD=replace-with-a-strong-secret
```

The database is only reachable on the internal Compose network and stores its
data in the `aloha-db` volume. To keep the SQLite default, do not use the
overlay. A DSN in `.env` without the overlay is ignored for the database
container; the overlay's `ALOHASOCIAL_DB_DSN` wins for app and worker.

## Environment

Every variable from [Configuration](configuration.md) works in `.env`. Compose
passes the file into the containers and uses it for `${…}` substitutions. The
most useful Docker-specific values are:

| Variable | Default | Meaning |
| --- | --- | --- |
| `ALOHASOCIAL_PORT` | `3000` | Host port published by the app service. |
| `ALOHASOCIAL_IMAGE_TAG` | `latest` | Tag of the `ghcr.io/schbenedikt/server` image. |
| `ALOHASOCIAL_ADMIN_HANDLE` | empty | First admin handle; created on boot. |
| `ALOHASOCIAL_ADMIN_EMAIL` | `<handle>@example.invalid` | First admin email. |
| `ALOHASOCIAL_ADMIN_PASSWORD` | empty | First admin password (8+ characters). |
| `ALOHASOCIAL_ADMIN_DISPLAY_NAME` | handle | First admin display name. |
| `ALOHASOCIAL_DB_WAIT_ATTEMPTS` | `60` | Migration retries while the database starts. |

The `.env` file is excluded from the image and must not be committed.

## Files and backups

| Path | Content |
| --- | --- |
| `aloha-var` volume | `var/secret.key`, SQLite database, uploaded blobs and mail files |
| `aloha-db` volume (PostgreSQL overlay) | the database cluster |

`var/secret.key` decrypts the instance signing key. Back it up together with
the database and blobs; losing it makes existing actor keys unreadable.

SQLite backup:

```shell
docker compose exec app php -r '$p=new PDO("sqlite:var/server.sqlite"); $p->exec("VACUUM INTO \"/tmp/backup.sqlite\"");'
docker compose cp app:/tmp/backup.sqlite ./backup.sqlite
```

PostgreSQL backup:

```shell
docker compose -f docker-compose.yml -f docker-compose.postgres.yml exec -T db \
  pg_dump -U alohasocial alohasocial > backup.sql
```

See [Operations](operations.md) for restore, rotation and troubleshooting.

## Updating

```shell
git pull
docker compose pull
docker compose up -d
```

The entrypoint applies pending migrations before Apache starts. The worker is
recreated after the app is healthy. If you pinned `ALOHASOCIAL_IMAGE_TAG`,
update the tag. To follow a source change instead of the published image, use
`docker compose up -d --build` (and rebuild the UI bundle first, see below).

For a source change to the Vue app rebuild the committed bundle first:

```shell
npm --prefix ui ci
npm --prefix ui run build
docker compose up -d --build
```

## Running the tests in Docker

The runtime image intentionally excludes development dependencies. Use the
local PHP toolchain for PHPUnit, or a one-off Composer install:

```shell
docker compose run --rm --entrypoint sh app -lc \
  'composer install --no-interaction && php vendor/bin/phpunit'
```

The `docker compose run` path runs as `www-data`; `var/` is writable by that
user.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| App restarts with `database not ready` | `docker compose logs db`; verify `ALOHASOCIAL_DB_PASSWORD` matches the volume. |
| `/app` loads but API answers 500 | `docker compose logs app`; a missing migration writes `schema is not current`. |
| WebFinger or `/@handle` returns 403 | Confirm the image is current; old images denied every dot-path including `/.well-known`. |
| Login loops back to `/login` | The instance URL must match the browser origin, including scheme and port. |
| Permission denied on `var/secret.key` | The entrypoint fixes ownership on start; a read-only bind mount prevents this. Use the named volume or chown it for the container's `www-data` user. |
| Worker does nothing | `docker compose logs worker`; the worker needs the app healthy before it starts. |

## Related documents

- [Installation and setup](installation.md) for bare metal and the installer script
- [Configuration](configuration.md) for every environment variable
- [Operations](operations.md) for backups, restore and monitoring
