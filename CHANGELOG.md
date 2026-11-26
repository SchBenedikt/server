# Changelog

All notable changes to the standalone Aloha Social server are recorded here.
The project follows [Semantic Versioning](https://semver.org/): the major
version changes when an operator has to change configuration or data, the
minor version when features are added without breaking an existing install,
and the patch version for fixes.

The release version is kept in `VERSION`, `ui/package.json` and the newest
entry below. `tests/VersionTest.php` fails when those three drift apart.

## Unreleased

### Added

- **Starter packs:** members can bundle local accounts, fediverse actors and
  Bluesky identities into a named pack and share it. A pack shows each
  member's network and display profile, and **Follow all** follows the members
  through the normal local, ActivityPub and AT Protocol follow services. Packs
  live in `social_starter_pack` and `social_starter_pack_member`; every signed-in
  member can read a pack, only the owner can edit or delete it.
- **Replies to remote posts:** federated posts can now be answered from the
  composer. The reply is stored locally with the remote object URL as its
  parent and delivered as a signed `Create` with `inReplyTo` to the parent
  author, while the local thread view keeps showing it. The reply-target
  column is widened for PostgreSQL; SQLite already stores the URL.
- **Cached remote author profiles:** the federated timeline and its hashtag
  view show the origin display name and avatar of each author. A host-side
  cache (`social_remote_actor_profile`) is filled by the worker from one
  guarded actor request per author, refreshed weekly with a six-hour failure
  backoff. A post without a cached profile falls back to its derived
  `@name@host` handle.
- **Federated timeline and federated hashtags:** Home now separates **Local**
  from **Federated**, and the federated view pages every public post this
  instance received from other servers out of the remote-post cache, including
  boosts, cached origin counters and media. Hashtag pages add the same tag from
  that cache below the local timeline. A federated page costs one bounded
  database query and makes no peer request.
- **Mastodon-compatible OAuth:** clients can register, authorize with PKCE,
  rotate refresh tokens and revoke access. OAuth scopes are enforced on API
  routes and account security changes invalidate outstanding credentials.
  See [the OAuth guide](docs/mastodon-oauth.md) for supported flows and
  current client limitations.
- **Federated engagement counters:** opening a remote post keeps the
  origin's public like, boost and reply totals, and every card for that post
  shows them next to the local viewer state instead of zero.
- **Live page updates:** an open page polls a cheap change cursor and adds new
  posts or drops deleted ones in place, without reloading the timeline.
- **Learning For You feed:** the ranked timeline now learns from likes,
  boosts, bookmarks, replies and the time posts stay on screen, decays old
  interests, keeps already-seen posts out of later pages and reports which
  tags it learned. The feed loads further pages while scrolling instead of
  waiting for a button.

### Fixed

- **Liking and boosting external posts:** an AT Protocol card rendered its like
  action as a static count because the card only accepted HTTP targets, and the
  AT interaction policy refused every post whose author was not followed. Both
  are fixed: a cached Bluesky post can now be liked or boosted from the
  federated timeline, and a missing Bluesky identity answers with a clear
  "connect an account" message instead of a generic failure.
- **Local follows in stories, live updates and For You:** a local follow is a
  follower edge in `social_follower` with the follower's actor URL, not a
  `social_following` row. Host queries that only read the remote table, or
  compared actor URLs with local handles, made followed local accounts vanish
  from the story bar and the live cursor and removed their For You bonus. A
  shared `SocialGraph` now resolves both id spaces correctly.
- **Pins and archives have one source of truth:** host pins moved from
  `pinned_post` to the core's `social_post_pin`, so the UI and the ActivityPub
  `featured` collection agree. Archives moved from `archived_post` to
  `social_post.archived_at`, so core statuses, search and
  `/api/v1/posts/archived` see the same state. Migration `202611280007` copies
  existing valid rows and drops the duplicate tables.
- **Complete account export:** export now includes core pins, archived posts
  and the For You impressions (seen time, dwell time and count) that erasure
  already deleted.
- **One storage path for reading lists and text stories:** reading lists moved
  from `collection`/`collection_item` to the core `social_media_collection`
  tables, and text stories moved from `story`/`story_seen` to
  `social_story`/`social_story_view`. The adapter preserves arbitrary-post and
  owner-only reading lists, and text/background stories, without the core's
  media-only restrictions. Migration `202611280008` copies every row, item
  position and view marker and drops the duplicate tables. Additive migration
  `202611280009` removes view markers for deleted host stories before a
  legacy rollback and repairs existing markers on upgrade; migration
  `202611280008` remains byte-identical and keeps its recorded checksum. The
  migration runner also drops legacy views that collide with an unrelated core
  story id, and a deleted host story removes its view markers.
- **S3 upload streams:** the adapter now declares the wrapped stream property
  explicitly, removing the PHP dynamic-property deprecation emitted by the
  Guzzle stream decorator during uploads.
- **Installer on statically built PHP:** the preflight and DSN checks accept
  PDO drivers reported by `PDO::getAvailableDrivers()`, not only a separately
  loadable `pdo_sqlite`/`pdo_pgsql` extension.
- **Runnable test command:** `phpunit.xml` raises the test process limit to
  512 MB, so the documented `composer test` no longer dies at PHP's 128 MB CLI
  default while building many throwaway runtimes.

### Changed

- The development and frontend guides now name the actual core line (`0.8.0`)
  and the current server release instead of the stale `0.7.0`/`0.11.0` pair.

## [0.12.0] - 2026-11-18

### Added

- **Standalone administration console:** `/admin` is now a separate, dense
  page with its own CSS and JavaScript. It no longer renders inside the social
  cards, so instance administration has the full desktop width and the app
  sidebar links straight to it.
- **Public registration switch:** administrators can enable or disable public
  registration and choose whether new accounts need approval. Both values are
  persisted in the env file. With approval enabled, a new account starts
  disabled until an administrator activates it.
- **Core version policy:** the admin console stores whether the instance follows
  the Core `main` branch or a tagged release, plus the version to install.
  `php bin/core-version.php` applies the policy with Composer and runs the
  database migrations. Docker installations rebuild the image with the chosen
  version.
- **Native AT Protocol identity:** an optional built-in PDS lets every member
  create their own DID and handle on the instance, rotate keys, and use it
  alongside a separate Bluesky connection. The host serves
  `/.well-known/atproto-did` for HTTPS handle verification.
- **Following and follower lists:** the profile counters open lists of local
  and remote accounts, including accepted remote followers.
- **Remote follow approval:** pending Follow activities from remote accounts
  appear in the same request list as local requests and can be accepted or
  rejected there; the core queues the signed answer.
- **Accurate NodeInfo:** `activeMonth` and `activeHalfyear` count distinct
  users who posted or signed in during the window, the software version is the
  standalone server release, and the Core version is reported separately.
- **Docker:** the final image ships Composer so the version helper can run
  during maintenance.

### Changed

- **Mobile layout:** under 600 px, feed posts use flat rows separated by a line
  instead of floating cards, leaving more room for content. Page-level cards
  keep a consistent outer margin.
- **Remote follow policy:** a locked account now advertises
  `manuallyApprovesFollowers` and receives remote Follow activities as pending
  requests instead of being followed immediately.
- **Security:** inbound interaction counts are only returned for posts the
  caller may read; Bluesky PDS discovery requires HTTPS and a DID match and
  refuses private-network targets; a LICENSE and a security policy are now
  part of the repository, and CI runs lint, tests and the UI build.

### Fixed

- **Like counter for remote likes:** inbound Likes stored in the core reaction
  table are added to the local favourite count, so a Like from another instance
  increases the number shown on the post.
- **Sign-in return path:** an anonymous visit to `/admin` returns there after
  signing in instead of dropping the member on the timeline.
- **Reply counts:** follower-only and direct replies no longer contribute to
  an anonymous interaction count, and a private parent post is omitted.
- **Profile updates:** changing the display name or locked setting now queues
  a signed ActivityPub `Update` for accepted followers.
- **Registration abuse:** sign-up attempts are rate limited per address.

## [0.11.6] - 2026-11-18

### Added

- **Complete profile timelines:** local profiles now read
  `GET /api/v1/accounts/{id}/statuses` instead of scanning the newest public
  posts. Older posts no longer disappear once 120 newer posts exist, and a
  signed-out visitor receives exactly the public and unlisted rows the viewer
  may see.
- **Nextcloud API compatibility:** the Mastodon-compatible routes are also
  served under `/api/v1` when that path is not already a native route. This
  covers statuses, bookmarks, lists, mutes, filters, scheduled posts, polls and
  media for Nextcloud Social clients. Native routes keep precedence.

### Changed

- Core updated to `c9a5f91` for the profile timeline and compatibility alias
  layer.
- Release `0.11.6`.

## [0.11.5] - 2026-11-18

### Fixed

- **Mentions in posts:** `@user@host` in the text is resolved through
  WebFinger and the actor document. The Create is delivered to the mentioned
  actor's inbox even when they do not follow the author, and the note carries
  the standard `Mention` tag plus the actor in `cc`, so the peer can link and
  notify the account. A plain `@user` notifies a local account.
- **Mention lookup failures:** an unreachable or unknown mention is skipped;
  the post itself is still stored and published.

### Changed

- Core updated to `39e33bd` for mention parsing, delivery and tags.
- Release `0.11.5`.

## [0.11.4] - 2026-11-18

### Fixed

- **Following lists behind a CDN:** API, profile and ActivityPub responses now
  send `Cache-Control: no-store` and the Cloudflare CDN header. A cached
  profile could keep advertising `localhost` and `following_count: 0` hours
  after the domain and the remote follows were already stored correctly.
- **Own following list:** the following and follower counters on a local
  profile open the connection list again, and the list includes accepted
  remote accounts from `social_following`, not just local accounts.
- **Actor and WebFinger URLs:** the actor document advertises its canonical
  `url`, WebFinger accepts Unicode IDN handles as well as their `xn--` form,
  and the instance metadata reports the installed core version instead of
  the core's `0.1.0` default.

### Changed

- Core updated to `b18d6aa` for the actor `url` and IDN WebFinger fixes.
- Release `0.11.4`.

## [0.11.3] - 2026-11-18

### Fixed

- **Web setup persistence in Docker:** setup now writes
  `/var/www/html/var/.env` inside the persistent `var` volume instead of a
  container-local `.env`. A configured domain survives image updates,
  `docker compose up -d` and container recreation.
- **Domain overrides:** Compose no longer pins `ALOHASOCIAL_INSTANCE_URL`
  (or the SQLite DSN), and the setup-owned file now outranks the host `.env`
  values Compose forwards as process environment. Actor IDs, media URLs and
  federation use the public origin configured in the browser instead of a
  localhost default.
- **Configuration reloads:** a long-running PHP process no longer treats its
  own previously loaded dotenv values as real environment variables. Editing
  `.env` or completing setup takes effect on the next request without an FPM
  or Apache restart, while genuine process variables keep the highest
  precedence.
- **Media-only composer:** a newly selected image, video or audio file now
  enables the Post button without requiring text, matching the core rule that
  allows attachments without a caption.

### Changed

- Release `0.11.3`.

## [0.11.2] - 2026-11-17

### Fixed

- **Media-only posts:** core `5879c32` accepts a post with attachments and no
  text, matching the composer. Empty posts without media are still refused,
  and an edit cannot remove the last attachment while leaving the text empty.
- **Federation smoke:** the two-instance test now selects a globally routable
  local address by default, so the production SSRF guard stays active and the
  signed Follow, Accept and Create are delivered end to end. Set
  `ALOHASOCIAL_SMOKE_HOST` to override the address.

### Changed

- Release `0.11.2`.

## [0.11.1] - 2026-11-17

### Fixed

- **ActivityPub post discovery:** the activity URLs the outbox advertises
  (`/api/v1/posts/{id}/activity`) now return the signed `Create` document, and
  the Note URL negotiates a `Note` document when a peer sends an ActivityPub
  `Accept` header. Peers that dereference a post no longer receive the
  internal API JSON or a 404.
- **NodeInfo:** `/.well-known/nodeinfo`, `/nodeinfo/2.0` and `/nodeinfo/2.1`
  now advertise ActivityPub, the software and the local public post count.
- **Federation smoke:** the test now runs the worker that sends the queued
  Follow and Accept instead of asserting a follower edge that was never
  delivered.

### Changed

- Release `0.11.1`.

## [0.11.0] - 2026-11-17

### Added

- **Terminal reset:** `php bin/reset.php` creates a verified backup, drops all
  application tables, rebuilds the empty schema, clears media and mail by
  default and prints a one-time setup URL. `--yes`, `--keep-media` and backup
  controls are available for scripted and cautious use.
- **Token-protected web setup:** after a reset, `/setup?token=…` writes the
  final public origin, instance identity, contact address and first admin to
  `.env`. The token is single-use and setup is closed as soon as an account
  exists, so an empty database alone cannot be claimed by a visitor.
- **Admin Errors view:** runtime warnings and failures are mirrored from the
  PSR logger into `runtime_error`, with source, level, message, context and
  time. The new admin tab also lists dead delivery jobs and is included in
  system health.
- Migration `202611170002` creates `runtime_error`.

### Changed

- A failed boot now returns a readable HTML maintenance page (HTTP 503) for
  browser requests instead of raw JSON; API clients still receive
  `server_not_ready`.
- The runtime logger keeps the PHP error log but no longer relies on shell
  access for the admin-visible operational picture.

## [0.10.0] - 2026-11-17

### Added

- **Kokua board** at `#/kokua`: a public mutual-aid noticeboard for local help.
  Members can ask for help or offer it, choose a category, place and optional
  date, and respond to a card with a short note. Authors can mark an exchange
  as done, reopen it or remove it. The board filters by request/offer,
  category and completed state, and is linked from the sidebar, mobile sheet
  and home rail.
- Kokua schema migration `202611170001` with `kokua` and `kokua_help`, plus
  the first-party `/api/v1/host/kokua` routes for reading, creating, offering
  help, withdrawing, completing and deleting cards.
- End-to-end tests for visibility, filtering, idempotent offers, author-only
  completion/deletion and validation; the UI bundle test now checks the Kokua
  markers.

## [0.9.1] - 2026-11-17

### Added

- The interactive installer now asks for the data directory and mail
transport (log or SMTP), offers the systemd/Nginx templates, shows a complete
summary and runs `bin/doctor.php` before declaring success. `--no-verify`
skips the final check for scripted setups.
- Installer preflight validates the public URL, database driver and DSN, admin
handle, email, password length and SMTP settings before writing `.env` or
migrating the database.
- New installer tests cover a fresh install, an unchanged rerun, the
interactive defaults and a data-directory move with accounts, key and media.

### Changed

- Re-running the installer never replaces existing URL, instance name or
database values with built-in defaults. The wizard uses the current `.env`
(and real environment) for every prompt, so pressing Enter keeps the running
instance unchanged.
- `scripts/install.sh --data-dir=DIR` now actually moves SQLite to `DIR`.
On an existing installation it copies the database with `VACUUM INTO`, the
secret key, blobs and mail before switching the paths; the old copy stays in
place until the operator removes it.
- The installer creates and permission-checks the configured var, blob, key
and mail directories instead of always creating `var/blobs` and `var/mail`.
- `--force` keeps the existing database DSN, credentials and runtime paths
when it regenerates `.env`, so resetting the file cannot hide accounts.

### Fixed

- `--data-dir` previously wrote the new var/blob/key/mail paths but left
`ALOHASOCIAL_DB_DSN` at `sqlite:var/server.sqlite`, so the database and all
accounts stayed in the release directory.
- The generated Nginx template now allows the documented 64 MiB request body
and includes the actual service user and group. A backtick in the final
installer message was executed by the shell, and an HTTPS instance was offered
an unusable `php -S …:443` command.
- `bin/doctor.php` now checks the SQLite file and mail log directory for
writability, validates the configured secret key and counts SQLite and
PostgreSQL backups together.
- Installer `.env` backups are ignored by Git so a rollback copy cannot be
committed by accident.
- Database and SMTP passwords are written through standard input instead of
being exposed briefly as process arguments.

## [0.9.0] - 2026-11-16

### Added

- Guarded update command `bin/update.php`: it creates a verified database
  backup before a pending migration, applies only additive migrations and
  compares the account count before and after. A missing backup stops the
  update; a shrinking account count prints the exact restore command.
- `bin/backup.php` writes a consistent SQLite snapshot (`VACUUM INTO`) or a
  PostgreSQL custom-format dump, verifies it, records the account count and
  schema version in a JSON sidecar, and keeps the newest ten by default.
- `bin/doctor.php` reports the database path, schema state, pending
  migrations, account count, secret-key presence, writable runtime paths and
  the newest backup without printing secrets.
- `scripts/install.sh --data-dir=DIR` keeps the database, key, blobs and mail
  outside the checkout, so replacing or cleaning a release directory cannot
  delete a running instance.
- Adopted the newer core schema: post pins, media collections, account moves,
  moderation reports and actions, held posts, stories, notification policies
  and sender requests, conversation mutes, recommendations, discovery index
  and trends, inbound AT Protocol sync, account-deletion tombstones, the
  optional native PDS and the new follower, notification and post columns.

### Changed

- The Docker entrypoint now runs `bin/update.php` instead of the low-level
  migration runner, so SQLite deployments get the backup and account check on
  every image update.
- The installer and deployment guides use the guarded update path and explain
  how to keep accounts across code changes, including the `docker compose
  down -v` data-loss trap.
- The core dependency is pinned to `fce4ca2`, which adds account export and
  durable deletion alongside the schema above.

### Fixed

- The 0.8.0 host did not create the core tables added after its pinned
  revision, so profile pages, public timelines, collections and pinned-post
  writes failed with `no such table` after the core update.
- A bare-metal update could silently point at a new SQLite file when the
  release directory or data path changed; the data-directory option and the
  doctor make the active path explicit.
- Rebuilding the UI against the newer core failed because its Composer dist
  archive omits `ui/`. The build now prepares the shared package automatically
  and explains the `--prefer-source` fix when only the archive is installed.

## [0.8.0] - 2026-11-16

### Added

- Local profiles now open their followers and following lists, read from the
  core's Mastodon-compatible account endpoints. Remote profiles keep the
  existing public connection dialog.
- The composer accepts and previews images, video and audio, up to ten
  attachments per post. Images allow 5 MiB, video 20 MiB and audio 10 MiB,
  subject to the PHP upload limits configured by the operator.
- Video attachments render with native controls and their poster frame; audio
  attachments render with a native player. The lightbox and quoted posts use
  the same media rendering.
- Explore gained a Videos section built from the newest public posts that carry
  a video attachment.
- The desktop sidebar has a "More" popover for library, moderation and instance
  pages, so the navigation fits on one screen without scrolling. A settings
  dialog lets each user pin, reorder and reset the primary entries; the choice
  is stored locally.
- The mobile shell now uses five primary tabs and a "More" bottom sheet. The
  previous bar tried to render twelve entries in a fixed-height row.
- Remote profiles read the viewer's follow edges and show whether a follow is
  pending or accepted, with an unfollow action. The state survives a reload.
- Stories open in a full viewer with auto-advance, keyboard navigation and a
  progress bar, and adapt to small screens.
- The complete interface, including the server-rendered sign-in, password and
  error pages and all API error messages, is English-only.

### Changed

- The README and all guides document the 0.8.0 release, the current core pin and
  the media and navigation behaviour.
- The quote action uses a complete quote glyph instead of an open, easily
  clipped path.

### Fixed

- The quote icon was drawn with two unclosed paths that appeared cut off at
  small sizes.
- Video and audio uploads were accepted by the core but displayed as broken
  image previews in posts, quoted posts, the composer and the lightbox.
- The mobile navigation overflowed the viewport on phones.

## [0.7.0]

Initial documented release line of the standalone host: accounts, sessions,
migrations, ActivityPub, account mail, the shared Vue UI, host UI extensions,
community features, administration and the federation smoke test.
