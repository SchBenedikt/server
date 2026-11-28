# Features

The standalone host and the core together provide the social features below. Installation, configuration and operations are in the linked guides.

## Shared UI

The page at `/app` is a small host app that mounts `@alohasocial/ui` through
the standalone `HostBridge` (`src` under `ui/`). The bridge attaches the session
cookie and `X-CSRF-Token`, and the shared screens consume only `/api/v1`.

The desktop shell keeps its navigation in a fixed sidebar: six primary entries,
a composer button and a **More** popover for the library, moderation and
instance pages, so the sidebar never needs to scroll. Below 900 px the shell
becomes a single column with a five-tab bottom bar and the same **More** entries
in a bottom sheet. Touch targets and media layouts adapt to small screens.

```shell
# Only needed after changing the host app. The shared @alohasocial/ui package
# is committed prebuilt under ui/shared-ui/, so no Core Git checkout is needed.
(cd ui && npm ci && npm run build)   # writes public/ui/app.js + app.css
```

The bundled `public/ui/` files are committed, so a deployment only needs the
PHP host and never runs Node. The hosting decision is recorded in
[Frontend hosting](frontend.md).

## Host UI extensions

The shared screens need several things the core's anonymous v1 timeline does not
expose directly. The host adds them as same-origin routes; they are not part of
the core route contract:

| Route | Purpose |
| --- | --- |
| `GET /api/v1/host/interactions?ids=…` | Batched favourite/boost/reply counts and viewer state for local posts and cached remote posts. For a federated post the origin's public counters are added from the engagement cache, so a card shows the real totals instead of this instance's few rows. |
| `POST` / `DELETE /api/v1/posts/{id}/boost` | Add or remove a local boost, or queue an ActivityPub Announce/Undo for a cached remote post. A cached AT Protocol post is reposted in the member's own PDS repository. |
| `POST` / `DELETE /api/v1/posts/{id}/like` | Add or remove a local favourite, or queue an ActivityPub Like/Undo for a cached remote post. A cached AT Protocol post is liked in the member's own PDS repository; without a connected Bluesky or native PDS identity the route answers `409 atproto_account_not_connected`. |
| `GET /api/v1/host/posts?ids=…` | Full post records for batch hydration, including attachments and reply parents. |
| `GET /api/v1/host/lookup?handle=…&cursor=…` | Resolve and page a public Fediverse or Bluesky profile for the profile view. |
| `GET /api/v1/host/remote-post?id=…` | Read one remote post with its parent, replies and engagement counts. The counters are cached for later cards; `bin/worker.php` refreshes them for followed posts (newest first, 15-minute TTL, bounded per tick) so a card never has to fetch the origin itself. |
| `GET /api/v1/host/updates?since=…` | Cheap change cursor for an open page: ids of posts the viewer may see that appeared or were deleted since the given timestamp, limited to the last 30 minutes. It also carries origin counter refreshes for followed posts, so an open card updates likes, boosts and replies without a reload. |
| `GET /api/v1/host/remote-connections?handle=…&type=followers\|following&cursor=…` | Page a remote profile's public followers or following list. |
| `GET /api/v1/host/remote-engagement?id=…&type=likes\|reposts&cursor=…` | Page the public likes or boosts of one remote post. |
| `GET /api/v1/host/search?q=…&type=all\|accounts\|posts&network=all\|activitypub\|bluesky&cursor=…` | Cross-network search across the Fediverse and Bluesky. |
| `GET /api/v1/host/federated?limit=…&before=…&before_id=…&tag=…` | Page the federated timeline: every public post this instance received from another server, newest first, including boosts and the origin's cached counters. `tag` narrows it to one hashtag for a federated hashtag page. |
| `GET /api/v1/host/for-you?limit=…&cursor=…` | Page the signed-in account's ranked public feed. The score mixes follows, followed tags, reactions, reading time and recency, and already-seen posts sink so scrolling keeps producing new material. Authors who disabled "Profile discoverable" leave the feed for every other reader; their own feed still shows their posts. The response also carries the top interests learned so far. The opaque cursor continues the same ranking snapshot for 30 minutes; refresh the feed after it expires. |
| `POST /api/v1/host/for-you/impressions` | Batched "I saw this" report with the milliseconds a post stayed on screen (max 60 per request). The ranker learns per-author interest from it and keeps seen posts out of later pages. |
| `GET /api/v1/host/trending?limit=…&cursor=…` | Page the instance-wide ranked public feed using the same snapshot cursor behavior. Authors who disabled "Profile discoverable" never appear here. |
| `GET /api/v1/host/kokua?kind=all\|request\|offer&category=…&state=open\|done` | Read the Kokua mutual-aid board with open/request/offer filters. |
| `POST /api/v1/host/kokua` | Ask for help or offer it. |
| `GET` / `PATCH` / `DELETE /api/v1/host/kokua/{id}` | Read, complete/reopen or remove one Kokua (author only for mutations). |
| `POST` / `DELETE /api/v1/host/kokua/{id}/help` | Offer a hand with an optional note, or withdraw the offer. |
| `GET /api/v1/host/quoted?ids=…` | Resolve the quoted note behind a quote post, local or remote. |
| `PATCH /api/v1/host/profile` | Update the display name of the signed-in account. |
| `GET /api/v1/host/sessions` | List the account's live sessions (id hash, device, network hint, current flag). |
| `DELETE /api/v1/host/sessions/{id}` · `POST /api/v1/host/sessions/revoke-others` | Revoke one session or every session except the current one. |
| `GET /api/v1/host/mfa` · `POST /api/v1/host/mfa/totp/begin` · `POST /api/v1/host/mfa/totp/confirm` · `POST /api/v1/host/mfa/totp/disable` | TOTP two-factor status, enrollment, confirmation (returns one-time recovery codes) and code-guarded disable. |
| `POST` / `DELETE /api/v1/posts/{id}/like` | Idempotent like/unlike for the signed-in account. |

The post menu on your own posts opens the composer in edit mode. Saving sends
the core's `PATCH /api/v1/posts/{id}` with the `revision` the reader loaded;
a concurrent edit answers `409` instead of overwriting it, and the updated
record is broadcast to every open timeline and detail view. Edits can keep or
replace attachments, but cannot widen a post's audience.

All writes require the session cookie and `X-CSRF-Token`. Reads only reveal
posts the viewer may already see; private posts still go through the core's
visibility rules. The implementation lives in `src/Interactions/` and
`FrontController`.

Accounts can enroll a TOTP authenticator (Settings → Two-factor sign-in).
The base32 secret is sealed with the instance key; confirmation issues eight
single-use recovery codes stored only as hashes. While enabled, `/login`
answers the password step with a five-minute pending challenge instead of a
session; `/login/mfa` accepts the current code (one step of clock drift
either side) or a recovery code, capped at five attempts per challenge.
Disabling requires a live code, and re-enrollment is refused while a factor
is enabled so a stolen session cannot strip the protection. Submissions are
rate-limited per address, each challenge dies after five wrong codes, and a
fresh password step supersedes outstanding challenges. The admin overview
reports a health warning while any active admin account lacks two-factor
sign-in.

Home offers **For you**, **Trending**, **Following**, **Local** and
**Federated**. Local is the public timeline of this instance; Federated is
every post the instance has received from other servers whose audience is
verified public, read from the remote-post cache so paging makes no peer
request and shows the origin's counters. Followers-only, unlisted and
unknown-audience rows never enter a listing; they stay readable through
direct links for viewers whose follow of the content author permits it,
the same rule `RemotePostAccess` applies everywhere else. Author display names and avatars come from the host's
`social_remote_actor_profile` cache: the worker reads each author's actor
document once with the guarded fetcher, keeps a successful profile for a week
and backs off for six hours after a failure. Until a profile is cached the card
shows the handle derived from the actor URL, so the feed never waits on a peer.
A hashtag page lists the local posts first and then the same tag from the
federated cache, so `#photography` is not limited to what was written here. Opening either view is public; liking and boosting still need an
account.

Ranked feed snapshots are stored for at most 30 minutes and scoped to the
account for **For You** or shared for **Trending**. They contain only post
identifiers and display reasons, and account erasure removes that account's snapshots.

## Account moves

Settings can announce an ActivityPub move to a destination account after that
account lists the current profile as an alias. The owner can inspect the
outbound move status at `GET /api/v1/accounts/me/move` and start it with
`POST /api/v1/accounts/me/move`. The UI requires a second confirmation because
the destination cannot be changed and new posts are blocked once the move is
announced. Follower migration continues through the background worker.

## Notification controls

Settings let each account choose whether events from mutuals, followed accounts,
followers, new accounts and other senders are allowed, held for review or
dropped. Held senders appear at the top of Notifications, where accepting
releases their events and dismissing suppresses them. A notification with a
post can also mute or resume alerts for its whole thread; the posts remain
visible.

## Kokua board

Kokua is the Hawaiian word for help, and the board is deliberately not another
feed. It is a public noticeboard where a signed-in member can ask for practical
help or offer it: a lift, a tool, a skill, company, goods or an extra pair of
hands. Every card has a category, an optional place and an optional date. Other
members can offer a hand with a short note; the author can mark the exchange as
done and reopen it later. Nothing is federated, nothing is scored and there is no
marketplace transaction.

`#/kokua` is linked from the sidebar's discovery menu, the mobile sheet and the
home rail. The board defaults to open requests and offers and can be filtered by
kind, category and completed state. Completed cards stay readable as a record of
help that actually happened.

The tables (`kokua`, `kokua_help`) and indexes are created by host migration
`202611170001`. The service is `src/Community/KokuaBoard.php`; the API is part
of `CommunityApiController`. Authors alone can complete, reopen or delete a
card, and the author cannot offer help on their own request. Offers are
idempotent: a second click updates the note instead of inflating the count.

## Reading, organising and scheduling

The core's Mastodon-compatible API at `/api/mastodon/v1` is enabled by default
in `HostConfig` (`mastodon_api`), and the standalone UI uses it for the features
Mastodon clients expect instead of duplicating federation logic in the host:

Mastodon OAuth clients can also subscribe to the standalone SSE endpoint at
`/api/mastodon/v1/streaming`. Its supported channels, scopes, reconnect cursor
and lifecycle are documented in [Mastodon streaming](mastodon-streaming.md).
The standalone supports SSE; WebSocket and third-party-client compatibility
are not implied.

Browser-based Mastodon clients (Elk, Phanpy, Pinafore and the like) call the
compatibility surface, `POST /api/v1/apps`, `/api/v2/instance` and
`/oauth/token` + `/oauth/revoke` cross-origin. Those routes answer with
`Access-Control-Allow-Origin: *` — never a reflected origin and never
`Allow-Credentials` — and `OPTIONS` preflights on them return 204 without
authentication. The session-authenticated first-party API stays same-origin
only.

`GET /api/v2/instance` aliases the v1 instance document for clients that
probe the v2 path. The compatibility prefix also serves the public instance
sub-resources Mastodon clients read alongside it:
`{prefix}/instance/peers` (domains this instance exchanges content with),
`{prefix}/instance/rules` and `{prefix}/instance/custom_emojis` (empty —
the product has no rules text or custom emoji), and
`{prefix}/instance/announcements` (+ `/{id}`) projecting the admin
announcement board into the Mastodon announcement shape.

| Screen | Route | What it uses |
| --- | --- | --- |
| Lesezeichen | `#/bookmarks` | `GET /bookmarks`, `POST /statuses/{id}/bookmark` and `/unbookmark`. Bookmark state is also rendered on every post. |
| Suchen | `#/search` | `GET /search` for signed-in local accounts, statuses and hashtags; an exact remote address resolves independently, including when signed out. Lookup failures and rate limits are shown without hiding local results. |
| Listen | `#/lists`, `#/l/<id>` | List CRUD and membership through `/lists`, and the list timeline through `/timelines/list/{id}`. Only local accounts can be members. |
| Stummgeschaltet | `#/muted` | `GET /mutes`, mute/unmute per account. Profiles can mute and unmute directly. |
| Blockiert | `#/blocked` | `GET /api/v1/blocks` and block/unblock per actor URL. Profiles can block and unblock directly. |
| Filter | `#/filters` | Full filter rules through `/api/v1/filters` with action, contexts, keywords and optional expiry. |
| Scheduled posts | `#/scheduled` | `/api/v1/scheduled-posts`; a schedule can be re-timed or cancelled, but not edited after queueing. |
| Notifications | `#/notifications` | `GET /api/mastodon/v1/notifications` with actor and status previews, plus the core dismiss/clear actions. |

### Starter packs

`#/starter-packs` collects local accounts, fediverse actors and Bluesky
identities into a named, shareable pack. Add a member by local handle,
`@name@server`, actor URL or Bluesky handle/DID; the host resolves and stores
the canonical identity, display name, avatar and — for ActivityPub — the
inbox. **Follow all** then follows each member through the same services as a
single follow: a locked local account receives an ordinary follow request, and
a Bluesky member needs a connected identity. Every signed-in member can read a
pack; only the owner can add or remove members, rename it or delete it.

The pack is a directory, not a second follow graph, so it never widens a
post's audience or bypasses a block. It is stored in `social_starter_pack` and
`social_starter_pack_member`; `GET /api/v1/host/starter-packs` returns own and
discoverable packs, and the `/members` routes manage membership.

The composer can attach a poll (2–4 options, multiple choice, hide totals,
expiry) or schedule a post. Polls are created through
`POST /api/mastodon/v1/statuses` because the neutral create route has no poll
field; poll voting uses `GET /polls/{id}` and `POST /polls/{id}/votes`. A poll
cannot be combined with scheduling, because a schedule stores no poll. First-party
sessions receive the `['*']` scope, so these routes work without a separately
issued bearer token; bearer clients keep their own scopes.

## Remote profiles and Bluesky

`#/u/<handle>` opens a profile that is not hosted here. The handle may be an
`@user@server` address, a profile URL, a Bluesky handle (`name.bsky.social`) or
a `bsky.app/profile/…` URL. The lookup is outbound and read-only:

- **ActivityPub**: WebFinger resolves the handle, the actor document supplies
  the profile and the actor's public outbox supplies the posts. Local posts
  are dereferenceable both as their `Note` URL and as the `Create` activity
  the outbox advertises; `/.well-known/nodeinfo` completes the public
  discovery surface. An instance that enables authorized fetch answers
  `401`/`403` to an unsigned actor request; for reading, the host then falls
  back to that instance's public Mastodon-compatible API (`can_follow` stays
  `false`).
- **Bluesky**: the unauthenticated public AppView supplies the profile and the
  author feed. Remote profiles remain read-only. A signed-in member can link a
  Bluesky account from Settings through OAuth and opt into publishing
  eligible public top-level posts from the composer. The action is offered
  only while the linked account and instance publishing setting are available.
  A local post is saved first; Bluesky failures are reported separately and
  can be retried from the post menu. That menu reads the saved publication
  state for the linked identity and links a published post back to Bluesky.
  Expired OAuth credentials refresh through
  the account's authorization server; if refresh is refused, reconnect the
  account in Settings. This uses the account's discovered PDS and
  does not require the instance to run its optional native PDS. Local likes
  and reposts remain local until the member opts in from Settings; Settings
  also lets members choose whether those interactions use their hosted or
  linked external identity.

A remote post opens in the same detail view as a local post. The canonical
ActivityPub object id or AT Protocol URI is encoded into `#/p/…`; the host
fetches the post live and shows its parent and replies. Mastodon instances are
read through the public status and context API, other ActivityPub servers
through the object and its `replies` collection, and Bluesky through
`app.bsky.feed.getPostThread`. The view shows like, reply and boost counts
alongside a link to the original. Opening a remote profile's follower or
following count loads that list page by page: the Mastodon API when available,
otherwise the actor's ActivityPub collection, or `app.bsky.graph.getFollowers`
and `getFollows` for Bluesky. Remote profile and post counts are shown exactly
as the origin reports them. Remote profiles, follows and replies remain
read-only; any cached public post supports local likes and boosts. A cached
ActivityPub post uses the local account's ActivityPub identity and queues
signed Like/Undo(Like) and Announce/Undo(Announce) deliveries; a cached AT
Protocol post is liked or reposted as a record in the member's connected
Bluesky or native PDS repository. Replies to an ActivityPub post use
`Create`/`Note` deliveries. A reply stores the remote object as its parent and
is threaded locally; remote engagement totals remain as reported by the
origin.

Every remote document is checked by `FederationTargetGuard` (SSRF, redirects,
DNS) and fetched with a byte limit; remote HTML is converted to plain text
before it reaches Vue, which does not use `v-html` for remote content. Remote
lookup and connections are limited to 30 requests per minute per session or
address, and remote post detail to 60.
The implementation is `src/Federation/` plus the host route in
`FrontController`.

The bundle is committed, so a fresh clone serves `/app` without Node. The
production web server serves `public/`; the PHP built-in server serves the
bundle through the router fallback in `public/index.php`.

## Administration and moderation

Accounts with the `admin` role see an **Administration** entry in the sidebar
and in the profile menu. `/admin` redirects to `/app#/admin`; the page and every
`/api/v1/host/admin/*` route require an admin session, so a normal account gets
`403` and a signed-out visitor gets `401`. The interface is host-owned because
the core deliberately has no administration API.

| Section | What it does |
| --- | --- |
| Overview | Account, post, federation, queue, report and storage counters; health checks; worker heartbeat; recent accounts, posts and audit entries. |
| Accounts | Create accounts, change display names and roles, reset passwords, revoke sessions, disable and re-enable accounts. |
| Meldungen | Open, resolved and dismissed reports with target preview, reporter, category and internal note; delete the reported post or disable the reported local account. |
| Federation | Peer domains, cached remote posts and actors, follower/following totals, active blocks and the most recent remote posts. |
| Warteschlange | Pending, running and dead jobs with payload inspection, single retry/delete, retry-all, purge of old dead jobs and an immediate worker run. |
| Instance | Effective identity, versions, feature flags, limits, paths, configuration checks and operator warnings. |
| Protokoll | Every administrative mutation with actor, action, target, details and time. |

Every signed-in reader can report a post from its action bar or a profile from
its header. A report stores the reporter, target, category (`spam`, `abuse`,
`harassment`, `misinformation`, `other`), an optional comment and the target
snapshot. Remote posts and profiles may be reported by canonical URL, `at://`
or `did:` even before they are cached; the moderation view marks them as
`remote` and links to the origin. Duplicate open reports from the same reporter
for the same target answer `409`. Resolving or dismissing a report, deleting a
post and disabling an account are written to the audit log.

```text
GET    /api/v1/host/admin/overview
GET    /api/v1/host/admin/users?query=&page=&per_page=
POST   /api/v1/host/admin/users
PATCH  /api/v1/host/admin/users/{id}
POST   /api/v1/host/admin/users/{id}/disable|enable|password|sessions
GET    /api/v1/host/admin/reports?state=open|resolved|dismissed|all
POST   /api/v1/host/admin/reports/{id}/resolve
DELETE /api/v1/host/admin/posts/{id}
GET    /api/v1/host/admin/jobs?state=
POST   /api/v1/host/admin/jobs/{id}/retry
DELETE /api/v1/host/admin/jobs/{id}
POST   /api/v1/host/admin/jobs/retry-dead|purge
POST   /api/v1/host/admin/queue/run
GET    /api/v1/host/admin/federation|communications|instance|audit
POST   /api/v1/host/reports
```

The self-protection rules are intentional: an admin cannot disable their own
account or remove the last active admin. Those requests answer `409`, as does
any attempt to change state that a concurrent moderator already changed.
Disabling an account, resetting its password, or revoking its sessions also
revokes native AT Protocol and Mastodon OAuth credentials; re-enabling an
account does not restore previously revoked tokens. The moderation tables
(`moderation_report`, `admin_audit`) and the worker
heartbeat (`runtime_state`) are created by host migration `202611150001`.
Warnings and failures from the worker and federation layer are mirrored into
`runtime_error` by the runtime logger; the admin **Errors** tab shows them next
to dead delivery jobs. The table is created by migration `202611170002`.

Post actions — reply, boost, quote, like, bookmark, share, report and open —
render as icons with accessible labels. Counts appear only when they are greater
than zero, so a quiet post stays quiet without losing the action. The quote icon
is a closed glyph that stays legible at 15–18 px.

Attachments support images (JPEG, PNG, WebP, GIF), video (MP4, WebM) and audio
(MP3, M4A, Ogg, WAV), up to ten per post. Images render as a grid and open in
the lightbox; video plays inline with native controls and its poster frame;
audio uses a native player. The composer previews each kind before upload, and
quoted posts render their first attachment with the same rules. The limits are
5 MiB for images, 20 MiB for video and 10 MiB for audio, but PHP's
`upload_max_filesize` and `post_max_size` still cap what reaches the app.

Followers and following on a **local** profile open the same list dialog as a
remote profile. The list is read from
`/api/mastodon/v1/accounts/{handle}/followers|following`; remote profiles keep
their existing public ActivityPub/Bluesky lookup.

## Account export and deletion

Account export and deletion are available in Settings. Export combines
host-owned account data with the Core archive, including available media
blobs. The host export includes profile email, pending follow requests,
featured tags, stories and views, announcements, reading lists, drafts, pinned
and archived posts, Kokua posts and replies, recommendation feedback and the
implicit For You impression log (seen time, dwell time and count). Password
hashes, session tokens, OAuth secrets, and private signing keys are never
exported.

Deletion asks for a second confirmation, revokes local and AT Protocol
credentials, removes host-owned account content and linked credentials, and
preserves a disabled identity as a tombstone with scrubbed account details. Its
stable internal account id remains so deletion and moderation records can be
audited; email, display name, handle, and password verifier are cleared or
replaced with random, non-routable values. Moderation reports and administrative
audit records remain for accountability and retain that internal id. Technical
error-log rows are purged according to `ALOHASOCIAL_RUNTIME_ERROR_RETENTION_DAYS`;
they do not replace the administrator audit trail. The last active administrator
cannot be deleted. Once deletion begins, writes through
host-only APIs and native PDS sessions are blocked too; a repeated deletion
request can resume cleanup after an interrupted attempt. Operator-managed web
server and proxy logs follow their own retention policy. Remote copies and
remote AT Protocol data are outside this instance's control and may remain on
peer servers.

## Reading lists and text stories

Both surfaces now persist in the core tables; there is no second host table
to drift. **Reading lists** use `social_media_collection` and
`social_media_collection_item`, powering `#/collections` and `#/c/:id`.
Public lists use the real account as `owner_id` and `audience = public`.
Private lists use the reserved `host-private:` owner namespace with
`audience = followers`; the core services cannot enumerate or dereference
that namespace, and the adapter maps it back to the real owner on every read.
This adapter is necessary because the core media-collection service is
strictly media-only (own image/video posts), while a reading list may contain
any post the owner can read, including text and posts by other authors. The
adapter keeps the core `PostService` as the visibility authority and rechecks
every item on read. Migration `202611280008` copies every collection, item,
title, description, visibility, timestamp and position into the core tables
and drops `collection`/`collection_item`.

**Text stories** use `social_story` and `social_story_view`: the text is the
core caption, the colour background is a generated background URL, and the
view marker is the core story view. The core story entity and repository are
used for writes; the adapter supplies the text/background behaviour that the
core media-story service does not model. Stories remain 24-hour local stories
visible to the author and local followers; they are not federated. Migration
`202611280008` copies every story and view marker into the core tables and
drops `story`/`story_seen`.
