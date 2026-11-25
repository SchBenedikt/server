# Configuration reference

Aloha Social reads configuration in this order, lowest to highest precedence:

1. built-in defaults in `src/Config/HostConfig.php`
2. `config/server.php`
3. the checkout's `.env`
4. real process environment variables
5. the setup-owned file named by `ALOHASOCIAL_ENV_FILE`, for the keys it defines

`ALOHASOCIAL_ENV_FILE` defaults to `.env`; when it points to a different file,
that file wins over the process environment. Under Docker Compose it is
`/var/www/html/var/.env`, inside the persistent `var` volume, so a domain set
in the browser survives container updates and overrides the localhost default
that Compose forwards from the host `.env`. Keys not present in the setup file
still come from the process environment or `.env`. `.env` is never committed.

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

If `ALOHASOCIAL_ENV_FILE` is set to another path, that file is loaded last and
is authoritative for every key it contains. This is how the browser setup
works in Docker without making the checkout writable. Set
`ALOHASOCIAL_ENV_FILE` only when the process that reads and the process that
writes the file agree on the path.

## Instance

| Variable | Config file key | Default | Meaning |
| --- | --- | --- | --- |
| `ALOHASOCIAL_INSTANCE_URL` | `instance_url` | `http://localhost:3000` | Public origin. Must be an absolute URL. Public origins require `https://` outside debug mode; loopback URLs may use HTTP. Actor IDs, WebFinger, media URLs, and cookies derive from it. |
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
| `ALOHASOCIAL_BLOB_DIRECTORY` | `blob_directory` | `var/blobs` | Local storage path, used when `ALOHASOCIAL_STORAGE_DRIVER=local`. |
| `ALOHASOCIAL_SECRET_KEY` | `secret_key` | empty | Base64 32-byte key for the secret box. If set, no key file is used. |
| `ALOHASOCIAL_SECRET_KEY_FILE` | `secret_key_file` | `var/secret.key` | Key file generated on first boot when no key is provided. |

The secret box encrypts the instance signing key stored in the database.
Losing the key makes that stored key unreadable, so **back up the key and the
database together**. The key file must be `0600` and owned by the application
user.

`ALOHASOCIAL_VAR_DIRECTORY` is always created if needed. The PHP user must be
able to write it. The local blob directory is created only when the local
storage driver is active.

On a host that updates the checkout in place, use an absolute data directory
outside the release, for example `/srv/aloha-data`, and point all four paths
(`var`, `blobs`, `secret key file`, `mail log`) at it. Otherwise a fresh clone,
a release swap or `git clean -fdx` can remove the SQLite database, the secret
key and uploaded media without any warning. `scripts/install.sh --data-dir=DIR`
writes this configuration.

## Object storage

Local filesystem storage remains the default. To use S3 or MinIO, set
`ALOHASOCIAL_STORAGE_DRIVER=s3`, provide a private bucket, and restart both the
web process and worker with the same configuration. The AWS SDK uses its
standard credential provider chain when both explicit credentials are empty,
so AWS deployments can use workload identity. For MinIO, set an endpoint and
usually enable path-style addressing.

| Variable | Config file key | Default | Meaning |
| --- | --- | --- | --- |
| `ALOHASOCIAL_STORAGE_DRIVER` | `storage_driver` | `local` | `local` or `s3`. |
| `ALOHASOCIAL_S3_ENDPOINT` | `s3_endpoint` | AWS default | S3-compatible endpoint URL; no user information, query or fragment. Must use HTTPS outside debug mode. |
| `ALOHASOCIAL_S3_REGION` | `s3_region` | `us-east-1` | AWS signing region. |
| `ALOHASOCIAL_S3_BUCKET` | `s3_bucket` | empty | Required bucket name when the S3 driver is active. |
| `ALOHASOCIAL_S3_PREFIX` | `s3_prefix` | empty | Optional isolated key prefix; relative path segments only. |
| `ALOHASOCIAL_S3_ACCESS_KEY` | `s3_access_key` | empty | Optional access key. Set together with the secret key; prefer deployment secrets. |
| `ALOHASOCIAL_S3_SECRET_KEY` | `s3_secret_key` | empty | Optional secret key; never returned in diagnostics. |
| `ALOHASOCIAL_S3_PATH_STYLE` | `s3_path_style` | `false` | Use path-style bucket addressing, commonly required by MinIO. |
| `ALOHASOCIAL_S3_SERVER_SIDE_ENCRYPTION` | `s3_server_side_encryption` | `AES256` for S3 | `AES256`, `aws:kms`, or `none`; encryption is set on each upload. |
| `ALOHASOCIAL_S3_KMS_KEY_ID` | `s3_kms_key_id` | empty | Optional KMS key identifier, only with `aws:kms`. |
| `ALOHASOCIAL_S3_TIMEOUT` | `s3_timeout` | `30` | Per-request timeout in seconds, from 1 to 120. |
| `ALOHASOCIAL_S3_MAX_OBJECT_BYTES` | `s3_max_object_bytes` | `268435456` | Maximum object size accepted by the S3 adapter, from 1 byte to 5 GiB. |

Keep the bucket private and grant the application only the required object
read, write, delete, list and metadata permissions. The host never sets a
public object ACL. Core media URLs remain same-origin `/api/v1/media/{id}`
routes; public/unlisted reads and authorized local followers/direct recipients
are checked before the object is read. S3 object URLs are not exposed to
clients. Remote followers-only delivery still requires authenticated remote
fetch support and is not enabled by choosing S3 storage.

To exercise the actual S3 request path against a disposable MinIO bucket, set
`ALOHASOCIAL_S3_TEST_ENDPOINT`, `ALOHASOCIAL_S3_TEST_BUCKET`,
`ALOHASOCIAL_S3_TEST_ACCESS_KEY`, and `ALOHASOCIAL_S3_TEST_SECRET_KEY` in the
test process (optionally `ALOHASOCIAL_S3_TEST_REGION`), then run
`vendor/bin/phpunit tests/Storage/MinioMediaIntegrationTest.php`. The test uses
a random object prefix and removes its objects when it finishes. Use a bucket
reserved for integration tests; the test does not alter normal storage
configuration.

The admin health view checks whether the configured bucket can be listed and
does not include endpoint credentials. For local storage it reports directory
writability and disk space; S3 usage totals are not reported as local disk
usage.

## Sessions and cookies

| Variable | Config file key | Default | Meaning |
| --- | --- | --- | --- |
| `ALOHASOCIAL_SECURE_COOKIES` | `secure_cookies` | on for `https://` instance URLs | Adds `Secure` to the session cookie. |
| `ALOHASOCIAL_SESSION_LIFETIME` | `session_lifetime` | `604800` | Session lifetime in seconds; minimum 60. |
| — | `session_cookie_name` | `alohasocial_session` | Cookie name. Useful when several instances share a hostname. |
| `ALOHASOCIAL_RUNTIME_ERROR_RETENTION_DAYS` | `runtime_error_retention_days` | `90` | Purge technical error rows older than this many days during cleanup; accepted range is 1–3650 days. Administrator audit history is retained separately. |

Cookies are `HttpOnly` and `SameSite=Lax`. Use HTTPS in production and keep the
instance URL in sync with the origin users open, otherwise secure cookies can
make login appear to fail. Changing a password revokes all sessions for that
account. Public instances require secure cookies unless debug mode is enabled;
the browser-facing scheme controls this setting, including when a reverse proxy
terminates TLS and forwards to the app over HTTP.

If the app is behind a reverse proxy, set `ALOHASOCIAL_TRUSTED_PROXIES` to a
comma-separated list of that proxy's IP addresses or CIDR ranges. Only then
will the server use `X-Forwarded-For` for login, AT Protocol login, and API throttling;
the header is ignored when the connecting peer is not on this list. Configure
the proxy to replace or append this header correctly, and never trust a broad
public range. The default is empty, which uses the direct peer address.

## Uploads and federation

| Variable | Config file key | Default | Meaning |
| --- | --- | --- | --- |
| `ALOHASOCIAL_REMOTE_MEDIA` | `remote_media` | `true` | Allows the core to fetch remote media. |
| `ALOHASOCIAL_MASTODON_API` | `mastodon_api` | `true` | Enables the Mastodon-compatible prefix. |
| `ALOHASOCIAL_DISCOVERY_INSTANCE` | `discovery_instance` | `mastodon.social` | Public Mastodon-compatible instance used for federated keyword and hashtag search. Empty disables it; Bluesky search keeps working. |
| `ALOHASOCIAL_MASTODON_PREFIX` | `mastodon_prefix` | `/api/mastodon/v1` | Compatibility route prefix. |
| `ALOHASOCIAL_ATPROTO_PUBLISH` | `atproto_publish` | `true` | Allows members to publish supported public posts to a linked AT Protocol account. The instance's native PDS is not required. |
| `ALOHASOCIAL_ATPROTO_PDS` | `atproto_pds` | `false` | Enables the optional native AT Protocol PDS hosted by this instance. |
| `PDS_FIREHOSE_MAX_CLIENTS` | — | `256` | Maximum simultaneous native PDS WebSocket clients (1–4096). Connections over the limit receive HTTP 503 before upgrade. |

The application accepts images (JPEG, PNG, WebP, GIF) up to 5 MiB, video
(MP4, WebM) up to 20 MiB and audio (MP3, M4A, Ogg, WAV) up to 10 MiB per file,
with at most ten attachments per post. The core enforces the per-kind limits;
the host does not override them. Set PHP's `upload_max_filesize` to at least
`21M` and `post_max_size` to at least `64M` if video uploads should work; a
post with several files needs room for their sum too. Set the web server's
request-body limit to at least the same value.

Federation is enabled by the host. Actor documents, WebFinger, NodeInfo
(`/.well-known/nodeinfo`, `/nodeinfo/2.0`) and inbox/outbox routes must be
served from the same origin through `public/index.php`; do not rewrite only
`/api/` and leave `/.well-known/` or `/@handle` unhandled. Post URLs negotiate
ActivityPub from the `Accept` header and the activity URLs advertised by the
outbox are publicly dereferenceable.

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
| `ALOHASOCIAL_DEBUG` | `debug` | `false` | Adds development diagnostics and allows non-loopback HTTP origins for development. Never enable in production. Boot errors stay generic in web responses; inspect the PHP error log or run `php bin/doctor.php` for details. |
| `ALOHASOCIAL_ENV_FILE` | `env_file` | `.env` | File the web setup reads and rewrites. Usually left at the default; useful for tests or a container-managed environment file. |

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

## Registration and Core version policy

Public registration is off by default. Set `ALOHASOCIAL_REGISTRATIONS=true` to
show the sign-up form. With `ALOHASOCIAL_REGISTRATION_APPROVAL=true`, a new
account is created disabled and an administrator must activate it. Both values
can also be changed in the admin console at `/admin`.

The console stores the Core dependency policy in the env file:

```dotenv
ALOHASOCIAL_CORE_CHANNEL=main
ALOHASOCIAL_CORE_VERSION=
```

`main` follows the development branch. `release` installs the tag in
`ALOHASOCIAL_CORE_VERSION`, such as `0.8.0` after that tag is published. The
admin instance diagnostics and `php bin/doctor.php --json` report the installed
Core package version and the exact locked commit reference. Apply a change on
a bare-metal install with:

```bash
php bin/core-version.php
```

The helper runs `composer require alohasocial/core:...` and the database
migrations. Docker images are immutable; rebuild the image with the chosen
Core version instead of changing the vendor tree inside a running container.

## Native AT Protocol PDS

The native PDS is optional and disabled by default:

```dotenv
ALOHASOCIAL_ATPROTO_PDS=true
ALOHASOCIAL_ATPROTO_PDS_URL=https://social.example
ALOHASOCIAL_ATPROTO_PDS_HANDLE_DOMAIN=social.example
ALOHASOCIAL_ATPROTO_PLC_URL=https://plc.directory
```

`ALOHASOCIAL_ATPROTO_PDS_URL` must be the public HTTPS origin that serves the
host, and `ALOHASOCIAL_ATPROTO_PDS_HANDLE_DOMAIN` is the suffix members get
(for example `alice.social.example`). Point a wildcard DNS record for
`*.social.example` at the instance so the handle verifier can serve
`https://alice.social.example/.well-known/atproto-did`. The instance signs PLC
operations with the key sealed by `ALOHASOCIAL_SECRET_KEY`; back that secret up
before the first identity is created. Without a wildcard record, members can
still create a DID, but handle verification and external discovery will fail.
When the native PDS is disabled, its `subscribeRepos` WebSocket endpoint also
returns `503 Service Unavailable`; enabling the PDS makes that endpoint
available through the firehose service.

A new identity is created under **Settings → AT Protocol** in the app. Rotating
the keys is available there as well. Keep the PDS disabled until the domain and
TLS certificate are reachable from the public internet.

The PDS exposes the standard `com.atproto.server.createSession`,
`getSession`, `refreshSession` and `deleteSession` XRPC methods. Members can
therefore use a normal ATProto client with their handle (or email) and account
password; the client receives Bearer tokens and can use the same
`com.atproto.identity.*`, `com.atproto.repo.*` and `com.atproto.sync.*` routes
as any other PDS. Public posts are also written into the native repository by
the background worker, so the local post and its ATProto record stay in sync.

After creating an identity and publishing a public post, verify discovery from
outside the instance with:

```bash
php bin/pds-discovery-smoke.php alice.social.example 'at://did:plc:.../app.bsky.feed.post/...'
```

This read-only check follows the public HTTPS handle endpoint, confirms the PLC
DID document points to this PDS, checks `com.atproto.server.describeServer`,
and asks the public Bluesky AppView to resolve the handle and load the post.
The final check demonstrates external relay/AppView indexing; local XRPC success
alone does not. Pass a third argument with another AppView's XRPC base URL if
you operate a separate integration target. Every destination must resolve only
to public IP addresses; the check pins those addresses for each HTTPS request so
DNS changes cannot redirect the connection to a private or local service.

## Inbound AT Protocol ingestion

Federated AT Protocol content arrives through a public Jetstream relay:

```dotenv
ALOHASOCIAL_ATPROTO_JETSTREAM=wss://jetstream2.us-east.bsky.network
```

Leave the value empty to disable the ingestion. When set, the background
worker subscribes to the relay and advances the durable event cursor stored in
`social_atproto_sync_checkpoint`. Numeric sequence cursors are preferred;
legacy messages without one fall back to `time_us`. When present,
`witnessed_at_us` supplies event freshness independently of display time.
An `OutdatedCursor` notice marks the source checkpoint for reconciliation.
The worker saves the records the instance cares about: posts and account updates from
followed DIDs, plus likes and replies that reference a post this instance
published. A reconciler then projects
those records into the same tables the timelines already read, so Bluesky
likes count on the card and Bluesky replies appear in the reply counter.

Jetstream is a global firehose. The host filters client-side by the accounts
this instance follows and by its own published records, so the amount of
stored data scales with the instance, not with Bluesky. Every projection is
keyed by the AT record URI and a tombstone removes exactly what its record
created, which makes the pass safe to repeat after a reconnect. The relay
keeps roughly a day of replayable events; a longer outage leaves the
checkpoint stale and the next successful pull sets reconciliation as required.

The same instance, registration, PDS and Jetstream values can be changed under
**Administration → Instance**; saving writes them to the configured env file
and they apply on the next request.

Run the worker under a supervisor (`docker compose` does this for you).
Without a running worker nothing is ingested, and no outbound post, reply or
like is published either.

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
ALOHASOCIAL_TRUSTED_PROXIES=127.0.0.1,10.20.0.0/16

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
