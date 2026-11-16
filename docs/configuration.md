# Configuration reference

Aloha Social reads configuration in this order, lowest to highest precedence:

1. built-in defaults in `src/Config/HostConfig.php`
2. `config/server.php`
3. environment variables, including values loaded from `.env`

Real environment variables win over `.env`. `.env` is never committed.

The same file is read by the web process, the CLI scripts and the worker, so
keep one authoritative configuration for all of them. Paths in configuration
are resolved against the project root, not the process working directory.

## `.env`

Start from the committed example:

```shell
cp .env.example .env
```

The application loads `.env` itself. With PHP-FPM, the file must be readable by
the PHP-FPM user and should not be reachable through the web server (it is
outside `public/`, and Nginx dotfile rules deny it).

## Instance

| Variable | Config file key | Default | Meaning |
| --- | --- | --- | --- |
| `ALOHASOCIAL_INSTANCE_URL` | `instance_url` | `http://localhost:3000` | Public origin. Must be an absolute `http://` or `https://` URL. Actor IDs, WebFinger, media URLs, and cookies derive from it. |
| `ALOHASOCIAL_INSTANCE_NAME` | `instance_name` | `Aloha Social` | Display name in the UI and instance API. |
| `ALOHASOCIAL_INSTANCE_DESCRIPTION` | `instance_description` | empty | Public instance description. |
| `ALOHASOCIAL_CONTACT_EMAIL` | `contact_email` | empty | Public admin contact. |

Use the final public origin before creating accounts or content. Changing it
later changes public actor identities and existing links; plan the domain
first.

## Database

| Variable | Config file key | Default | Meaning |
| --- | --- | --- | --- |
| `ALOHASOCIAL_DB_DSN` | `database_dsn` | `sqlite:var/server.sqlite` | PDO DSN. Use `pgsql:host=…;port=5432;dbname=alohasocial` for PostgreSQL. |
| `ALOHASOCIAL_DB_USER` | `database_user` | empty | Database user. |
| `ALOHASOCIAL_DB_PASSWORD` | `database_password` | empty | Database password. |

SQLite is the default for local development. PostgreSQL is recommended for a
production instance. The database user needs schema create/alter rights during
migration and normal read/write rights afterwards.

## Files and secrets

| Variable | Config file key | Default | Meaning |
| --- | --- | --- | --- |
| `ALOHASOCIAL_VAR_DIRECTORY` | `var_directory` | `var` | Runtime state: SQLite file, key, logs and local mail. |
| `ALOHASOCIAL_BLOB_DIRECTORY` | `blob_directory` | `var/blobs` | Original images and generated thumbnails. |
| `ALOHASOCIAL_SECRET_KEY` | `secret_key` | empty | Base64 32-byte key for the secret box. If set, no key file is used. |
| `ALOHASOCIAL_SECRET_KEY_FILE` | `secret_key_file` | `var/secret.key` | Key file generated on first boot when no key is provided. |

The secret box encrypts the instance signing key stored in the database.
Losing the key makes that stored key unreadable, so **back up the key and the
database together**. The key file must be `0600` and owned by the application
user.

`ALOHASOCIAL_VAR_DIRECTORY` and `ALOHASOCIAL_BLOB_DIRECTORY` are created if
needed. The PHP user must be able to write them.

On a host that updates the checkout in place, use an absolute data directory
outside the release, for example `/srv/aloha-data`, and point all four paths
(`var`, `blobs`, `secret key file`, `mail log`) at it. Otherwise a fresh clone,
a release swap or `git clean -fdx` can remove the SQLite database, the secret
key and uploaded media without any warning. `scripts/install.sh --data-dir=DIR`
writes this configuration.

## Sessions and cookies

| Variable | Config file key | Default | Meaning |
| --- | --- | --- | --- |
| `ALOHASOCIAL_SECURE_COOKIES` | `secure_cookies` | on for `https://` instance URLs | Adds `Secure` to the session cookie. |
| `ALOHASOCIAL_SESSION_LIFETIME` | `session_lifetime` | `604800` | Session lifetime in seconds; minimum 60. |
| — | `session_cookie_name` | `alohasocial_session` | Cookie name. Useful when several instances share a hostname. |

Cookies are `HttpOnly` and `SameSite=Lax`. Use HTTPS in production and keep the
instance URL in sync with the origin users open, otherwise secure cookies can
make login appear to fail. Changing a password revokes all sessions for that
account.

## Uploads and federation

| Variable | Config file key | Default | Meaning |
| --- | --- | --- | --- |
| `ALOHASOCIAL_REMOTE_MEDIA` | `remote_media` | `true` | Allows the core to fetch remote media. |
| `ALOHASOCIAL_MASTODON_API` | `mastodon_api` | `true` | Enables the Mastodon-compatible prefix. |
| `ALOHASOCIAL_DISCOVERY_INSTANCE` | `discovery_instance` | `mastodon.social` | Public Mastodon-compatible instance used for federated keyword and hashtag search. Empty disables it; Bluesky search keeps working. |
| `ALOHASOCIAL_MASTODON_PREFIX` | `mastodon_prefix` | `/api/mastodon/v1` | Compatibility route prefix. |

The application accepts images (JPEG, PNG, WebP, GIF) up to 5 MiB, video
(MP4, WebM) up to 20 MiB and audio (MP3, M4A, Ogg, WAV) up to 10 MiB per file,
with at most ten attachments per post. The core enforces the per-kind limits;
the host does not override them. Set PHP's `upload_max_filesize` to at least
`21M` and `post_max_size` to at least `64M` if video uploads should work; a
post with several files needs room for their sum too. Set the web server's
request-body limit to at least the same value.

Federation is enabled by the host. Actor documents, WebFinger and inbox/outbox
routes must be served from the same origin through `public/index.php`; do not
rewrite only `/api/` and leave `/.well-known/` or `/@handle` unhandled.

## Mail

| Variable | Config file key | Default | Meaning |
| --- | --- | --- | --- |
| `ALOHASOCIAL_MAIL_TRANSPORT` | `mail_transport` | `log` | `log` writes complete `.eml` files; `smtp` sends mail. |
| `ALOHASOCIAL_MAIL_FROM` | `mail_from` | `no-reply@<instance host>` | Envelope and `From` address. |
| `ALOHASOCIAL_MAIL_LOG_DIR` | `mail_log_directory` | `var/mail` | Directory for the `log` transport. |
| `ALOHASOCIAL_SMTP_HOST` | `smtp_host` | empty | SMTP server; required when transport is `smtp`. |
| `ALOHASOCIAL_SMTP_PORT` | `smtp_port` | `587` | SMTP port. |
| `ALOHASOCIAL_SMTP_USER` | `smtp_user` | empty | AUTH LOGIN user; empty skips authentication. |
| `ALOHASOCIAL_SMTP_PASSWORD` | `smtp_password` | empty | AUTH LOGIN password. |
| `ALOHASOCIAL_SMTP_ENCRYPTION` | `smtp_encryption` | `starttls` | `starttls`, `tls` (implicit TLS), or `none`. |

The `log` transport is for development. Use `smtp` in production so password
reset messages are delivered. A message that cannot be handed off returns
`503` instead of being silently dropped.

## Debugging

| Variable | Config file key | Default | Meaning |
| --- | --- | --- | --- |
| `ALOHASOCIAL_DEBUG` | `debug` | `false` | Adds exception details to error responses. Never enable in production. |

Logs go to PHP's configured `error_log` (PHP-FPM, the built-in server's
terminal, or the system journal). The worker writes to stdout/stderr, so
`journalctl -u aloha-worker -f` is the usual place to watch delivery and
cleanup jobs.

## `config/server.php`

This file returns a PHP array using the config file keys above. It is useful
for non-secret defaults and for tests. Environment values still win.

```php
<?php

return [
    'instance_url' => 'https://social.example',
    'instance_name' => 'Aloha Social',
    'instance_description' => 'A small, independent instance.',
    'contact_email' => 'admin@social.example',
    'database_dsn' => 'pgsql:host=127.0.0.1;port=5432;dbname=alohasocial',
    'mail_transport' => 'smtp',
    'debug' => false,
];
```

Do not put passwords or private keys in this file. Use `.env` or the process
environment for secrets, and keep the file out of version control changes that
are environment-specific.

## Complete production example

```dotenv
ALOHASOCIAL_INSTANCE_URL=https://social.example
ALOHASOCIAL_INSTANCE_NAME="Aloha Social"
ALOHASOCIAL_INSTANCE_DESCRIPTION="A small, open instance in the fediverse."
ALOHASOCIAL_CONTACT_EMAIL=admin@social.example

ALOHASOCIAL_DB_DSN=pgsql:host=127.0.0.1;port=5432;dbname=alohasocial
ALOHASOCIAL_DB_USER=alohasocial
ALOHASOCIAL_DB_PASSWORD=replace-with-a-strong-secret

ALOHASOCIAL_VAR_DIRECTORY=var
ALOHASOCIAL_BLOB_DIRECTORY=var/blobs
ALOHASOCIAL_SECRET_KEY_FILE=var/secret.key
ALOHASOCIAL_SECURE_COOKIES=true
ALOHASOCIAL_SESSION_LIFETIME=604800

ALOHASOCIAL_MAIL_TRANSPORT=smtp
ALOHASOCIAL_MAIL_FROM=no-reply@social.example
ALOHASOCIAL_SMTP_HOST=mail.social.example
ALOHASOCIAL_SMTP_PORT=587
ALOHASOCIAL_SMTP_USER=alohasocial
ALOHASOCIAL_SMTP_PASSWORD=replace-with-a-strong-secret
ALOHASOCIAL_SMTP_ENCRYPTION=starttls

ALOHASOCIAL_REMOTE_MEDIA=true
ALOHASOCIAL_MASTODON_API=true
ALOHASOCIAL_DEBUG=false
```

For the matching installation steps see
[Installation and setup](installation.md); for routine tasks and backups see
[Operations](operations.md).
