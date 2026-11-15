<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Http;

use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Server\Admin\AdminException;
use AlohaSocial\Server\Auth\AuthenticatedSession;
use AlohaSocial\Server\Bootstrap\Runtime;
use Throwable;

use function array_key_exists;
use function explode;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function max;
use function min;
use function preg_match;
use function rawurldecode;
use function str_starts_with;
use function trim;

/**
 * The host's administration and reporting API.
 *
 * Admin routes live under `/api/v1/host/admin` and require an admin session.
 * `/api/v1/host/reports` is the only write every signed-in reader may use.
 * CSRF has already been checked by the front controller before this class is
 * called, so every mutation here is a deliberate, authenticated request.
 */
final class AdminApiController
{
    private const PREFIX = '/api/v1/host/admin';

    public function __construct(
        private readonly Runtime $runtime,
    ) {
    }

    public function handle(Request $request, ?AuthenticatedSession $session): ?Response
    {
        $path = $request->path;
        $isAdmin = str_starts_with($path, self::PREFIX);
        $isReport = $path === '/api/v1/host/reports';

        if (!$isAdmin && !$isReport) {
            return null;
        }

        $userId = $session?->userId();
        if ($userId === null) {
            return $this->error(401, 'not_authenticated', 'Für diese Aktion musst du angemeldet sein.');
        }

        if ($isAdmin && $session?->account?->isAdmin() !== true) {
            return $this->error(403, 'admin_required', 'Diese Seite ist der Administration vorbehalten.');
        }

        try {
            if ($isReport) {
                if ($request->method !== 'POST') {
                    return $this->error(405, 'method_not_allowed', 'Diese Route akzeptiert nur POST.');
                }

                $body = $this->jsonBody($request);

                return Response::json([
                    'report' => $this->runtime->admin->createReport(
                        $userId,
                        $this->text($body, 'target_type'),
                        $this->text($body, 'target_id'),
                        $this->text($body, 'category'),
                        $this->optionalText($body, 'comment'),
                    ),
                ], 201);
            }

            return $this->admin($request, $userId);
        } catch (AdminException $exception) {
            return $this->error(
                $exception->status,
                $exception->errorCode,
                $exception->getMessage(),
                $exception->fields,
            );
        } catch (Throwable) {
            return $this->error(500, 'admin_action_failed', 'Die Aktion konnte nicht ausgeführt werden.');
        }
    }

    private function admin(Request $request, string $userId): Response
    {
        $path = $request->path;
        $method = $request->method;

        if ($method === 'GET' && $path === self::PREFIX . '/overview') {
            return Response::json($this->runtime->admin->overview());
        }

        if ($method === 'GET' && $path === self::PREFIX . '/users') {
            return Response::json($this->runtime->admin->users(
                (string) ($request->queryParam('query') ?? ''),
                $this->intParam($request, 'page', 1, 1, 100000),
                $this->intParam($request, 'per_page', 20, 5, 100),
            ));
        }

        if ($method === 'POST' && $path === self::PREFIX . '/users') {
            $body = $this->jsonBody($request);

            return Response::json([
                'user' => $this->runtime->admin->createUser(
                    $userId,
                    $this->text($body, 'handle'),
                    $this->text($body, 'email'),
                    $this->text($body, 'password'),
                    $this->optionalText($body, 'role') ?? 'user',
                    $this->optionalText($body, 'display_name'),
                ),
            ], 201);
        }

        if ($method === 'PATCH' && preg_match('#^' . self::PREFIX . '/users/([^/]+)$#', $path, $match) === 1) {
            $body = $this->jsonBody($request);

            return Response::json([
                'user' => $this->runtime->admin->updateUser(
                    $userId,
                    rawurldecode($match[1]),
                    array_key_exists('display_name', $body) ? $this->optionalText($body, 'display_name') : null,
                    array_key_exists('role', $body) ? $this->optionalText($body, 'role') : null,
                ),
            ]);
        }

        if ($method === 'POST' && preg_match('#^' . self::PREFIX . '/users/([^/]+)/(disable|enable)$#', $path, $match) === 1) {
            $id = rawurldecode($match[1]);
            $user = $match[2] === 'disable'
                ? $this->runtime->admin->disableUser($userId, $id)
                : $this->runtime->admin->enableUser($userId, $id);

            return Response::json(['user' => $user]);
        }

        if ($method === 'POST' && preg_match('#^' . self::PREFIX . '/users/([^/]+)/password$#', $path, $match) === 1) {
            $body = $this->jsonBody($request);
            $revoked = $this->runtime->admin->resetPassword(
                $userId,
                rawurldecode($match[1]),
                $this->text($body, 'password'),
            );

            return Response::json(['revoked_sessions' => $revoked]);
        }

        if ($method === 'POST' && preg_match('#^' . self::PREFIX . '/users/([^/]+)/sessions$#', $path, $match) === 1) {
            return Response::json([
                'revoked_sessions' => $this->runtime->admin->revokeSessions($userId, rawurldecode($match[1])),
            ]);
        }

        if ($method === 'GET' && $path === self::PREFIX . '/reports') {
            return Response::json($this->runtime->admin->reports(
                (string) ($request->queryParam('state') ?? 'open'),
                $this->intParam($request, 'limit', 50, 1, 100),
            ));
        }

        if ($method === 'POST' && preg_match('#^' . self::PREFIX . '/reports/([^/]+)/resolve$#', $path, $match) === 1) {
            $body = $this->jsonBody($request);

            return Response::json([
                'report' => $this->runtime->admin->resolveReport(
                    $userId,
                    rawurldecode($match[1]),
                    $this->text($body, 'state'),
                    $this->optionalText($body, 'note'),
                ),
            ]);
        }

        if ($method === 'DELETE' && preg_match('#^' . self::PREFIX . '/posts/([^/]+)$#', $path, $match) === 1) {
            $this->runtime->admin->deletePost($userId, rawurldecode($match[1]));

            return Response::empty(204);
        }

        if ($method === 'GET' && $path === self::PREFIX . '/jobs') {
            return Response::json($this->runtime->admin->jobs(
                (string) ($request->queryParam('state') ?? 'all'),
                $this->intParam($request, 'limit', 100, 1, 200),
            ));
        }

        if ($method === 'POST' && $path === self::PREFIX . '/jobs/retry-dead') {
            return Response::json(['retried' => $this->runtime->admin->retryDeadJobs($userId)]);
        }

        if ($method === 'POST' && $path === self::PREFIX . '/jobs/purge') {
            return Response::json(['purged' => $this->runtime->admin->purgeDeadJobs($userId)]);
        }

        if ($method === 'POST' && $path === self::PREFIX . '/queue/run') {
            return Response::json(['handled' => $this->runtime->admin->runQueue($userId)]);
        }

        if ($method === 'POST' && preg_match('#^' . self::PREFIX . '/jobs/([^/]+)/retry$#', $path, $match) === 1) {
            return Response::json(['job' => $this->runtime->admin->retryJob($userId, rawurldecode($match[1]))]);
        }

        if ($method === 'DELETE' && preg_match('#^' . self::PREFIX . '/jobs/([^/]+)$#', $path, $match) === 1) {
            $this->runtime->admin->deleteJob($userId, rawurldecode($match[1]));

            return Response::empty(204);
        }

        if ($method === 'GET' && $path === self::PREFIX . '/federation') {
            return Response::json($this->runtime->admin->federation());
        }

        if ($method === 'GET' && $path === self::PREFIX . '/instance') {
            return Response::json($this->runtime->admin->instance());
        }

        if ($method === 'GET' && $path === self::PREFIX . '/audit') {
            return Response::json([
                'entries' => $this->runtime->admin->audit($this->intParam($request, 'limit', 100, 1, 500)),
            ]);
        }

        return $this->error(404, 'admin_route_not_found', 'Diese Admin-Route existiert nicht.');
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(Request $request): array
    {
        $decoded = json_decode($request->body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function text(array $body, string $key): string
    {
        $value = $body[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string, mixed> $body
     */
    private function optionalText(array $body, string $key): ?string
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return null;
        }

        $value = $body[$key];

        return is_string($value) ? trim($value) : null;
    }

    private function intParam(Request $request, string $key, int $default, int $min, int $max): int
    {
        $value = $request->queryParam($key);
        if (is_int($value)) {
            return max($min, min($max, $value));
        }
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
