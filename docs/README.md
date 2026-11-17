# Aloha Social server documentation

Release **0.11.2** (see [the changelog](../CHANGELOG.md)).

The application is a standalone Aloha Social host: PHP serves the accounts,
sessions, API, dashboard and the Vue social UI at `/app`; a worker handles
delivery and cleanup; the social behaviour lives in the Composer package
`alohasocial/core`.

## Start here

| I want to… | Read |
| --- | --- |
| Start with Docker in one command | [Docker installation](docker.md) |
| Install locally or in production | [Installation and setup](installation.md) |
| Understand every environment variable | [Configuration](configuration.md) |
| Run, back up, upgrade or repair an instance | [Operations](operations.md) |
| See the user-facing feature surface | [Features](features.md) |
| Understand the architecture, core checkout and test gates | [Development](development.md) |
| Understand where the frontend lives and how it is built | [Frontend hosting decision](frontend.md) |
| See the project overview and architecture | [README](../README.md) |
| Read the release history and versioning rules | [Changelog](../CHANGELOG.md) |

## The short path

Docker:

```shell
cp .env.example .env
docker compose up -d --build
```

Bare metal:

```shell
scripts/install.sh --url=http://localhost:3000 \
  --handle=alice --email=alice@example.com
php -S localhost:3000 -t public public/index.php
```

Open `http://localhost:3000/app`. Registration is intentionally disabled;
create accounts with `bin/create-account.php`. The full guide covers PostgreSQL,
Nginx, PHP-FPM, mail, the worker and verification: see
[Installation and setup](installation.md).

## What is included

- Account creation and password reset (log mail in development, SMTP in
  production)
- Sessions with rotation, CSRF protection and rate limits
- The shared Aloha Social UI: feed, composer, replies, boosts, quotes, likes,
  bookmarks, lists, filters, polls, scheduling, direct messages, collections,
  stories, memories, search, notifications, profiles and themes
- A Kokua mutual-aid board where members ask for or offer practical help,
  respond with an offer and mark the exchange as done
- A guarded terminal reset and a token-protected web setup for the public
  domain and first administrator
- An admin **Errors** tab for delivery, federation and background failures
- Image, video and audio attachments (up to ten per post) with inline players,
  a lightbox and previews in the composer
- Local and remote follower/following lists on profiles
- A host-owned administration interface at `/admin` for accounts, reports,
  federation, the job queue, instance checks and the audit log
- User-facing reporting for posts and profiles, with a moderation queue that
  can resolve, dismiss, delete or disable
- ActivityPub actor documents, WebFinger, NodeInfo, dereferenceable Note/Create documents, federation delivery and a real two-instance smoke test
- Media-only posts, alongside text, polls, replies, quotes, edits and scheduled posts
- A migration runner, a worker, cleanup and route parity checks
- A committed Vue bundle, so running the app does not require Node.js

The UI and the repository documentation are English. The interface
supports light and dark themes, a fixed desktop sidebar that does not scroll
(primary entries plus a **More** popover) and a mobile shell with five tabs and
a **More** bottom sheet. Layouts are checked down to 390 px.

## Development checks

```shell
composer test                      # PHPUnit
php bin/routes.php                 # served route manifest
npm --prefix ui run build          # rebuild public/ui/ after frontend changes
composer smoke:federation          # optional two-instance federation check
```

A frontend change is not complete until `public/ui/app.js` and
`public/ui/app.css` have been rebuilt and committed. A backend change should
keep the install, migration and worker commands above working from a fresh
checkout.
