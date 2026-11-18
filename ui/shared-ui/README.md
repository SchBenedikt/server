# @alohasocial/ui

Shared Vue 3 components, composables and the `HostBridge` contract for the
Nextcloud and standalone Aloha Social hosts. The package is a separate npm
artifact under `ui/`; it never imports a host, OCP, a server runtime or a
database.

See [ADR 0005](../docs/adr/0005-shared-ui.md) and the
[shared UI guide](../docs/guides/shared-ui.md).

## What is here

- `HostBridge`: the only host boundary. It owns `apiBase`, authentication,
  CSRF, origin policy and navigation; the package owns presentational
  components and typed core API reads.
- `createAlohaUi({ bridge })` and `useHostBridge()`: one bridge provided at the
  host page boundary through Vue dependency injection. The bridge may omit
  `contractVersion` for contract `1`; an explicitly newer contract, a missing
  method or an incomplete `capabilities()` object fails with an upgrade error.
- `CoreApi`: typed reads and writes over the core HTTP contract, including the
  documented JSON error shape, the public-timeline `before`/`limit` cursor and
  the notification id cursor.
- `PublicTimeline` (public or `tag`-scoped), `PostDetail` and `PostCard`: the read-only shared screens.
  The timeline covers loading, empty, error, retry and load-more states; the
  detail screen reads one existing post by id and covers loading, not-found,
  error and retry states.
- `ActorHeader`, `PostComposer` and `NotificationList`: the shared write
  screens. The actor header loads a local profile and toggles the follow edge;
  the composer writes a post with visibility and content warning; the
  notification list pages unread events and dismisses or clears them. Each
  uses the neutral `/api/v1` routes and covers signed-out/401 states.
- `FakeHostBridge`: a deterministic, network-free bridge for component tests.

The package does not add a host page, a Nextcloud bridge or a standalone
bridge; those stay in the host repositories. It has no persistence layer,
schema or migration: screen state lives in the components and composables and
is read from or written to existing manifest paths.

The package is not on npm yet. `npm pack --dry-run` builds and lists the
tarball; the verified artifact, `files`/`exports`/`publishConfig` and the
registry credential blocker are documented in
[release artifacts](../docs/operations/release.md).

## Version compatibility

`ui/package.json` is the UI package's version authority; `UI_VERSION` in
`src/version.ts` is the value emitted in the build. `CORE_API_VERSION` mirrors
the core's `ApiVersion::CURRENT` (`v1`) and `HOST_BRIDGE_CONTRACT_VERSION` is
`1`. The core and UI are released on the same line (currently `0.7.x`; for 0.x
the minor line is breaking). `ui/tests/compatibility.test.ts` checks the
package, lockfile and constants and tests both a compatible bridge and the
rejection of an incompatible one. The full matrix, including the not-yet-
existing hosts, is in the
[host integration guide](../docs/guides/host-integration.md#compatibility).

## Development

```bash
npm ci
npm run typecheck
npm test
npm run build
npm pack --dry-run
```

`npm test` includes a route-parity test that invokes
`php ../bin/export-routes`, so the repository's PHP dependencies must be
installed (`composer install` at the repository root) when running that test.

## Host wiring

The host provides one bridge and mounts the shared app:

```ts
import { createApp } from 'vue';
import { createAlohaUi } from '@alohasocial/ui';
import App from './App.vue';
import { bridge } from './bridge';

import '@alohasocial/ui/style.css';

createApp(App).use(createAlohaUi({ bridge })).mount('#aloha-social');
```

A component uses `useHostBridge()` or `useCoreApi()`; it never calls `fetch()`,
reads `OC`, reads a session global or learns the host's URL prefix. The bridge
must not expose a token to component code. A host that targets a future
HostBridge surface declares `contractVersion`; omitting it selects the current
contract `1`.
