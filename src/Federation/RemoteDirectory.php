<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Federation;

use AlohaSocial\Core\Platform\Http\BoundedHttpClient;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Platform\Http\ResponseLimitExceededException;
use AlohaSocial\Core\Service\Federation\FederationTargetGuard;
use AlohaSocial\Core\Service\Federation\RemoteFetchException;
use JsonException;

use function array_filter;
use function array_is_list;
use function array_values;
use function count;
use function explode;
use function filter_var;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function ltrim;
use function mb_strlen;
use function mb_substr;
use function parse_url;
use function preg_match;
use function rawurldecode;
use function rawurlencode;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtolower;
use function strrpos;
use function substr;
use function trim;

use const FILTER_VALIDATE_URL;

/**
 * Resolves public Fediverse and Bluesky profiles for the standalone UI.
 *
 * ActivityPub profiles go through WebFinger, the actor document and the
 * actor's public outbox. Bluesky profiles use the unauthenticated public
 * AppView. Both paths are outbound, peer-controlled requests, so every URL is
 * checked by the federation target guard and fetched with a byte limit. The
 * requested profile may be a handle (`@user@host`, `user.bsky.social`) or an
 * absolute profile/actor URL.
 */
final class RemoteDirectory
{
    private const ACTIVITY_ACCEPT = 'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"';

    private const JSON_ACCEPT = 'application/json';

    private const JRD_ACCEPT = 'application/jrd+json, application/json';

    private const MAX_BYTES = 1_048_576;

    private const TIMEOUT_SECONDS = 10;

    private const MAX_REDIRECTS = 5;

    private const MAX_POSTS = 40;

    private const BSKY_APPVIEW = 'https://public.api.bsky.app/xrpc/';

    /** @var array<string, array<string, mixed>> actor documents already fetched in this request */
    private array $actorCache = [];

    public function __construct(
        private readonly BoundedHttpClient $http,
        private readonly FederationTargetGuard $guard,
        private readonly string $discoveryInstance = 'mastodon.social',
    ) {
    }

    /**
     * @return array{kind: string, profile: array<string, mixed>, posts: list<array<string, mixed>>, cursor: ?string}
     */
    public function lookup(string $query, ?string $cursor = null): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new RemoteDirectoryException('remote_handle_missing', 'Please provide a fediverse or Bluesky profile.', 422);
        }

        if ($this->isBlueskyQuery($query)) {
            return $this->lookupBluesky($query, $cursor);
        }

        return $this->lookupActivityPub($query, $cursor);
    }

    /**
     * Reads one remote post with its direct parent, replies and engagement
     * counts. The id is the canonical ActivityPub object id or an AT Protocol
     * post URI, exactly as the feed presents it.
     *
     * @return array{
     *     network: string,
     *     post: array<string, mixed>,
     *     parent: ?array<string, mixed>,
     *     replies: list<array<string, mixed>>,
     *     profile: ?array<string, mixed>
     * }
     */
    public function remotePost(string $id): array
    {
        $id = trim($id);
        if ($id === '') {
            throw new RemoteDirectoryException('remote_post_missing', 'Please provide a post.', 422);
        }

        if (str_starts_with($id, 'at://')) {
            return $this->blueskyThread($id);
        }

        if (!preg_match('#^https?://#i', $id) || filter_var($id, FILTER_VALIDATE_URL) === false) {
            throw new RemoteDirectoryException('remote_post_invalid', 'The post address is invalid.', 422);
        }

        return $this->activityPubThread($id);
    }

    /**
     * Reads a remote profile's followers or following list.
     *
     * @return array{network: string, profiles: list<array<string, mixed>>, cursor: ?string}
     */
    public function remoteConnections(string $query, string $type, ?string $cursor = null): array
    {
        $type = strtolower(trim($type));
        if (!in_array($type, ['followers', 'following'], true)) {
            throw new RemoteDirectoryException('remote_connection_type_invalid', 'Unknown connection type.', 422);
        }

        if ($this->isBlueskyQuery($query)) {
            return $this->blueskyConnections($query, $type, $cursor);
        }

        return $this->activityPubConnections($query, $type, $cursor);
    }

    /**
     * Searches both networks without requiring a local account.
     *
     * Bluesky uses the public AppView's actor and post search. The Fediverse
     * has no shared search endpoint, so a handle is resolved through WebFinger
     * and an outbox, while keywords and hashtags are read from the configured
     * public discovery instance. That instance is a read-only convenience:
     * point `ALOHASOCIAL_DISCOVERY_INSTANCE` at another host, or set it empty
     * to turn the federated keyword search off.
     *
     * @return array{
     *     query: string,
     *     accounts: list<array<string, mixed>>,
     *     posts: list<array<string, mixed>>,
     *     cursor: ?string,
     *     notes: list<string>
     * }
     */
    public function search(string $query, string $type = 'all', string $network = 'all', ?string $cursor = null): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new RemoteDirectoryException('remote_search_missing', 'Please enter a search term.', 422);
        }
        if (mb_strlen($query) > 200) {
            throw new RemoteDirectoryException('remote_search_too_long', 'The search term is too long.', 422);
        }

        $type = in_array($type, ['accounts', 'posts'], true) ? $type : 'all';
        $network = in_array($network, ['activitypub', 'bluesky'], true) ? $network : 'all';

        $accounts = [];
        $posts = [];
        $next = null;
        $notes = [];

        if ($network !== 'activitypub') {
            try {
                $result = $this->blueskySearch($query, $type, $cursor);
                $accounts = array_merge($accounts, $result['accounts']);
                $posts = array_merge($posts, $result['posts']);
                $next = $result['cursor'];
            } catch (RemoteDirectoryException $exception) {
                $notes[] = 'Bluesky: ' . $exception->getMessage();
            }
        }

        if ($network !== 'bluesky') {
            try {
                $result = $this->activityPubSearch($query, $type, $cursor);
                $accounts = array_merge($accounts, $result['accounts']);
                $posts = array_merge($posts, $result['posts']);
                $next ??= $result['cursor'];
                foreach ($result['notes'] as $note) {
                    $notes[] = $note;
                }
            } catch (RemoteDirectoryException $exception) {
                $notes[] = 'Fediverse: ' . $exception->getMessage();
            }
        }

        return [
            'query' => $query,
            'accounts' => $this->uniqueProfiles($accounts),
            'posts' => $this->uniquePosts($posts),
            'cursor' => $next,
            'notes' => $notes,
        ];
    }

    /**
     * Reads who liked or reposted one remote post.
     *
     * Mastodon-compatible servers expose two public collections for a status;
     * Bluesky exposes them as AppView endpoints. Both return profile cards in
     * the shape the rest of the remote views already understand.
     *
     * @return array{network: string, type: string, profiles: list<array<string, mixed>>, cursor: ?string}
     */
    public function remoteEngagement(string $id, string $type = 'likes', ?string $cursor = null): array
    {
        $id = trim($id);
        $type = strtolower(trim($type));
        if (!in_array($type, ['likes', 'reposts'], true)) {
            throw new RemoteDirectoryException('remote_engagement_type_invalid', 'Unknown reaction type.', 422);
        }
        if ($id === '') {
            throw new RemoteDirectoryException('remote_post_missing', 'Please provide a post.', 422);
        }

        if (str_starts_with($id, 'at://')) {
            return $this->blueskyEngagement($id, $type, $cursor);
        }

        $host = parse_url($id, PHP_URL_HOST);
        $statusId = $this->mastodonStatusId($id);
        if (!is_string($host) || $host === '' || $statusId === null) {
            throw new RemoteDirectoryException(
                'remote_engagement_unsupported',
                'This server does not publish a public list of reactions.',
                404,
            );
        }

        $collection = $type === 'likes' ? 'favourited_by' : 'reblogged_by';
        $url = 'https://' . $host . '/api/v1/statuses/' . rawurlencode($statusId) . '/' . $collection . '?limit=40';
        if ($cursor !== null && $cursor !== '') {
            $url .= '&max_id=' . rawurlencode($cursor);
        }

        $list = $this->fetchJson($url, self::JSON_ACCEPT);
        if (!array_is_list($list)) {
            throw new RemoteDirectoryException('remote_invalid_response', 'The remote server returned an unexpected response.');
        }

        $profiles = [];
        $next = null;
        foreach ($list as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $profiles[] = $this->mastodonProfile($entry, $host);
            $entryId = $this->string($entry['id'] ?? null);
            if ($entryId !== null) {
                $next = $entryId;
            }
        }

        return [
            'network' => 'activitypub',
            'type' => $type,
            'profiles' => $profiles,
            'cursor' => count($profiles) >= 40 ? $next : null,
        ];
    }

    /**
     * @return array{accounts: list<array<string, mixed>>, posts: list<array<string, mixed>>, cursor: ?string}
     */
    private function blueskySearch(string $query, string $type, ?string $cursor): array
    {
        $accounts = [];
        $posts = [];
        $next = null;
        $failures = [];

        if ($type !== 'posts') {
            try {
                $data = $this->fetchJson(
                    self::BSKY_APPVIEW . 'app.bsky.actor.searchActors?q=' . rawurlencode($query) . '&limit=20',
                    self::JSON_ACCEPT,
                );
                foreach (is_array($data['actors'] ?? null) ? $data['actors'] : [] as $actor) {
                    if (is_array($actor)) {
                        $accounts[] = $this->blueskyProfile($actor);
                    }
                }
            } catch (RemoteDirectoryException $exception) {
                $failures[] = $exception->getMessage();
            }
        }

        if ($type !== 'accounts') {
            try {
                $url = self::BSKY_APPVIEW . 'app.bsky.feed.searchPosts?q=' . rawurlencode($query) . '&limit=25&sort=latest';
                if ($cursor !== null && $cursor !== '') {
                    $url .= '&cursor=' . rawurlencode($cursor);
                }
                $data = $this->fetchJson($url, self::JSON_ACCEPT);
                foreach (is_array($data['posts'] ?? null) ? $data['posts'] : [] as $post) {
                    if (!is_array($post)) {
                        continue;
                    }
                    $mapped = $this->blueskyPost($post);
                    if ($mapped !== null) {
                        $posts[] = $mapped;
                    }
                    if (count($posts) >= self::MAX_POSTS) {
                        break;
                    }
                }
                $next = $this->string($data['cursor'] ?? null);
            } catch (RemoteDirectoryException $exception) {
                // The public AppView blocks post search on some addresses. The
                // actor results are still worth showing, so a partial failure
                // must not discard them.
                $failures[] = $exception->getMessage();
            }
        }

        if ($accounts === [] && $posts === [] && $failures !== []) {
            throw new RemoteDirectoryException(
                'remote_search_failed',
                $failures[0],
            );
        }

        return ['accounts' => $accounts, 'posts' => $posts, 'cursor' => $next];
    }

    /**
     * @return array{accounts: list<array<string, mixed>>, posts: list<array<string, mixed>>, cursor: ?string, notes: list<string>}
     */
    private function activityPubSearch(string $query, string $type, ?string $cursor): array
    {
        $accounts = [];
        $posts = [];
        $notes = [];

        if ($this->parseHandle($query) !== null || str_starts_with($query, 'did:')) {
            $lookup = $this->lookup($query, $cursor);

            return [
                'accounts' => [$lookup['profile']],
                'posts' => $lookup['posts'],
                'cursor' => $lookup['cursor'],
                'notes' => $notes,
            ];
        }

        $host = $this->discoveryHost();
        if ($host === null) {
            $notes[] = 'No discovery instance is configured for keyword search in the fediverse.';

            return ['accounts' => [], 'posts' => [], 'cursor' => null, 'notes' => $notes];
        }

        $base = 'https://' . $host . '/api/';

        if ($type !== 'posts') {
            $search = $this->mastodonSearch($host, $query, 'accounts', $cursor);
            foreach ($search['accounts'] as $account) {
                $accounts[] = $this->mastodonProfile($account, $host);
            }
        }

        if ($type !== 'accounts') {
            if (str_starts_with($query, '#')) {
                $tag = ltrim($query, '#');
                $url = $base . 'v1/timelines/tag/' . rawurlencode($tag) . '?limit=25';
                $list = $this->fetchJson($url, self::JSON_ACCEPT);
                foreach (is_array($list) ? $list : [] as $status) {
                    if (!is_array($status)) {
                        continue;
                    }
                    $mapped = $this->mastodonPost($status, $host);
                    if ($mapped !== null) {
                        $posts[] = $mapped;
                    }
                }
            } else {
                $search = $this->mastodonSearch($host, $query, 'statuses', $cursor);
                foreach ($search['statuses'] as $status) {
                    if (!is_array($status)) {
                        continue;
                    }
                    $mapped = $this->mastodonPost($status, $host);
                    if ($mapped !== null) {
                        $posts[] = $mapped;
                    }
                }
            }
        }

        return [
            'accounts' => $accounts,
            'posts' => $posts,
            'cursor' => null,
            'notes' => $notes,
        ];
    }

    /**
     * Mastodon's v2 search shape, with a v1 fallback for older servers.
     *
     * @return array{accounts: list<array<string, mixed>>, statuses: list<array<string, mixed>>}
     */
    private function mastodonSearch(string $host, string $query, string $type, ?string $cursor): array
    {
        $parameters = '?q=' . rawurlencode($query) . '&type=' . $type . '&limit=20&resolve=false';
        if ($cursor !== null && $cursor !== '') {
            $parameters .= '&max_id=' . rawurlencode($cursor);
        }

        try {
            $data = $this->fetchJson('https://' . $host . '/api/v2/search' . $parameters, self::JSON_ACCEPT);
        } catch (RemoteDirectoryException) {
            $data = $this->fetchJson('https://' . $host . '/api/v1/search' . $parameters, self::JSON_ACCEPT);
        }

        $accounts = is_array($data['accounts'] ?? null) ? $data['accounts'] : [];
        $statuses = is_array($data['statuses'] ?? null) ? $data['statuses'] : [];

        return [
            'accounts' => array_values(array_filter($accounts, 'is_array')),
            'statuses' => array_values(array_filter($statuses, 'is_array')),
        ];
    }

    /**
     * @return array{network: string, type: string, profiles: list<array<string, mixed>>, cursor: ?string}
     */
    private function blueskyEngagement(string $uri, string $type, ?string $cursor): array
    {
        $parameters = '?uri=' . rawurlencode($uri) . '&limit=50';
        if ($cursor !== null && $cursor !== '') {
            $parameters .= '&cursor=' . rawurlencode($cursor);
        }

        $profiles = [];
        if ($type === 'likes') {
            $data = $this->fetchJson(self::BSKY_APPVIEW . 'app.bsky.feed.getLikes' . $parameters, self::JSON_ACCEPT);
            foreach (is_array($data['likes'] ?? null) ? $data['likes'] : [] as $like) {
                if (is_array($like) && is_array($like['actor'] ?? null)) {
                    $profiles[] = $this->blueskyProfile($like['actor']);
                }
            }
        } else {
            $data = $this->fetchJson(self::BSKY_APPVIEW . 'app.bsky.feed.getRepostedBy' . $parameters, self::JSON_ACCEPT);
            foreach (is_array($data['repostedBy'] ?? null) ? $data['repostedBy'] : [] as $profile) {
                if (is_array($profile)) {
                    $profiles[] = $this->blueskyProfile($profile);
                }
            }
        }

        return [
            'network' => 'bluesky',
            'type' => $type,
            'profiles' => $profiles,
            'cursor' => $this->string($data['cursor'] ?? null),
        ];
    }

    private function discoveryHost(): ?string
    {
        $value = strtolower(trim($this->discoveryInstance));
        if ($value === '') {
            return null;
        }

        if (str_contains($value, '://')) {
            $host = parse_url($value, PHP_URL_HOST);

            return is_string($host) && $host !== '' ? $host : null;
        }

        return preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $value) === 1 ? $value : null;
    }

    /**
     * @param list<array<string, mixed>> $profiles
     *
     * @return list<array<string, mixed>>
     */
    private function uniqueProfiles(array $profiles): array
    {
        $seen = [];
        $unique = [];
        foreach ($profiles as $profile) {
            $id = $this->string($profile['id'] ?? null) ?? '';
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $unique[] = $profile;
        }

        return $unique;
    }

    /**
     * @param list<array<string, mixed>> $posts
     *
     * @return list<array<string, mixed>>
     */
    private function uniquePosts(array $posts): array
    {
        $seen = [];
        $unique = [];
        foreach ($posts as $post) {
            $id = $this->string($post['id'] ?? null) ?? '';
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $unique[] = $post;
        }

        return array_slice($unique, 0, 50);
    }

    /**
     * @return array{kind: string, profile: array<string, mixed>, posts: list<array<string, mixed>>, cursor: ?string}
     */
    private function lookupActivityPub(string $query, ?string $cursor): array
    {
        $handle = $this->parseHandle($query);

        try {
            [$actorUrl, $actor] = $this->resolveActor($query);
            $profile = $this->activityPubProfile($actor, $actorUrl);

            $outbox = $this->link($actor['outbox'] ?? null);
            [$posts, $next, $total] = $this->activityPubPosts($outbox, $cursor, $profile);
            if ($total !== null) {
                $profile['posts_count'] = $total;
            }

            return [
                'kind' => 'activitypub',
                'profile' => $profile,
                'posts' => $posts,
                'cursor' => $next,
            ];
        } catch (RemoteDirectoryException $exception) {
            // Some instances enable authorized fetch and answer 401/403 to
            // an unsigned actor request. Their public Mastodon-compatible API
            // still exposes the same profile, so use it for reading. The
            // actor document stays the authority for following.
            if (in_array($exception->status, [401, 403], true) && $handle !== null) {
                $fallback = $this->mastodonLookup($handle[0], $handle[1], $cursor);
                if ($fallback !== null) {
                    return $fallback;
                }
            }

            throw $exception;
        }
    }

    /**
     * @return array{0: string, 1: string}|null user and host
     */
    private function parseHandle(string $query): ?array
    {
        $query = trim($query);

        if (preg_match('~^https?://~i', $query) === 1 && filter_var($query, FILTER_VALIDATE_URL) !== false) {
            $host = parse_url($query, PHP_URL_HOST);
            $path = parse_url($query, PHP_URL_PATH);
            if (!is_string($host) || $host === '' || !is_string($path)) {
                return null;
            }

            if (preg_match('~^/@([^/]+)~', $path, $matches) === 1
                || preg_match('~^/users/([^/]+)~', $path, $matches) === 1
            ) {
                return [rawurldecode($matches[1]), $host];
            }

            return null;
        }

        $address = ltrim($query, '@');
        $at = strrpos($address, '@');
        if ($at === false) {
            return null;
        }

        $user = substr($address, 0, $at);
        $host = substr($address, $at + 1);

        return $user !== '' && $host !== '' && !str_contains($user, '@') ? [$user, $host] : null;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function resolveActor(string $query): array
    {
        $actorUrl = $this->actorUrl($query);
        $actor = $this->fetchJson($actorUrl, self::ACTIVITY_ACCEPT);

        return [$actorUrl, $actor];
    }

    /**
     * @return array{
     *     network: string,
     *     post: array<string, mixed>,
     *     parent: ?array<string, mixed>,
     *     replies: list<array<string, mixed>>,
     *     profile: ?array<string, mixed>
     * }
     */
    private function activityPubThread(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        $statusId = $this->mastodonStatusId($url);
        if ($statusId !== null && is_string($host) && $host !== '') {
            try {
                return $this->mastodonThread($host, $statusId, $url);
            } catch (RemoteDirectoryException) {
                // Not every ActivityPub server exposes the Mastodon API, and
                // the API may hide a status that the object endpoint still
                // serves to an unauthenticated request.
            }
        }

        $note = $this->fetchJson($url, self::ACTIVITY_ACCEPT);
        if (isset($note['object']) && is_array($note['object'])) {
            $note = $note['object'];
        }

        $authorUrl = $this->firstLink($note['attributedTo'] ?? null);
        $profile = $authorUrl !== null ? $this->resolveActorProfile($authorUrl, false) : null;

        $likes = $this->collectionCount($this->link($note['likes'] ?? null));
        $boosts = $this->collectionCount($this->link($note['shares'] ?? null));
        $repliesUrl = $this->link($note['replies'] ?? null);
        $replyTotal = $this->collectionCount($repliesUrl);

        $post = $this->activityPubPost($note, $profile ?? [], [
            'likes' => $likes,
            'replies' => $replyTotal,
            'boosts' => $boosts,
        ]);
        if ($post === null) {
            throw new RemoteDirectoryException(
                'remote_post_unavailable',
                'This post is not publicly available.',
                404,
            );
        }

        $parent = null;
        $parentUrl = $this->link($note['inReplyTo'] ?? null);
        if ($parentUrl !== null) {
            try {
                $parentNote = $this->fetchJson($parentUrl, self::ACTIVITY_ACCEPT);
                if (isset($parentNote['object']) && is_array($parentNote['object'])) {
                    $parentNote = $parentNote['object'];
                }
                $parentAuthor = $this->firstLink($parentNote['attributedTo'] ?? null);
                $parent = $this->activityPubPost(
                    $parentNote,
                    $parentAuthor !== null ? $this->resolveActorProfile($parentAuthor, false) : [],
                );
            } catch (RemoteDirectoryException) {
                $parent = null;
            }
        }

        $replies = $this->activityPubReplies($repliesUrl);
        if ($replyTotal === null) {
            $post['replies_count'] = count($replies);
        }

        return [
            'network' => 'activitypub',
            'post' => $post,
            'parent' => $parent,
            'replies' => $replies,
            'profile' => $profile,
        ];
    }

    /**
     * @return array{
     *     network: string,
     *     post: array<string, mixed>,
     *     parent: ?array<string, mixed>,
     *     replies: list<array<string, mixed>>,
     *     profile: array<string, mixed>
     * }
     */
    private function mastodonThread(string $host, string $statusId, string $url): array
    {
        $base = 'https://' . $host . '/api/v1/';
        $status = $this->fetchJson($base . 'statuses/' . rawurlencode($statusId), self::JSON_ACCEPT);
        if (isset($status['reblog']) && is_array($status['reblog'])) {
            $status = $status['reblog'];
        }

        $post = $this->mastodonPost($status, $host);
        if ($post === null) {
            throw new RemoteDirectoryException(
                'remote_post_unavailable',
                'This post is not publicly available.',
                404,
            );
        }

        $context = [];
        try {
            $context = $this->fetchJson(
                $base . 'statuses/' . rawurlencode($statusId) . '/context',
                self::JSON_ACCEPT,
            );
        } catch (RemoteDirectoryException) {
            // The post and its own counts stay useful without the context.
        }

        $ancestors = is_array($context['ancestors'] ?? null) ? $context['ancestors'] : [];
        $descendants = is_array($context['descendants'] ?? null) ? $context['descendants'] : [];

        $parent = null;
        foreach ($ancestors as $ancestor) {
            if (!is_array($ancestor)) {
                continue;
            }

            $candidate = $this->mastodonPost($ancestor, $host);
            if ($candidate !== null) {
                $parent = $candidate;
            }
        }

        if ($parent !== null) {
            $post['in_reply_to_id'] = $parent['id'];
        }

        $replies = [];
        foreach ($descendants as $descendant) {
            if (!is_array($descendant)) {
                continue;
            }

            $reply = $this->mastodonPost($descendant, $host);
            if ($reply !== null) {
                $replies[] = $reply;
            }

            if (count($replies) >= 40) {
                break;
            }
        }

        $post['external_url'] = $this->string($status['url'] ?? null) ?? $post['external_url'] ?? $url;

        return [
            'network' => 'activitypub',
            'post' => $post,
            'parent' => $parent,
            'replies' => $replies,
            'profile' => $this->mastodonProfile(
                is_array($status['account'] ?? null) ? $status['account'] : [],
                $host,
            ),
        ];
    }

    private function mastodonStatusId(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }

        if (preg_match('~^/(?:@[^/]+|users/[^/]+)/statuses/(\d+)$~', $path, $matches) === 1
            || preg_match('~^/@[^/]+/(\d+)$~', $path, $matches) === 1
        ) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activityPubReplies(?string $repliesUrl): array
    {
        if ($repliesUrl === null) {
            return [];
        }

        try {
            $collection = $this->fetchJson($repliesUrl, self::ACTIVITY_ACCEPT);
        } catch (RemoteDirectoryException) {
            return [];
        }

        if (isset($collection['first'])) {
            $first = $collection['first'];
            if (is_array($first) && !array_is_list($first)) {
                $collection = $first;
            } else {
                $firstUrl = $this->link($first);
                if ($firstUrl !== null) {
                    try {
                        $collection = $this->fetchJson($firstUrl, self::ACTIVITY_ACCEPT);
                    } catch (RemoteDirectoryException) {
                        return [];
                    }
                }
            }
        }

        $items = $collection['orderedItems'] ?? $collection['items'] ?? [];
        $replies = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_string($item)) {
                try {
                    $item = $this->fetchJson($item, self::ACTIVITY_ACCEPT);
                } catch (RemoteDirectoryException) {
                    continue;
                }
            }
            if (!is_array($item)) {
                continue;
            }

            $object = is_array($item['object'] ?? null) ? $item['object'] : $item;
            $author = $this->firstLink($object['attributedTo'] ?? null);
            $reply = $this->activityPubPost(
                $object,
                $author !== null ? $this->resolveActorProfile($author, false) : [],
            );
            if ($reply !== null) {
                $replies[] = $reply;
            }

            if (count($replies) >= 20) {
                break;
            }
        }

        return $replies;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveActorProfile(string $actorUrl, bool $withCounts = true): array
    {
        if (isset($this->actorCache[$actorUrl])) {
            $cached = $this->actorCache[$actorUrl];
            if (!$withCounts) {
                $cached['followers_count'] = null;
                $cached['following_count'] = null;
            }

            return $cached;
        }

        try {
            $actor = $this->fetchJson($actorUrl, self::ACTIVITY_ACCEPT);
            $profile = $this->activityPubProfile($actor, $actorUrl, $withCounts);
        } catch (RemoteDirectoryException) {
            $profile = $this->fallbackActorProfile($actorUrl);
        }

        $this->actorCache[$actorUrl] = $profile;

        return $profile;
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackActorProfile(string $actorUrl): array
    {
        $host = parse_url($actorUrl, PHP_URL_HOST);
        $path = parse_url($actorUrl, PHP_URL_PATH);
        $name = '';

        if (is_string($path)) {
            $segments = array_values(array_filter(
                explode('/', $path),
                static fn (string $part): bool => $part !== '',
            ));
            $last = $segments !== [] ? $segments[count($segments) - 1] : '';
            $name = rawurldecode(ltrim($last, '@'));
        }

        $host = is_string($host) ? $host : '';
        $handle = '@' . ($name !== '' ? $name : $host) . ($host !== '' ? '@' . $host : '');

        return [
            'id' => $actorUrl,
            'handle' => $handle,
            'display_name' => $name !== '' ? $name : $handle,
            'url' => $actorUrl,
            'avatar' => null,
            'banner' => null,
            'bio' => null,
            'followers_count' => null,
            'following_count' => null,
            'posts_count' => null,
            'inbox' => null,
            'outbox' => null,
            'is_remote' => true,
            'can_follow' => false,
            'network' => 'activitypub',
            'published_at' => null,
        ];
    }

    /**
     * @return array{network: string, profiles: list<array<string, mixed>>, cursor: ?string}
     */
    private function activityPubConnections(string $query, string $type, ?string $cursor): array
    {
        $handle = $this->parseHandle($query);
        if ($handle !== null) {
            try {
                $fallback = $this->mastodonConnections($handle[0], $handle[1], $type, $cursor);
                if ($fallback !== null) {
                    return $fallback;
                }
            } catch (RemoteDirectoryException) {
                // Fall through to the actor's ActivityPub collection.
            }
        }

        [$actorUrl, $actor] = $this->resolveActor($query);
        $collectionUrl = $this->link($actor[$type] ?? null);
        if ($collectionUrl === null) {
            return ['network' => 'activitypub', 'profiles' => [], 'cursor' => null];
        }

        $url = $cursor ?? $collectionUrl;
        if ($cursor !== null && !$this->sameOrigin($cursor, $collectionUrl)) {
            throw new RemoteDirectoryException('remote_cursor_invalid', 'The next page does not belong to this profile.');
        }

        $collection = $this->fetchJson($url, self::ACTIVITY_ACCEPT);
        $next = $this->link($collection['next'] ?? null);

        if ($cursor === null && isset($collection['first'])) {
            $first = $collection['first'];
            if (is_array($first) && !array_is_list($first)) {
                $collection = $first;
                $next = $this->link($collection['next'] ?? null);
            } else {
                $firstUrl = $this->link($first);
                if ($firstUrl !== null && $this->sameOrigin($firstUrl, $collectionUrl)) {
                    $collection = $this->fetchJson($firstUrl, self::ACTIVITY_ACCEPT);
                    $next = $this->link($collection['next'] ?? null);
                }
            }
        }

        $items = $collection['orderedItems'] ?? $collection['items'] ?? [];
        $profiles = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $actorLink = is_string($item)
                ? $item
                : $this->link(is_array($item) ? ($item['object'] ?? $item) : null);
            if ($actorLink === null) {
                continue;
            }

            $profiles[] = $this->resolveActorProfile($actorLink, false);
            if (count($profiles) >= 20) {
                break;
            }
        }

        return ['network' => 'activitypub', 'profiles' => $profiles, 'cursor' => $next];
    }

    /**
     * @return array{network: string, profiles: list<array<string, mixed>>, cursor: ?string}|null
     */
    private function mastodonConnections(string $user, string $host, string $type, ?string $cursor): ?array
    {
        $base = 'https://' . $host . '/api/v1/';
        try {
            $account = $this->fetchJson($base . 'accounts/lookup?acct=' . rawurlencode($user), self::JSON_ACCEPT);
        } catch (RemoteDirectoryException) {
            return null;
        }

        $id = $this->string($account['id'] ?? null);
        if ($id === null) {
            return null;
        }

        $url = $base . 'accounts/' . rawurlencode($id) . '/' . $type . '?limit=40';
        if ($cursor !== null && $cursor !== '') {
            $url .= '&max_id=' . rawurlencode($cursor);
        }

        try {
            $list = $this->fetchJson($url, self::JSON_ACCEPT);
        } catch (RemoteDirectoryException) {
            return null;
        }

        if (!array_is_list($list)) {
            return null;
        }

        $profiles = [];
        $next = null;
        foreach ($list as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $profiles[] = $this->mastodonProfile($entry, $host);
            $entryId = $this->string($entry['id'] ?? null);
            if ($entryId !== null) {
                $next = $entryId;
            }
        }

        return ['network' => 'activitypub', 'profiles' => $profiles, 'cursor' => $next];
    }

    /**
     * @return array{
     *     network: string,
     *     post: array<string, mixed>,
     *     parent: ?array<string, mixed>,
     *     replies: list<array<string, mixed>>,
     *     profile: null
     * }
     */
    private function blueskyThread(string $uri): array
    {
        $data = $this->fetchJson(
            self::BSKY_APPVIEW . 'app.bsky.feed.getPostThread?uri=' . rawurlencode($uri)
                . '&depth=3&parentHeight=1',
            self::JSON_ACCEPT,
        );

        $thread = is_array($data['thread'] ?? null) ? $data['thread'] : [];
        $post = $this->blueskyPost(is_array($thread['post'] ?? null) ? $thread['post'] : []);
        if ($post === null) {
            throw new RemoteDirectoryException(
                'remote_post_unavailable',
                'This Bluesky post is not publicly available.',
                404,
            );
        }

        $parent = isset($thread['parent']['post']) && is_array($thread['parent']['post'])
            ? $this->blueskyPost($thread['parent']['post'])
            : null;

        $replies = [];
        $this->collectBlueskyReplies($thread['replies'] ?? null, $replies);

        return [
            'network' => 'bluesky',
            'post' => $post,
            'parent' => $parent,
            'replies' => $replies,
            'profile' => null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $replies
     */
    private function collectBlueskyReplies(mixed $value, array &$replies): void
    {
        if (!is_array($value)) {
            return;
        }

        foreach ($value as $reply) {
            if (!is_array($reply)) {
                continue;
            }

            if (isset($reply['post']) && is_array($reply['post'])) {
                $post = $this->blueskyPost($reply['post']);
                if ($post !== null) {
                    $replies[] = $post;
                }
            }

            if (isset($reply['replies'])) {
                $this->collectBlueskyReplies($reply['replies'], $replies);
            }

            if (count($replies) >= 30) {
                return;
            }
        }
    }

    /**
     * @return array{network: string, profiles: list<array<string, mixed>>, cursor: ?string}
     */
    private function blueskyConnections(string $query, string $type, ?string $cursor): array
    {
        $actor = $this->blueskyActor($query);
        $endpoint = $type === 'followers' ? 'app.bsky.graph.getFollowers' : 'app.bsky.graph.getFollows';
        $url = self::BSKY_APPVIEW . $endpoint . '?actor=' . rawurlencode($actor) . '&limit=50';
        if ($cursor !== null && $cursor !== '') {
            $url .= '&cursor=' . rawurlencode($cursor);
        }

        $data = $this->fetchJson($url, self::JSON_ACCEPT);
        $key = $type === 'followers' ? 'followers' : 'follows';
        $entries = is_array($data[$key] ?? null) ? $data[$key] : [];

        $profiles = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $profiles[] = $this->blueskyProfile($entry);
        }

        return [
            'network' => 'bluesky',
            'profiles' => $profiles,
            'cursor' => is_string($data['cursor'] ?? null) && $data['cursor'] !== '' ? $data['cursor'] : null,
        ];
    }

    /**
     * @return array{kind: string, profile: array<string, mixed>, posts: list<array<string, mixed>>, cursor: ?string}|null
     */
    private function mastodonLookup(string $user, string $host, ?string $cursor): ?array
    {
        $base = 'https://' . $host . '/api/v1/';
        try {
            $account = $this->fetchJson($base . 'accounts/lookup?acct=' . rawurlencode($user), self::JSON_ACCEPT);
        } catch (RemoteDirectoryException) {
            return null;
        }

        $id = $this->string($account['id'] ?? null);
        if ($id === null) {
            return null;
        }

        $statusesUrl = $base . 'accounts/' . rawurlencode($id) . '/statuses?limit=30&exclude_replies=true';
        if ($cursor !== null && $cursor !== '') {
            $statusesUrl .= '&max_id=' . rawurlencode($cursor);
        }

        try {
            $statuses = $this->fetchJson($statusesUrl, self::JSON_ACCEPT);
        } catch (RemoteDirectoryException) {
            $statuses = [];
        }

        $posts = [];
        $next = null;
        foreach ($statuses as $status) {
            if (!is_array($status)) {
                continue;
            }

            if (isset($status['reblog']) && is_array($status['reblog'])) {
                $status = $status['reblog'];
            }

            $post = $this->mastodonPost($status, $host);
            if ($post !== null) {
                $posts[] = $post;
            }

            $statusId = $this->string($status['id'] ?? null);
            if ($statusId !== null) {
                $next = $statusId;
            }

            if (count($posts) >= self::MAX_POSTS) {
                break;
            }
        }

        return [
            'kind' => 'activitypub',
            'profile' => $this->mastodonProfile($account, $host),
            'posts' => $posts,
            'cursor' => $next,
        ];
    }

    /**
     * @param array<string, mixed> $account
     *
     * @return array<string, mixed>
     */
    private function mastodonProfile(array $account, string $host): array
    {
        $acct = $this->string($account['acct'] ?? null) ?? '';
        $handle = $acct !== ''
            ? '@' . (str_contains($acct, '@') ? $acct : $acct . '@' . $host)
            : '@' . $host;

        return [
            'id' => $this->string($account['uri'] ?? null) ?? $this->string($account['url'] ?? null) ?? '',
            'handle' => $handle,
            'display_name' => $this->string($account['display_name'] ?? null) ?? $handle,
            'url' => $this->string($account['url'] ?? null) ?? '',
            'avatar' => $this->string($account['avatar_static'] ?? null) ?? $this->string($account['avatar'] ?? null),
            'banner' => $this->string($account['header_static'] ?? null) ?? $this->string($account['header'] ?? null),
            'bio' => HtmlText::toPlainText($this->string($account['note'] ?? null), 5_000),
            'followers_count' => $this->integer($account['followers_count'] ?? null),
            'following_count' => $this->integer($account['following_count'] ?? null),
            'posts_count' => $this->integer($account['statuses_count'] ?? null),
            'inbox' => null,
            'outbox' => null,
            'is_remote' => true,
            'can_follow' => false,
            'network' => 'activitypub',
            'via' => 'mastodon_api',
            'published_at' => $this->string($account['created_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $status
     *
     * @return array<string, mixed>|null
     */
    private function mastodonPost(array $status, string $host): ?array
    {
        $id = $this->string($status['uri'] ?? null) ?? $this->string($status['url'] ?? null);
        if ($id === null) {
            return null;
        }

        $account = is_array($status['account'] ?? null) ? $status['account'] : [];
        $author = $this->string($account['uri'] ?? null) ?? $this->string($account['url'] ?? null) ?? '';
        $acct = $this->string($account['acct'] ?? null) ?? '';
        $authorHandle = $acct !== ''
            ? '@' . (str_contains($acct, '@') ? $acct : $acct . '@' . $host)
            : null;
        $authorDisplay = $this->string($account['display_name'] ?? null);
        $authorAvatar = $this->string($account['avatar_static'] ?? null)
            ?? $this->string($account['avatar'] ?? null);
        $published = $this->string($status['created_at'] ?? null) ?? '';
        $attachments = [];

        foreach (is_array($status['media_attachments'] ?? null) ? $status['media_attachments'] : [] as $media) {
            if (!is_array($media)) {
                continue;
            }

            $url = $this->string($media['url'] ?? null) ?? $this->string($media['remote_url'] ?? null);
            if ($url === null) {
                continue;
            }

            $type = strtolower($this->string($media['type'] ?? null) ?? 'image');
            $kind = in_array($type, ['video', 'gifv'], true) ? 'video' : ($type === 'audio' ? 'audio' : 'image');
            $meta = is_array($media['meta'] ?? null) ? $media['meta'] : [];
            $original = is_array($meta['original'] ?? null) ? $meta['original'] : [];

            $attachments[] = [
                'id' => $url,
                'media_type' => $kind,
                'mime_type' => $this->string($media['mime_type'] ?? null)
                    ?? ($kind === 'image' ? 'image/*' : $kind . '/*'),
                'width' => $this->integer($original['width'] ?? null) ?? 0,
                'height' => $this->integer($original['height'] ?? null) ?? 0,
                'byte_size' => 0,
                'alt_text' => $this->string($media['description'] ?? null),
                'created_at' => $published,
                'url' => $url,
                'thumbnail_url' => $this->string($media['preview_url'] ?? null),
            ];
        }

        return [
            'id' => $id,
            'author_id' => $author !== '' ? $author : $id,
            'author_handle' => $authorHandle,
            'author_display_name' => $authorDisplay,
            'author_avatar' => $authorAvatar,
            'content' => HtmlText::toPlainText($this->string($status['content'] ?? null), 10_000),
            'content_warning' => $this->string($status['spoiler_text'] ?? null),
            'visibility' => $this->string($status['visibility'] ?? null) ?? 'public',
            'published_at' => $published,
            'edited_at' => $this->string($status['edited_at'] ?? null),
            'deleted_at' => null,
            'in_reply_to_id' => null,
            'recipient_ids' => [],
            'attachments' => $attachments,
            'poll' => null,
            'kind' => 'post',
            'object_id' => null,
            'object_author_id' => null,
            'boosted_by' => null,
            'is_remote' => true,
            'likes_count' => $this->integer($status['favourites_count'] ?? null),
            'replies_count' => $this->integer($status['replies_count'] ?? null),
            'boosts_count' => $this->integer($status['reblogs_count'] ?? null),
            'external_url' => $this->string($status['url'] ?? null) ?? $id,
        ];
    }

    /**
     * @return array{kind: string, profile: array<string, mixed>, posts: list<array<string, mixed>>, cursor: ?string}
     */
    private function lookupBluesky(string $query, ?string $cursor): array
    {
        $actor = $this->blueskyActor($query);
        $profile = $this->fetchJson(
            self::BSKY_APPVIEW . 'app.bsky.actor.getProfile?actor=' . rawurlencode($actor),
            self::JSON_ACCEPT,
        );

        $feedUrl = self::BSKY_APPVIEW . 'app.bsky.feed.getAuthorFeed?actor=' . rawurlencode($actor)
            . '&limit=30&filter=posts_no_replies';
        if ($cursor !== null && $cursor !== '') {
            $feedUrl .= '&cursor=' . rawurlencode($cursor);
        }
        $feed = $this->fetchJson($feedUrl, self::JSON_ACCEPT);

        return [
            'kind' => 'bluesky',
            'profile' => $this->blueskyProfile($profile),
            'posts' => $this->blueskyPosts($feed),
            'cursor' => is_string($feed['cursor'] ?? null) && $feed['cursor'] !== '' ? $feed['cursor'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function activityPubProfile(array $actor, string $actorUrl, bool $withCounts = true): array
    {
        $id = $this->string($actor['id'] ?? null) ?? $actorUrl;
        $host = parse_url($id, PHP_URL_HOST);
        $host = is_string($host) && $host !== '' ? $host : '';
        $preferred = $this->string($actor['preferredUsername'] ?? null) ?? '';
        $handle = $preferred !== ''
            ? '@' . $preferred . ($host !== '' ? '@' . $host : '')
            : ($this->string($actor['name'] ?? null) ?? $id);

        $inbox = $this->link($actor['inbox'] ?? null);
        $outbox = $this->link($actor['outbox'] ?? null);
        $followers = $this->link($actor['followers'] ?? null);
        $following = $this->link($actor['following'] ?? null);

        $name = $this->string($actor['name'] ?? null);
        $summary = $this->string($actor['summary'] ?? null);

        return [
            'id' => $id,
            'handle' => $handle,
            'display_name' => $name !== null && $name !== '' ? $name : $handle,
            'url' => $this->link($actor['url'] ?? null) ?? $id,
            'avatar' => $this->imageUrl($actor['icon'] ?? null),
            'banner' => $this->imageUrl($actor['image'] ?? null),
            'bio' => HtmlText::toPlainText($summary, 5_000),
            'followers_count' => $withCounts ? $this->collectionCount($followers) : null,
            'following_count' => $withCounts ? $this->collectionCount($following) : null,
            'posts_count' => null,
            'inbox' => $inbox,
            'outbox' => $outbox,
            'followers_url' => $followers,
            'following_url' => $following,
            'is_remote' => true,
            'can_follow' => $inbox !== null,
            'network' => 'activitypub',
            'published_at' => $this->string($actor['published'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $profile
     *
     * @return array{0: list<array<string, mixed>>, 1: ?string, 2: ?int}
     */
    private function activityPubPosts(?string $outbox, ?string $cursor, array $profile): array
    {
        if ($outbox === null) {
            return [[], null, null];
        }

        $url = $cursor ?? $outbox;
        if ($cursor !== null && !$this->sameOrigin($cursor, $outbox)) {
            throw new RemoteDirectoryException('remote_cursor_invalid', 'The next page does not belong to this profile.');
        }

        $collection = $this->fetchJson($url, self::ACTIVITY_ACCEPT);
        $total = $this->integer($collection['totalItems'] ?? null);
        $next = $this->link($collection['next'] ?? null);

        if ($cursor === null && isset($collection['first'])) {
            $first = $collection['first'];
            if (is_array($first) && !array_is_list($first)) {
                $collection = $first;
                $next = $this->link($collection['next'] ?? null);
            } else {
                $firstUrl = $this->link($first);
                if ($firstUrl !== null && $this->sameOrigin($firstUrl, $outbox)) {
                    $collection = $this->fetchJson($firstUrl, self::ACTIVITY_ACCEPT);
                    $next = $this->link($collection['next'] ?? null);
                }
            }
        }

        $items = $collection['orderedItems'] ?? $collection['items'] ?? [];
        $total ??= $this->integer($collection['totalItems'] ?? null);
        $posts = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $object = $item['object'] ?? $item;
            if (!is_array($object)) {
                continue;
            }

            $type = strtolower($this->string($object['type'] ?? null) ?? '');
            if (!in_array($type, ['note', 'article', 'page', 'question', 'video'], true)) {
                continue;
            }

            $post = $this->activityPubPost($object, $profile);
            if ($post !== null) {
                $posts[] = $post;
            }

            if (count($posts) >= self::MAX_POSTS) {
                break;
            }
        }

        return [$posts, $next, $total];
    }

    /**
     * @param array<string, mixed> $note
     * @param array<string, mixed> $profile
     * @param array{likes?: ?int, replies?: ?int, boosts?: ?int} $counts
     *
     * @return array<string, mixed>|null
     */
    private function activityPubPost(array $note, array $profile, array $counts = []): ?array
    {
        $id = $this->link($note['id'] ?? null);
        if ($id === null) {
            return null;
        }

        $author = $this->firstLink($note['attributedTo'] ?? null) ?? '';
        $published = $this->string($note['published'] ?? null) ?? '';
        $summary = $this->string($note['summary'] ?? null);
        $attachments = $this->activityPubAttachments($note, $published);

        return [
            'id' => $id,
            'author_id' => $author !== '' ? $author : $id,
            'author_handle' => $this->string($profile['handle'] ?? null),
            'author_display_name' => $this->string($profile['display_name'] ?? null),
            'author_avatar' => $this->string($profile['avatar'] ?? null),
            'content' => HtmlText::toPlainText($this->string($note['content'] ?? null), 10_000),
            'content_warning' => $summary !== null && trim($summary) !== ''
                ? HtmlText::toPlainText($summary, 500)
                : null,
            'visibility' => $this->visibility($note),
            'published_at' => $published,
            'edited_at' => $this->string($note['updated'] ?? null),
            'deleted_at' => null,
            'in_reply_to_id' => $this->link($note['inReplyTo'] ?? null),
            'recipient_ids' => [],
            'attachments' => $attachments,
            'poll' => null,
            'kind' => 'post',
            'object_id' => null,
            'object_author_id' => null,
            'boosted_by' => null,
            'is_remote' => true,
            'likes_count' => isset($counts['likes']) ? $counts['likes'] : null,
            'replies_count' => isset($counts['replies']) ? $counts['replies'] : null,
            'boosts_count' => isset($counts['boosts']) ? $counts['boosts'] : null,
            'external_url' => $id,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activityPubAttachments(array $note, string $published): array
    {
        $values = $note['attachment'] ?? [];
        if (!is_array($values)) {
            return [];
        }

        $attachments = [];
        foreach ($values as $value) {
            if (!is_array($value)) {
                continue;
            }

            $url = $this->link($value['url'] ?? null);
            if ($url === null) {
                continue;
            }

            $mime = $this->string($value['mediaType'] ?? null) ?? '';
            $type = strtolower($this->string($value['type'] ?? null) ?? '');
            $kind = str_starts_with($mime, 'video/') || $type === 'video'
                ? 'video'
                : (str_starts_with($mime, 'audio/') ? 'audio' : 'image');

            $attachments[] = [
                'id' => $url,
                'media_type' => $kind,
                'mime_type' => $mime !== '' ? $mime : ($kind === 'image' ? 'image/*' : $kind . '/*'),
                'width' => $this->integer($value['width'] ?? null) ?? 0,
                'height' => $this->integer($value['height'] ?? null) ?? 0,
                'byte_size' => 0,
                'alt_text' => $this->string($value['name'] ?? null),
                'created_at' => $published,
                'url' => $url,
                'thumbnail_url' => null,
            ];
        }

        return $attachments;
    }

    /**
     * @param array<string, mixed> $profile
     *
     * @return array<string, mixed>
     */
    private function blueskyProfile(array $profile): array
    {
        $handle = $this->string($profile['handle'] ?? null) ?? '';
        $did = $this->string($profile['did'] ?? null) ?? $handle;
        $display = $this->string($profile['displayName'] ?? null);
        $url = $handle !== '' ? 'https://bsky.app/profile/' . rawurlencode($handle) : 'https://bsky.app';

        return [
            'id' => $handle !== '' ? 'https://bsky.app/profile/' . $handle : $did,
            'did' => $did,
            'handle' => $handle !== '' ? '@' . $handle : $did,
            'display_name' => $display !== null && $display !== '' ? $display : '@' . $handle,
            'url' => $url,
            'avatar' => $this->string($profile['avatar'] ?? null),
            'banner' => $this->string($profile['banner'] ?? null),
            'bio' => HtmlText::toPlainText($this->string($profile['description'] ?? null), 5_000),
            'followers_count' => $this->integer($profile['followersCount'] ?? null),
            'following_count' => $this->integer($profile['followsCount'] ?? null),
            'posts_count' => $this->integer($profile['postsCount'] ?? null),
            'inbox' => null,
            'outbox' => null,
            'is_remote' => true,
            'can_follow' => false,
            'network' => 'bluesky',
            'published_at' => $this->string($profile['createdAt'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $feed
     *
     * @return list<array<string, mixed>>
     */
    private function blueskyPosts(array $feed): array
    {
        $entries = $feed['feed'] ?? [];
        if (!is_array($entries)) {
            return [];
        }

        $posts = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $post = $this->blueskyPost(is_array($entry['post'] ?? null) ? $entry['post'] : []);
            if ($post !== null) {
                $posts[] = $post;
            }

            if (count($posts) >= self::MAX_POSTS) {
                break;
            }
        }

        return $posts;
    }

    /**
     * @param array<string, mixed> $post an AppView post view
     *
     * @return array<string, mixed>|null
     */
    private function blueskyPost(array $post): ?array
    {
        $record = $post['record'] ?? [];
        $author = $post['author'] ?? [];
        if (!is_array($record) || !is_array($author)) {
            return null;
        }

        $uri = $this->string($post['uri'] ?? null);
        if ($uri === null) {
            return null;
        }

        $handle = $this->string($author['handle'] ?? null) ?? '';
        $content = $this->string($record['text'] ?? null) ?? '';
        $published = $this->string($record['createdAt'] ?? null)
            ?? $this->string($post['indexedAt'] ?? null)
            ?? '';

        [$content, $attachments] = $this->blueskyEmbed($post['embed'] ?? null, $content, $published);

        return [
            'id' => $uri,
            'author_id' => $handle !== '' ? 'https://bsky.app/profile/' . $handle : $uri,
            'author_handle' => $handle !== '' ? '@' . $handle : null,
            'author_display_name' => $this->string($author['displayName'] ?? null),
            'author_avatar' => $this->string($author['avatar'] ?? null),
            'content' => $content,
            'content_warning' => null,
            'visibility' => 'public',
            'published_at' => $published,
            'edited_at' => null,
            'deleted_at' => null,
            'in_reply_to_id' => $this->blueskyReplyParent($record),
            'recipient_ids' => [],
            'attachments' => $attachments,
            'poll' => null,
            'kind' => 'post',
            'object_id' => null,
            'object_author_id' => null,
            'boosted_by' => null,
            'is_remote' => true,
            'likes_count' => $this->integer($post['likeCount'] ?? null),
            'replies_count' => $this->integer($post['replyCount'] ?? null),
            'boosts_count' => $this->integer($post['repostCount'] ?? null),
            'external_url' => $this->blueskyPostUrl($uri, $handle),
        ];
    }

    /**
     * The reply parent is an AT URI in the record. Older AppView payloads
     * wrap it as `{uri: …, cid: …}`; both shapes are flattened.
     */
    private function blueskyReplyParent(array $record): ?string
    {
        $reply = $record['reply'] ?? null;
        if (!is_array($reply) || !is_array($reply['parent'] ?? null)) {
            return null;
        }

        return $this->string($reply['parent']['uri'] ?? null);
    }

    private function blueskyPostUrl(string $uri, string $handle): string
    {
        $parts = explode('/', substr($uri, strlen('at://')));
        $rkey = count($parts) >= 3 ? $parts[2] : '';
        if ($handle === '' || $rkey === '') {
            return 'https://bsky.app';
        }

        return 'https://bsky.app/profile/' . rawurlencode($handle) . '/post/' . rawurlencode($rkey);
    }

    /**
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function blueskyEmbed(mixed $embed, string $content, string $published): array
    {
        if (!is_array($embed)) {
            return [$content, []];
        }

        $type = $this->string($embed['$type'] ?? null) ?? $this->string($embed['type'] ?? null) ?? '';
        $attachments = [];

        if (str_starts_with($type, 'app.bsky.embed.images')) {
            $images = $embed['images'] ?? [];
            foreach (is_array($images) ? $images : [] as $image) {
                if (!is_array($image)) {
                    continue;
                }

                $full = $this->string($image['fullsize'] ?? null);
                $thumb = $this->string($image['thumb'] ?? null);
                $url = $full ?? $thumb;
                if ($url === null) {
                    continue;
                }

                $aspect = is_array($image['aspectRatio'] ?? null) ? $image['aspectRatio'] : [];
                $attachments[] = [
                    'id' => $url,
                    'media_type' => 'image',
                    'mime_type' => 'image/*',
                    'width' => $this->integer($aspect['width'] ?? null) ?? 0,
                    'height' => $this->integer($aspect['height'] ?? null) ?? 0,
                    'byte_size' => 0,
                    'alt_text' => $this->string($image['alt'] ?? null),
                    'created_at' => $published,
                    'url' => $url,
                    'thumbnail_url' => $thumb,
                ];
            }
        } elseif (str_starts_with($type, 'app.bsky.embed.video')) {
            $playlist = $this->string($embed['playlist'] ?? null);
            $thumbnail = $this->string($embed['thumbnail'] ?? null);
            $url = $playlist ?? $thumbnail;
            if ($url !== null) {
                $aspect = is_array($embed['aspectRatio'] ?? null) ? $embed['aspectRatio'] : [];
                $attachments[] = [
                    'id' => $url,
                    'media_type' => 'video',
                    'mime_type' => 'video/mp4',
                    'width' => $this->integer($aspect['width'] ?? null) ?? 0,
                    'height' => $this->integer($aspect['height'] ?? null) ?? 0,
                    'byte_size' => 0,
                    'alt_text' => $this->string($embed['alt'] ?? null),
                    'created_at' => $published,
                    'url' => $url,
                    'thumbnail_url' => $thumbnail,
                ];
            }
        } elseif (str_starts_with($type, 'app.bsky.embed.external')) {
            $external = is_array($embed['external'] ?? null) ? $embed['external'] : [];
            $uri = $this->string($external['uri'] ?? null);
            if ($uri !== null) {
                $title = $this->string($external['title'] ?? null);
                $content = rtrim($content) . "\n\n" . ($title !== null && $title !== '' ? $title . "\n" : '') . $uri;
            }
        }

        return [$content, $attachments];
    }

    private function blueskyActor(string $query): string
    {
        $query = trim($query);

        if (str_starts_with($query, 'did:')) {
            return $query;
        }

        if (preg_match('~^(?:https?://)?(?:www\.)?bsky\.app/profile/([^/?#]+)~i', $query, $matches) === 1) {
            $query = rawurldecode($matches[1]);
        }

        $query = ltrim($query, '@');
        if ($query === '') {
            throw new RemoteDirectoryException('remote_handle_missing', 'Please provide a Bluesky profile.', 422);
        }

        if (str_starts_with($query, 'did:')) {
            return $query;
        }

        $data = $this->fetchJson(
            self::BSKY_APPVIEW . 'com.atproto.identity.resolveHandle?handle=' . rawurlencode($query),
            self::JSON_ACCEPT,
        );
        $did = $this->string($data['did'] ?? null);
        if ($did === null) {
            throw new RemoteDirectoryException('bluesky_handle_unknown', 'This Bluesky profile was not found.');
        }

        return $did;
    }

    private function actorUrl(string $query): string
    {
        $query = trim($query);

        if (preg_match('#^https?://#i', $query) === 1 && filter_var($query, FILTER_VALIDATE_URL) !== false) {
            return $query;
        }

        $address = ltrim($query, '@');
        $at = strrpos($address, '@');
        if ($at === false) {
            throw new RemoteDirectoryException(
                'remote_handle_invalid',
                'Please provide a profile like @name@server.tld or a profile URL.',
                422,
            );
        }

        $user = substr($address, 0, $at);
        $host = substr($address, $at + 1);
        if ($user === '' || $host === '' || str_contains($user, '@')) {
            throw new RemoteDirectoryException('remote_handle_invalid', 'This profile handle is invalid.', 422);
        }

        $resource = 'acct:' . $user . '@' . $host;
        $webfinger = 'https://' . $host . '/.well-known/webfinger?resource=' . rawurlencode($resource);
        $document = $this->fetchJson($webfinger, self::JRD_ACCEPT);

        $links = $document['links'] ?? [];
        foreach (is_array($links) ? $links : [] as $link) {
            if (!is_array($link) || ($link['rel'] ?? null) !== 'self') {
                continue;
            }

            $type = $this->string($link['type'] ?? null) ?? '';
            if ($type === '' || str_contains($type, 'activity+json') || str_contains($type, 'ld+json')) {
                $href = $this->string($link['href'] ?? null);
                if ($href !== null) {
                    return $href;
                }
            }
        }

        throw new RemoteDirectoryException(
            'webfinger_not_found',
            'The server did not publish an ActivityPub profile for this handle.',
            404,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchJson(string $url, string $accept): array
    {
        $response = $this->fetch($url, $accept);

        try {
            $data = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RemoteDirectoryException('remote_invalid_response', 'The remote server did not return a valid JSON response.');
        }

        if (!is_array($data)) {
            throw new RemoteDirectoryException('remote_invalid_response', 'The remote server returned an unexpected response.');
        }

        return $data;
    }

    private function fetch(string $url, string $accept): HttpResponse
    {
        $current = $url;
        for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++) {
            try {
                $addresses = $this->guard->addressesFor($current);
            } catch (RemoteFetchException $exception) {
                throw new RemoteDirectoryException($exception->reason, $this->fetchMessage($exception->reason), 403);
            }

            try {
                $response = $this->http->requestBounded(
                    'GET',
                    $current,
                    ['Accept' => $accept],
                    '',
                    self::TIMEOUT_SECONDS,
                    self::MAX_BYTES,
                    $addresses,
                );
            } catch (ResponseLimitExceededException) {
                throw new RemoteDirectoryException('remote_response_too_large', 'The remote server\'s response is too large.');
            } catch (HttpClientException) {
                throw new RemoteDirectoryException('remote_unreachable', 'The remote server is unreachable.');
            }

            if (in_array($response->status, [301, 302, 303, 307, 308], true)) {
                $location = $response->header('location');
                if ($location === null || $location === '') {
                    throw new RemoteDirectoryException('remote_redirect_invalid', 'The remote server returned an invalid redirect.');
                }

                $current = $this->resolveUrl($current, $location);
                continue;
            }

            if (!$response->isSuccessful()) {
                throw new RemoteDirectoryException(
                    'remote_http_error',
                    'The remote server responded with HTTP ' . $response->status . '.',
                    in_array($response->status, [401, 403, 404], true) ? $response->status : 502,
                );
            }

            return $response;
        }

        throw new RemoteDirectoryException('remote_redirect_loop', 'The remote server redirects too often.');
    }

    private function resolveUrl(string $base, string $reference): string
    {
        if (preg_match('#^https?://#i', $reference) === 1) {
            return $reference;
        }

        $parts = parse_url($base);
        if (!is_array($parts)) {
            throw new RemoteDirectoryException('remote_redirect_invalid', 'The redirect could not be resolved.');
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        if (str_starts_with($reference, '//')) {
            return $scheme . ':' . $reference;
        }

        if (str_starts_with($reference, '/')) {
            return $scheme . '://' . $host . $port . $reference;
        }

        $path = $parts['path'] ?? '/';
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $scheme . '://' . $host . $port . $directory . $reference;
    }

    private function collectionCount(?string $url): ?int
    {
        if ($url === null) {
            return null;
        }

        try {
            $collection = $this->fetchJson($url, self::ACTIVITY_ACCEPT);
        } catch (RemoteDirectoryException) {
            return null;
        }

        return $this->integer($collection['totalItems'] ?? null);
    }

    /**
     * @param array<string, mixed> $note
     */
    private function visibility(array $note): string
    {
        $audience = [];
        foreach (['to', 'cc'] as $field) {
            $value = $note[$field] ?? [];
            foreach (is_array($value) ? $value : [$value] as $entry) {
                if (is_string($entry)) {
                    $audience[] = $entry;
                }
            }
        }

        if (in_array('https://www.w3.org/ns/activitystreams#Public', $audience, true)) {
            return 'public';
        }

        foreach ($audience as $entry) {
            if (str_ends_with($entry, '/followers')) {
                return 'followers';
            }
        }

        return 'unlisted';
    }

    private function isBlueskyQuery(string $query): bool
    {
        $query = trim($query);
        if (str_starts_with($query, 'did:')) {
            return true;
        }

        if (preg_match('~^(?:https?://)?(?:www\.)?bsky\.app/profile/~i', $query) === 1) {
            return true;
        }

        // Any other absolute URL is an ActivityPub actor or profile URL, not
        // a Bluesky handle. Without this a `/users/<name>` URL would be sent
        // to the AppView resolver.
        if (preg_match('~^https?://~i', $query) === 1) {
            return false;
        }

        $address = ltrim($query, '@');
        if (str_contains($address, '@')) {
            return false;
        }

        return str_contains($address, '.');
    }

    private function firstLink(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (!is_array($value)) {
            return null;
        }

        if (!array_is_list($value)) {
            return $this->link($value);
        }

        foreach ($value as $entry) {
            $link = $this->link($entry);
            if ($link !== null) {
                return $link;
            }
        }

        return null;
    }

    private function link(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (!is_array($value)) {
            return null;
        }

        foreach (['href', 'url', 'id'] as $key) {
            if (isset($value[$key])) {
                $link = $this->link($value[$key]);
                if ($link !== null) {
                    return $link;
                }
            }
        }

        return null;
    }

    private function imageUrl(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (!is_array($value)) {
            return null;
        }

        return $this->link($value['url'] ?? $value['href'] ?? null);
    }

    private function string(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return null;
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        return null;
    }

    private function sameOrigin(string $left, string $right): bool
    {
        $leftParts = parse_url($left);
        $rightParts = parse_url($right);
        if (!is_array($leftParts) || !is_array($rightParts)) {
            return false;
        }

        return strtolower((string) ($leftParts['host'] ?? '')) === strtolower((string) ($rightParts['host'] ?? ''))
            && (int) ($leftParts['port'] ?? 0) === (int) ($rightParts['port'] ?? 0);
    }

    private function fetchMessage(string $reason): string
    {
        return match ($reason) {
            'ssrf_blocked' => 'This address is not allowed for security reasons.',
            'invalid_url' => 'The profile address is invalid.',
            'dns_failed' => 'The profile\'s server could not be found.',
            default => 'The profile could not be fetched.',
        };
    }
}
