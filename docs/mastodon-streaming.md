# Mastodon streaming

The standalone host provides an authenticated Server-Sent Events (SSE)
transport at `/api/mastodon/v1/streaming`. It follows the configured Mastodon
API prefix. The endpoint is available when `ALOHASOCIAL_MASTODON_API=true` and
requires a Mastodon OAuth bearer token; a first-party browser session is not a
stream credential.

```http
GET /api/mastodon/v1/streaming?stream=home
Authorization: Bearer <access-token>
Last-Event-ID: <event-id>
Accept: text/event-stream
```

## Supported channels

| Channel | Scope | Events delivered |
| --- | --- | --- |
| `home` | `read` or `read:statuses` | Visible public/followers posts addressed to the account by accepted local or remote follows; blocks and active mutes are applied. |
| `public` | `read` or `read:statuses` | Public status create, update and delete events. |
| `public:local` | `read` or `read:statuses` | Public status events from local accounts only. |
| `user` | `read` or `read:statuses` | The account's own status events; notification events are included only when the token also has `read` or `read:notifications`. |
| `user:notification` | `read` or `read:notifications` | Notifications visible to the account. |
| `direct` | `read` or `read:statuses` | Local direct-status events addressed to the account. Remote direct messages are not supported. |
| `list` | `read` or `read:lists` | Visible public and followers-only status events from members of the caller's private list. |
| `hashtag` | `read` or `read:statuses` | Public status events for posts carrying the `tag` query parameter's normalized hashtag. |

Open a list stream with `?stream=list&list=<list-id>`. The list must belong to
the authenticated account. Membership and followers-only access are checked
when reading and again before a status frame is written; direct statuses are
never included.

Open a hashtag stream with `?stream=hashtag&tag=<name>` (`#` optional, matched
case-insensitively against the stored normalized tags). Only public statuses
match; unlisted and followers-only posts never appear, and `status.deleted`
events are delivered while the post's hashtag rows still exist.

The host emits `update`, `delete` and `notification` SSE events. Events that do
not remain visible to the subscriber, have an unsupported projection, or fail
serialization advance the cursor without exposing their payload. 
## Reconnect and lifecycle

The event broker stores events and per-account recipient rows in the server
database. Multiple PHP workers can serve the same stream log when they share
that database. Events are retained for seven days. The server sends a keepalive
every ten seconds and closes each connection after at most 25 seconds, so
clients must reconnect using the last `id` they received:

```http
Last-Event-ID: 0123456789abcdef01234567
```

An unknown or expired cursor returns `410 cursor_expired`; reconnect without a
cursor and resync from the REST timeline. Cursors must be 24 lowercase
hexadecimal characters. Unsupported channels return `422`, malformed cursors
return `400`, and missing/expired OAuth authorization returns `401`. The
stream checks token revocation, account disable and account deletion while it
runs. Reverse proxies must disable response buffering for this path; the
response includes `X-Accel-Buffering: no`.

This is SSE only. WebSocket transport, remote direct messages and
integration against third-party Mastodon clients remain outside the current
compatibility claim. See the Core [streaming ADR](https://github.com/SchBenedikt/core/blob/main/docs/adr/0012-mastodon-streaming.md).
