# Aloha Social server documentation

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
| Understand where the frontend lives and how it is built | [Frontend hosting decision](frontend.md) |
| See the project overview and architecture | [README](../README.md) |

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
- The shared Aloha Social UI: feed, composer, replies, boosts, likes, media
  uploads, profiles, profile editing, search, notifications and themes
- A host-owned administration interface at `/admin` for accounts, reports,
  federation, the job queue, instance checks and the audit log
- User-facing reporting for posts and profiles, with a moderation queue that
  can resolve, dismiss, delete or disable
- ActivityPub actor documents, WebFinger, federation delivery and a smoke test
- A migration runner, a worker, cleanup and route parity checks
- A committed Vue bundle, so running the app does not require Node.js

The UI is German; the repository documentation is English. The interface
supports light and dark themes and a 390 px mobile layout.

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
