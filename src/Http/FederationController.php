<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Http;

use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Server\Bootstrap\Runtime;
use AlohaSocial\Server\Support\CoreVersion;
use AlohaSocial\Server\Support\ServerVersion;
use AlohaSocial\Core\Support\Time;

/**
 * Public discovery documents the core does not route itself.
 *
 * The core publishes Create activities whose ids end in `/activity` and uses
 * `/api/v1/posts/{id}` as the Note id. Those URLs must themselves be readable
 * as ActivityPub, otherwise a peer that dereferences an activity or a remote
 * post receives the host's internal API JSON (or a 404) and drops the post.
 *
 * NodeInfo is the second discovery surface: directory services and some
 * servers probe it to confirm that the origin actually speaks ActivityPub.
 */
final class FederationController
{
    private const ACTIVITY_CONTENT_TYPE = 'application/activity+json; charset=utf-8';

    private readonly ActivityFactory $activities;

    public function __construct(
        private readonly Runtime $runtime,
    ) {
        $this->activities = new ActivityFactory(
            $runtime->config->coreConfig(),
            $runtime->app->media,
        );
    }

    /**
     * Add the CORS header WebFinger requires without taking over the core's
     * account lookup and 404 rules.
     */
    public function decorate(Request $request, Response $response): Response
    {
        if ($request->path === '/.well-known/webfinger') {
            return $response->withHeader('Access-Control-Allow-Origin', '*');
        }

        return $response;
    }

    public function handle(Request $request): ?Response
    {
        if ($request->method !== 'GET') {
            return null;
        }

        if ($request->path === '/.well-known/nodeinfo') {
            return $this->nodeInfoIndex();
        }

        if ($request->path === '/.well-known/atproto-did') {
            return $this->atprotoDid($request);
        }

        if ($request->path === '/nodeinfo/2.0' || $request->path === '/nodeinfo/2.1') {
            return $this->nodeInfo(str_ends_with($request->path, '/2.1') ? '2.1' : '2.0');
        }

        if (preg_match('#^/api/v1/posts/([^/]+)/activity$#', $request->path, $match) === 1) {
            $post = $this->visiblePost(rawurldecode($match[1]));
            if ($post === null) {
                return $this->notFound();
            }

            return $this->activityResponse($this->activities->createNote($post));
        }

        if (preg_match('#^/api/v1/posts/([^/]+)$#', $request->path, $match) === 1
            && $this->wantsActivityPub($request)
        ) {
            $post = $this->visiblePost(rawurldecode($match[1]));
            if ($post === null) {
                return $this->notFound();
            }

            // The Create embeds the exact Note the outbox and delivery use, so
            // the dereferenced object and the delivered object cannot drift.
            $activity = $this->activities->createNote($post);
            $note = $activity['object'] ?? null;
            if (!is_array($note)) {
                return $this->notFound();
            }

            return Response::json($note, 200, [
                'Content-Type' => self::ACTIVITY_CONTENT_TYPE,
                'Vary' => 'Accept',
                'Access-Control-Allow-Origin' => '*',
            ]);
        }

        return null;
    }

    private function visiblePost(string $id): ?Post
    {
        if ($id === '' || str_contains($id, '/')) {
            return null;
        }

        try {
            return $this->runtime->app->posts->getVisible($id, null);
        } catch (PostNotFoundException) {
            return null;
        }
    }

    private function wantsActivityPub(Request $request): bool
    {
        $accept = strtolower((string) $request->header('accept'));

        return str_contains($accept, 'application/activity+json')
            || (str_contains($accept, 'application/ld+json')
                && str_contains($accept, 'activitystreams'));
    }

    /**
     * @param array<string, mixed> $activity
     */
    private function activityResponse(array $activity): Response
    {
        return Response::json($activity, 200, [
            'Content-Type' => self::ACTIVITY_CONTENT_TYPE,
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * HTTPS handle verification for the optional native PDS.
     *
     * A handle like `alice.social.example` is verified by requesting
     * `https://alice.social.example/.well-known/atproto-did`; the PDS stores
     * the DID for each local identity, so this route only translates the
     * request host back into that handle.
     */
    private function atprotoDid(Request $request): Response
    {
        if (!$this->runtime->config->atprotoPds) {
            return $this->notFound();
        }

        $domain = $this->runtime->config->atprotoPdsHandleDomain;
        $host = strtolower(trim((string) ($request->header('host') ?? $this->runtime->config->host())));
        $host = explode(':', $host)[0];

        $handle = '';
        if ($domain !== '' && $host !== $domain && str_ends_with($host, '.' . $domain)) {
            $handle = substr($host, 0, -strlen('.' . $domain)) . '.' . $domain;
        } elseif ($domain !== '' && $host === $domain) {
            $handle = trim((string) $request->queryParam('handle'));
        }

        if ($handle === '') {
            return $this->notFound();
        }

        $identity = $this->runtime->pdsRepository->identityByHandle($handle);
        if ($identity === null) {
            return $this->notFound();
        }

        return new Response(200, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ], $identity->did . "\n");
    }

    private function nodeInfoIndex(): Response
    {
        $base = $this->runtime->config->instanceUrl;

        return Response::json([
            'links' => [
                [
                    'rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.0',
                    'href' => $base . '/nodeinfo/2.0',
                ],
                [
                    'rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.1',
                    'href' => $base . '/nodeinfo/2.1',
                ],
            ],
        ], 200, [
            'Content-Type' => 'application/jrd+json; charset=utf-8',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    private function nodeInfo(string $version): Response
    {
        $now = $this->runtime->clock->now();
        $month = Time::format($now->modify('-30 days'));
        $halfYear = Time::format($now->modify('-180 days'));
        $users = (int) $this->runtime->database->fetchValue(
            'SELECT COUNT(*) FROM account WHERE disabled_at IS NULL',
        );
        $activeMonth = $this->activeUsersSince($month);
        $activeHalfYear = $this->activeUsersSince($halfYear);
        $posts = (int) $this->runtime->database->fetchValue(
            "SELECT COUNT(*) FROM social_post WHERE deleted_at IS NULL AND visibility = 'public'",
        );

        $metadata = [
            'nodeName' => $this->runtime->config->instanceName,
        ];
        if ($this->runtime->config->instanceDescription !== '') {
            $metadata['nodeDescription'] = $this->runtime->config->instanceDescription;
        }
        if ($this->runtime->config->contactEmail !== '') {
            $metadata['contactEmail'] = $this->runtime->config->contactEmail;
        }

        return Response::json([
            'version' => $version,
            'software' => [
                'name' => 'alohasocial',
                'version' => ServerVersion::current(),
            ],
            'protocols' => ['activitypub'],
            'services' => ['inbound' => [], 'outbound' => []],
            'openRegistrations' => $this->runtime->config->registrations,
            'usage' => [
                'users' => [
                    'total' => $users,
                    'activeMonth' => $activeMonth,
                    'activeHalfyear' => $activeHalfYear,
                ],
                'localPosts' => $posts,
            ],
            'metadata' => $metadata + ['coreVersion' => CoreVersion::current()],
        ], 200, [
            'Content-Type' => 'application/json; profile="http://nodeinfo.diaspora.software/ns/schema/'
                . $version . '"; charset=utf-8',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * A user counts as active after posting or signing in within the window.
     * Disabled accounts are excluded and the union avoids counting a user twice.
     */
    private function activeUsersSince(string $since): int
    {
        return (int) $this->runtime->database->fetchValue(
            'SELECT COUNT(*) FROM ('
            . 'SELECT author_id AS id FROM social_post WHERE deleted_at IS NULL AND published_at >= :since_post '
            . 'UNION SELECT account_id AS id FROM session WHERE account_id IS NOT NULL AND created_at >= :since_session'
            . ') active JOIN account a ON a.id = active.id WHERE a.disabled_at IS NULL',
            ['since_post' => $since, 'since_session' => $since],
        );
    }

    private function notFound(): Response
    {
        return Response::json([
            'error' => [
                'code' => 'post_not_found',
                'message' => 'Post not found.',
            ],
        ], 404);
    }
}
