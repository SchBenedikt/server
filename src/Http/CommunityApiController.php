<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Http;

use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Server\Auth\AuthenticatedSession;
use AlohaSocial\Server\Bootstrap\Runtime;
use AlohaSocial\Server\Accounts\Account;
use AlohaSocial\Server\Community\CommunityException;
use Throwable;

use function array_key_exists;
use function is_array;
use function is_string;
use function json_decode;
use function max;
use function min;
use function preg_match;
use function rawurldecode;
use function trim;

/**
 * First-party community routes: announcements, featured tags, trends,
 * memories, stories, collections and post revisions.
 *
 * GET routes are public unless they read the viewer's own data. Mutations are
 * authenticated and pass through the front controller's CSRF check before
 * this class runs, exactly like the admin API.
 */
final class CommunityApiController
{
    public function __construct(
        private readonly Runtime $runtime,
    ) {
    }

    public function handle(Request $request, ?AuthenticatedSession $session, ?string $ip = null): ?Response
    {
        $path = $request->path;
        $method = $request->method;
        $userId = $session?->userId();
        $isAdmin = $session?->account?->isAdmin() === true;

        try {
            if ($method === 'GET' && $path === '/api/v1/host/announcements') {
                if ($request->queryParam('all') === '1') {
                    if (!$isAdmin) {
                        return $this->error(403, 'admin_required', 'Diese Liste ist der Administration vorbehalten.');
                    }

                    return Response::json(['announcements' => $this->runtime->announcements->all()]);
                }

                return Response::json(['announcements' => $this->runtime->announcements->active()]);
            }

            if ($method === 'POST' && $path === '/api/v1/host/announcements') {
                if (!$isAdmin) {
                    return $this->error(403, 'admin_required', 'Nur die Administration kann Ankündigungen schreiben.');
                }

                $body = $this->jsonBody($request);

                return Response::json([
                    'announcement' => $this->runtime->announcements->create(
                        (string) $userId,
                        $body['content'] ?? null,
                        $body['expires_at'] ?? null,
                    ),
                ], 201);
            }

            if (preg_match('#^/api/v1/host/announcements/([^/]+)$#', $path, $matches) === 1
                && in_array($method, ['PATCH', 'DELETE'], true)
            ) {
                if (!$isAdmin) {
                    return $this->error(403, 'admin_required', 'Nur die Administration kann Ankündigungen ändern.');
                }

                $id = rawurldecode($matches[1]);
                if ($method === 'DELETE') {
                    $this->runtime->announcements->delete($id);

                    return Response::json(['deleted' => true]);
                }

                $body = $this->jsonBody($request);

                return Response::json([
                    'announcement' => $this->runtime->announcements->update(
                        $id,
                        $body['content'] ?? null,
                        $body['active'] ?? null,
                        $body['expires_at'] ?? null,
                    ),
                ]);
            }

            if ($method === 'GET' && $path === '/api/v1/host/featured-tags') {
                $account = $this->account($request, $session, 'account');
                if ($account === null) {
                    return $this->error(404, 'account_not_found', 'Dieses Profil gibt es nicht.');
                }

                return Response::json(['tags' => $this->runtime->featuredTags->forAccount($account->id)]);
            }

            if ($method === 'PUT' && $path === '/api/v1/host/featured-tags') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Für diese Aktion musst du angemeldet sein.');
                }

                $body = $this->jsonBody($request);
                $tags = $body['tags'] ?? [];
                if (!is_array($tags)) {
                    return $this->error(422, 'validation_failed', 'Die Hashtags müssen eine Liste sein.');
                }

                return Response::json([
                    'tags' => $this->runtime->featuredTags->replace($userId, $tags),
                ]);
            }

            if ($method === 'GET' && $path === '/api/v1/host/trends') {
                $this->limit($request, $ip, $session);

                return Response::json($this->runtime->trends->trends(
                    $this->intParam($request, 'days', 7, 1, 30),
                    $this->intParam($request, 'limit', 10, 1, 25),
                ));
            }

            if ($method === 'GET' && $path === '/api/v1/host/memories') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Melde dich an, um deine Erinnerungen zu sehen.');
                }

                return Response::json($this->runtime->memories->forAccount($userId));
            }

            if ($method === 'GET' && $path === '/api/v1/host/stories') {
                $this->limit($request, $ip, $session);

                return Response::json($this->runtime->stories->active());
            }

            if ($method === 'POST' && $path === '/api/v1/host/stories') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Für diese Aktion musst du angemeldet sein.');
                }

                $body = $this->jsonBody($request);

                return Response::json([
                    'story' => $this->runtime->stories->create(
                        $userId,
                        $body['content'] ?? null,
                        $body['background'] ?? null,
                    ),
                ], 201);
            }

            if (preg_match('#^/api/v1/host/stories/([^/]+)$#', $path, $matches) === 1 && $method === 'DELETE') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Für diese Aktion musst du angemeldet sein.');
                }

                $this->runtime->stories->delete(rawurldecode($matches[1]), $userId);

                return Response::json(['deleted' => true]);
            }

            if ($method === 'GET' && $path === '/api/v1/host/collections') {
                $account = $this->account($request, $session, 'account');
                if ($account === null) {
                    return $this->error(404, 'account_not_found', 'Dieses Profil gibt es nicht.');
                }

                $owner = $account->id === $userId;

                return Response::json([
                    'collections' => $this->runtime->collections->forAccount($account->id, $owner),
                ]);
            }

            if ($method === 'GET' && $path === '/api/v1/host/collection') {
                $id = trim((string) ($request->queryParam('id') ?? ''));
                if ($id === '') {
                    return $this->error(422, 'validation_failed', 'Die Sammlungs-ID fehlt.');
                }

                $collection = $this->runtime->collections->find($id, $userId);
                if ($collection === null) {
                    return $this->error(404, 'not_found', 'Diese Sammlung gibt es nicht.');
                }

                return Response::json(['collection' => $collection]);
            }

            if ($method === 'POST' && $path === '/api/v1/host/collections') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Für diese Aktion musst du angemeldet sein.');
                }

                $body = $this->jsonBody($request);

                return Response::json([
                    'collection' => $this->runtime->collections->create(
                        $userId,
                        $body['title'] ?? null,
                        $body['description'] ?? '',
                        $body['is_public'] ?? true,
                    ),
                ], 201);
            }

            if (preg_match('#^/api/v1/host/collections/([^/]+)$#', $path, $matches) === 1
                && in_array($method, ['PATCH', 'DELETE'], true)
            ) {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Für diese Aktion musst du angemeldet sein.');
                }

                $id = rawurldecode($matches[1]);
                if ($method === 'DELETE') {
                    $this->runtime->collections->delete($id, $userId);

                    return Response::json(['deleted' => true]);
                }

                $body = $this->jsonBody($request);

                return Response::json([
                    'collection' => $this->runtime->collections->update(
                        $id,
                        $userId,
                        $body['title'] ?? null,
                        $body['description'] ?? null,
                        array_key_exists('is_public', $body) ? $body['is_public'] : null,
                    ),
                ]);
            }

            if (preg_match('#^/api/v1/host/collections/([^/]+)/items$#', $path, $matches) === 1 && $method === 'POST') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Für diese Aktion musst du angemeldet sein.');
                }

                $body = $this->jsonBody($request);
                $postId = is_string($body['post_id'] ?? null) ? trim($body['post_id']) : '';
                if ($postId === '') {
                    return $this->error(422, 'validation_failed', 'Der Beitrag fehlt.', ['post_id' => 'Bitte wähle einen Beitrag.']);
                }

                return Response::json([
                    'collection' => $this->runtime->collections->addItem($userId === null ? '' : rawurldecode($matches[1]), $userId, $postId),
                ]);
            }

            if (preg_match('#^/api/v1/host/collections/([^/]+)/items/([^/]+)$#', $path, $matches) === 1
                && $method === 'DELETE'
            ) {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Für diese Aktion musst du angemeldet sein.');
                }

                return Response::json([
                    'collection' => $this->runtime->collections->removeItem(
                        rawurldecode($matches[1]),
                        $userId,
                        rawurldecode($matches[2]),
                    ),
                ]);
            }

            if ($method === 'GET' && $path === '/api/v1/host/post-revisions') {
                $postId = trim((string) ($request->queryParam('id') ?? ''));
                if ($postId === '') {
                    return $this->error(422, 'validation_failed', 'Die Beitrags-ID fehlt.');
                }

                try {
                    $post = $this->runtime->app->posts->getVisible($postId, $userId);
                } catch (PostNotFoundException) {
                    return $this->error(404, 'post_not_found', 'Dieser Beitrag ist nicht verfügbar.');
                }

                return Response::json([
                    'post_id' => $post->id,
                    'current_revision' => $post->revision,
                    'current' => [
                        'content' => $post->content,
                        'content_warning' => $post->contentWarning,
                        'visibility' => $post->visibility->value,
                        'edited_at' => $post->editedAt === null ? null : \AlohaSocial\Core\Support\Time::format($post->editedAt),
                    ],
                    'revisions' => array_map(
                        static fn ($revision): array => $revision->toArray(),
                        $this->runtime->postRevisions->forPost($post->id),
                    ),
                ]);
            }

            if ($method === 'GET' && $path === '/api/v1/host/drafts') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Melde dich an, um deine Entwürfe zu sehen.');
                }

                return Response::json(['drafts' => $this->runtime->drafts->forAccount($userId)]);
            }

            if ($method === 'POST' && $path === '/api/v1/host/drafts') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Für diese Aktion musst du angemeldet sein.');
                }

                return Response::json([
                    'draft' => $this->runtime->drafts->save($userId, $this->jsonBody($request)),
                ], 201);
            }

            if (preg_match('#^/api/v1/host/drafts/([^/]+)$#', $path, $matches) === 1 && $method === 'DELETE') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Für diese Aktion musst du angemeldet sein.');
                }

                return Response::json([
                    'draft' => $this->runtime->drafts->delete(rawurldecode($matches[1]), $userId),
                ]);
            }

            if ($method === 'GET' && $path === '/api/v1/host/archived') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Melde dich an, um dein Archiv zu sehen.');
                }

                return Response::json(['archived' => $this->runtime->archives->forAccount($userId)]);
            }

            if ($method === 'POST' && $path === '/api/v1/host/archived') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Für diese Aktion musst du angemeldet sein.');
                }

                $body = $this->jsonBody($request);
                $postId = is_string($body['post_id'] ?? null) ? $body['post_id'] : '';

                return Response::json([
                    'archived' => $this->runtime->archives->archive($userId, $postId),
                ], 201);
            }

            if (preg_match('#^/api/v1/host/archived/([^/]+)$#', $path, $matches) === 1 && $method === 'DELETE') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Für diese Aktion musst du angemeldet sein.');
                }

                return Response::json([
                    'archived' => $this->runtime->archives->restore($userId, rawurldecode($matches[1])),
                ]);
            }

            if ($method === 'GET' && $path === '/api/v1/host/for-you') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Melde dich an, um deine persönliche Zeitleiste zu sehen.');
                }

                return Response::json(
                    $this->runtime->forYou->page(
                        $userId,
                        $this->intParam($request, 'limit', 20, 1, 40),
                        $this->intParam($request, 'offset', 0, 0, 10_000),
                    ),
                );
            }
        } catch (CommunityException $exception) {
            return $this->error($exception->status, $exception->errorCode, $exception->getMessage(), $exception->fields);
        } catch (Throwable) {
            return $this->error(500, 'community_failed', 'Die Anfrage konnte nicht verarbeitet werden.');
        }

        return null;
    }

    private function account(Request $request, ?AuthenticatedSession $session, string $parameter): ?Account
    {
        $handle = trim((string) ($request->queryParam($parameter) ?? ''));
        if ($handle !== '') {
            $account = $this->runtime->accounts->findByHandle($handle);

            return $account ?? $this->runtime->accounts->find($handle);
        }

        $userId = $session?->userId();

        return $userId === null ? null : $this->runtime->accounts->find($userId);
    }

    private function limit(Request $request, ?string $ip, ?AuthenticatedSession $session): void
    {
        $identity = $session?->userId() ?? $ip ?? 'anonymous';
        $this->runtime->rateLimiter->attempt('host.community', $identity, 120, 60);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(Request $request): array
    {
        $decoded = json_decode($request->body, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function intParam(Request $request, string $key, int $default, int $min, int $max): int
    {
        $value = $request->queryParam($key);
        if (!is_string($value) || $value === '') {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    /**
     * @param array<string, string> $fields
     */
    private function error(int $status, string $code, string $message, array $fields = []): Response
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        return Response::json(['error' => $error], $status);
    }
}
