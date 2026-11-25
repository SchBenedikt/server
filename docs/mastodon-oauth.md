# Mastodon OAuth support

The standalone host exposes a first-party OAuth 2 authorization-code flow for
the Mastodon-compatible API when `mastodon_api` is enabled. Client registration
is `POST /api/v1/apps`; authorization uses `GET` and `POST /oauth/authorize`;
code exchange and refresh use `POST /oauth/token`; and `POST /oauth/revoke`
retires one access or refresh token. Requests accept JSON or
`application/x-www-form-urlencoded` bodies where Mastodon clients commonly
send them.

Authorization requires an exact registered redirect URI, a non-empty `state`,
and PKCE with `code_challenge_method=S256`. HTTPS redirects and native app URI
schemes are supported; plain HTTP is limited to loopback. Redirect fragments,
credentials and unsafe schemes are rejected. Users must sign in and explicitly
approve the client. Denial returns `access_denied` to the registered URI with
the original state.

The host stores only hashes of client secrets, authorization codes, access
tokens and refresh tokens. Authorization codes expire after five minutes and
are consumed once. Access tokens expire after 30 days; refresh tokens expire
after 90 days and rotate on use. Reusing a refresh token fails. Revocation,
password reset, account disable and account deletion invalidate the account's
tokens. Bearer credentials are accepted on the configured Mastodon API prefix.
The standard `/api/v1/timelines/home` path also accepts OAuth bearer tokens. The host
dispatches OAuth bearer requests to the Mastodon-compatible status-list route;
first-party cookie sessions continue to use the native timeline route, receive
full access and keep CSRF protection. Core route scope middleware receives the
scopes granted at consent.

## Streaming API

OAuth clients can open a Server-Sent Events connection at
`/api/mastodon/v1/streaming?stream=<channel>` or the standard alias
`/api/v1/streaming?stream=<channel>`. Supported channels are `public`,
`public:local`, `home`, `user`, `user:notification`, `direct`, and `list`. A
list stream uses `stream=list&list=<list-id>` and is limited to the caller's
own list. The endpoint requires a bearer token with `read:statuses`;
`user:notification` instead requires `read:notifications`, and `list` requires
`read:lists`. Notifications are also sent on `user` when the token has
`read:notifications`.

Frames use Mastodon's `update` and `notification` event names with JSON data.
`Last-Event-ID` resumes after a retained event; `last_event_id` is accepted as
a query fallback. The server retains replay events for up to seven days, reads
at most 100 events per poll, checks token/account status once per poll, and
closes each connection after 25 seconds. Clients should reconnect with the
last event ID and use the REST timeline or notification endpoint to resync if
the cursor has expired. Keepalive comments are sent every ten seconds. Hashtag
and WebSocket streams are not currently supported.

The supported scopes are the scopes declared by the current Core Mastodon
route manifest (`read`, `write`, `follow`, and its implemented granular
`read:*`/`write:*` scopes). Unsupported scopes fail registration or
authorization instead of being silently granted. The compatibility API remains
a subset: see [Mastodon API compatibility](features.md#mastodon-compatible-api)
and [Core issue #29](https://github.com/SchBenedikt/core/issues/29) for route
parity. This OAuth flow does not claim that every current Mastodon client can
use every API endpoint.

## Client flow

1. Register `client_name`, `redirect_uris`, requested `scopes`, and optional
   HTTPS `website` at `POST /api/v1/apps`.
2. Open `/oauth/authorize` with `response_type=code`, `client_id`, an exact
   registered `redirect_uri`, requested `scope`, random `state`, a high-entropy
   `code_challenge`, and `code_challenge_method=S256`.
3. After the user approves, exchange the returned `code` at `/oauth/token` with
   the matching `redirect_uri`, `client_id`, `client_secret` (when retained by
   the client), and `code_verifier`.
4. Send the access token as `Authorization: Bearer …` to the configured
   Mastodon API prefix. `/api/v1/timelines/home` is also supported for clients
   using the standard home timeline path. Use the returned refresh token to rotate credentials;
   send either token to `/oauth/revoke` to revoke the grant.

The OAuth endpoint integration tests cover registration validation, PKCE,
redirect matching, state preservation, scope enforcement, code and refresh
replay, token rotation, revocation, and disabled-account handling.
