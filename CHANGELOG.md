# Changelog

All notable changes to the standalone Aloha Social server are recorded here.
The project follows [Semantic Versioning](https://semver.org/): the major
version changes when an operator has to change configuration or data, the
minor version when features are added without breaking an existing install,
and the patch version for fixes.

The release version is kept in `composer.json`, `ui/package.json` and the newest
entry below. `tests/VersionTest.php` fails when those three drift apart.

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
