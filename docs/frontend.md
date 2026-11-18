# Frontend hosting decision

- Status: accepted
- Date: 2026-11-14
- Scope: where the standalone web interface lives, how it is built and when a
  separate web repository would be justified

## Short version

The standalone web interface belongs to this repository. The host app lives in
`ui/`, its built bundle is committed under `public/ui/`, and it is mounted at
`/app` by the PHP host. That stays here.

`SchBenedikt/web` is not used for it. Checked on 2026-11-14, that public
repository contains a single commit (`b6b8776 Initial commit`) with a
`README.md` containing only `# web`; it has no application code.

The reusable presentation layer is not in this repository. It is
`@alohasocial/ui` in the upstream core repository (`SchBenedikt/core`, `ui/`).
A prebuilt, versioned copy is committed under `ui/shared-ui/`, so building this
repository does not depend on the Core Composer archive containing `ui/`.

## The three layers

| Layer | Path | Responsibility |
| --- | --- | --- |
| Shared UI | `SchBenedikt/core` → `ui/` (`@alohasocial/ui`); installed here at `vendor/alohasocial/core/ui` | Vue 3 components, composables, typed core API client and the `HostBridge` contract. No host page, session, CSRF handling, URL policy or persistence. |
| Standalone host | this repository → `ui/` (`@alohasocial/server-ui`, private) and `public/ui/` | The standalone `HostBridge`, the mount page and the committed browser build. |
| Nextcloud host | `AlohaSocial/social` | Its own Nextcloud bridge and mount. It is not part of this repository. |

The layer boundary and the `HostBridge` contract are defined by
[ADR 0005](https://github.com/SchBenedikt/core/blob/main/docs/adr/0005-shared-ui.md)
and the [shared UI guide](https://github.com/SchBenedikt/core/blob/main/docs/guides/shared-ui.md).
Accounts, sessions and CSRF are host-owned per
[ADR 0006](https://github.com/SchBenedikt/core/blob/main/docs/adr/0006-host-owned-accounts.md).

## What the current implementation actually does

- **Dependency.** `ui/package.json` declares the private host app
  `@alohasocial/server-ui` and depends on
  `"@alohasocial/ui": "file:./shared-ui"`. Vue 3 is a direct dependency; the
  shared package declares Vue as a peer dependency.
- **Build.** `npm run build` first runs `ui/scripts/prepare-shared.mjs`, which
  verifies the committed `ui/shared-ui/dist/`, then runs `vite build`.
  `ui/vite.config.ts` writes to `../public/ui` with stable names: `app.js`,
  `app.css` and `app-[name].js`. The Core marks `ui/` as `export-ignore` and
  omits it from Composer archives, so the shared package is vendored as a
  built artifact instead. A clean checkout only needs
  `(cd ui && npm ci && npm run build)`; no Core Git checkout is required.
- **Committed artifact.** `public/ui/app.js` and `public/ui/app.css` are tracked
  in Git. A deployment serves them from `public/` and does not need Node; Node
  is only required to rebuild the UI.
- **Route.** `FrontController::appPage()` serves `GET /app` through
  `resources/views/app.php`. The view mounts `#aloha-social`, references
  `/ui/app.js` and `/ui/app.css`, and passes the handle, display name, CSRF
  token and admin flag as data attributes (`data-user`, `data-display-name`,
  `data-csrf`, `data-signed-in`, `data-is-admin`). Signed-out visitors get the
  page with an empty handle; the shell still mounts and can read the public
  timeline. `GET /admin` redirects to `/app#/admin`.
- **Bridge.** `ui/src/bridge.ts` implements the `HostBridge` with
  `apiBase: '/api/v1'`. It sends the session cookie with
  `credentials: 'same-origin'`, adds `X-CSRF-Token` from the page's data
  attribute, posts logout to `/logout` with `_csrf`, and uses `/login` for
  login. The host login currently redirects to `/` after a successful
  sign-in; the `return_to` parameter built by `loginUrl()` is not consumed yet.
  In addition to the core API, the host serves the same-origin UI extensions
  for likes, interaction counts, batch post hydration, profile updates, remote
  profiles, remote post detail, remote followers/following lists, remote
  likes/boosts, cross-network search and quote resolution (see the README).
  Local follower and following lists do not need a host extension: they use the
  core's `/api/mastodon/v1/accounts/{handle}/followers|following` routes.
- **Shell and navigation.** The desktop shell is a three-column grid. Its left
  sidebar holds the user's pinned primary entries (six by default), the composer
  and a **More** popover for the library, moderation and instance pages, so it
  fits without scrolling. A settings dialog can pin, reorder and reset the
  primary entries; the choice is stored in `localStorage` under
  `aloha.sidebar.v1`.
  At 900 px and below the shell becomes one column: a sticky mobile header, the
  timeline, a five-tab bottom bar and a **More** bottom sheet for the remaining
  destinations. The composer becomes a floating button. `ui/src/style.css`
  owns the breakpoints and touch-target sizes.
- **Media.** The composer accepts images, video and audio (up to ten
  attachments). `MediaGrid` renders video with native controls and a poster
  frame and audio with a native player; the lightbox does the same. The old
  markup treated every attachment as an image, so video and audio appeared as
  broken previews.
- **Discovery.** `#/explore` searches the public timeline locally and offers a
  cross-network search over Bluesky's AppView and a configurable public
  Mastodon-compatible instance (`ALOHASOCIAL_DISCOVERY_INSTANCE`). Results are
  grouped into profiles and posts, paged by cursor, and never stored locally.
  A remote post's detail view lists who publicly liked or reposted it. Both
  use the same SSRF guard and byte limits as the profile lookup.
- **Editing.** The post menu on the signed-in account's own posts opens the
  composer in edit mode. It calls the core's `PATCH /api/v1/posts/{id}` with the
  `revision` that was read and rebroadcasts the saved record; a stale revision
  is reported as a conflict instead of overwriting the newer edit.
- **Reader features.** The host app also mounts the shared screens for
  bookmarks, search, lists, mutes, blocks, keyword filters, scheduled posts and
  enriched notifications. Those screens call the core's Mastodon-compatible
  surface under `/api/mastodon/v1` where it exists (bookmarks, search, lists,
  poll voting, notifications) and the neutral `/api/v1` routes otherwise
  (blocks, filters, scheduled posts). The Mastodon API is enabled by default in
  `HostConfig`; first-party sessions get the `['*']` scope, so no separate
  bearer token is issued for the UI.
- **Administration.** `/admin` redirects to `/app#/admin`. The host app switches
  to a dedicated admin shell when the mount page reports the admin role; the
  sections call `/api/v1/host/admin/*`, and reporting uses
  `/api/v1/host/reports`. These routes are intentionally outside the core
  manifest because they depend on host-owned accounts, sessions and tables.
- **Composer extras.** The composer can attach up to ten images, videos or
  audio files, publish a poll or schedule a post. Polls use
  `POST /api/mastodon/v1/statuses` with the `poll` object; scheduling uses
  `POST /api/v1/scheduled-posts`. The two are mutually exclusive because a
  scheduled post stores no poll.
- **CSP.** The `/app` response uses
  `script-src 'self'`, `style-src 'self' 'unsafe-inline'`,
  `img-src 'self' data: https:` and `connect-src 'self'`, so the bundle and the
  API must be served from the instance origin.
- **Static delivery.** A production web server serves `public/` directly. With
  `php -S ... public/index.php`, `public/index.php` returns `false` for an
  existing file under `public/`, so the built-in server serves the bundle.
- **Tests.** `tests/UiMountTest.php` runs in the normal PHPUnit suite
  (`composer test`). It asserts the `/app` page and its data attributes, the
  presence of `/ui/app.js` and `/ui/app.css`, markers in the committed bundle,
  and the dashboard link to `/app`. `tests/HostExtensionTest.php` covers the
  likes API, viewer-specific interaction batch, post hydration and profile
  update, including authentication and CSRF rejection.

The operational summary is also in [the README](../README.md#shared-ui).

## Decision

Keep the standalone web interface in this repository. Do not create or use a
separate `web` repository for it now.

Reasons, in order of importance:

1. **The bridge is authentication code.** It depends on this server's session
   cookie, CSRF token, `/login`, `/logout` and same-origin API. Splitting the
   mount across repositories would make every auth or CSRF change a two-repo
   release, which is exactly the coupling the `HostBridge` boundary is meant to
   contain, not spread.
2. **One deployment artifact.** `public/ui/` is committed and served by the same
   PHP application. There is no second build pipeline, no asset upload and no
   cross-repository version pin. A fresh checkout without Node can serve `/app`.
3. **Version coupling.** `composer.json` requires `alohasocial/core: dev-main`
   and `composer.lock` pins the installed commit; the installed core reports
   `0.7.0` in `vendor/alohasocial/core/src/Version.php`, and the shared UI is
   versioned with that core line (`@alohasocial/ui` 0.7.0 in the installed
   package). The server itself is released as `0.11.0` (see the changelog). At
   the time of writing, `@alohasocial/ui` is not published on the public npm
   registry (`npm view @alohasocial/ui version` returns 404), so an external web
   repository could not consume it as a normal semver dependency yet.
4. **Tests stay together.** The UI mount is covered by the same PHPUnit run as
   the server routes and the API it calls. A separate repository would add an
   integration boundary and a second test setup for one mount page.
5. **Low overhead.** The standalone layer is deliberately small: a bridge, a
   mount view and a build config. The reusable code already lives in the core
   package, so a third repository would add CI, release and issue overhead
   without removing host-specific code.

## When a separate `web` repository becomes justified

Revisit this decision when at least one of these is true:

- the frontend becomes a real SPA with its own router and many screens, rather
  than a mount page for the shared screens;
- it must be deployed independently of `server` (separate CDN, caching or
  release cadence);
- several backends should use the same web client;
- `@alohasocial/ui` is published as a semver npm package and the web app can
  depend on it normally.

If that happens, the split should look like this:

1. Publish `@alohasocial/ui` from `SchBenedikt/core` as a real npm artifact.
2. Move the standalone app (`ui/`) into `SchBenedikt/web`, consuming the
   published package instead of a `file:` path.
3. Keep the `HostBridge` host-specific, or replace it with a documented,
   versioned auth contract; the server continues to expose the API and session
   handling.
4. Decide whether `server` serves the resulting bundle or `web` deploys it
   separately, and document the chosen release artifact.
5. Move the UI mount tests to `web`; keep API contract tests in `core` and
   `server`. Shared component changes still go to `core/ui` first
   ([ADR 0005](https://github.com/SchBenedikt/core/blob/main/docs/adr/0005-shared-ui.md)).

This document changes when that decision changes; it is not a substitute for
the core ADR.

## How to verify this document

```shell
git ls-files ui public/ui
grep -n 'file:./shared-ui' ui/package.json
grep -n 'outDir' ui/vite.config.ts
grep -n 'appPage\|/app' src/Http/FrontController.php
php vendor/bin/phpunit --filter UiMountTest
php vendor/bin/phpunit --filter VersionTest
npm view @alohasocial/ui version
```
