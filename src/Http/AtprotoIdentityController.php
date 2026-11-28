<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Http;

use AlohaSocial\Core\Domain\Atproto\Pds\PdsError;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Server\Auth\AtprotoSessionRepository;
use AlohaSocial\Server\Auth\AuthenticatedSession;
use AlohaSocial\Server\Bootstrap\Runtime;

/**
 * The member-facing half of the optional native PDS.
 *
 * The host owns the account id and the session; the core owns DID creation,
 * key custody, repository records and PLC operations. This controller only
 * exposes the caller's own identity, never another account's keys or records.
 */
final class AtprotoIdentityController
{
    private const PREFIX = '/api/v1/host/atproto';

    private readonly AtprotoSessionRepository $atprotoSessions;

    public function __construct(
        private readonly Runtime $runtime,
    ) {
        $this->atprotoSessions = new AtprotoSessionRepository($runtime->database);
    }

    public function handle(Request $request, ?AuthenticatedSession $session): ?Response
    {
        if (!str_starts_with($request->path, self::PREFIX)) {
            return null;
        }

        $userId = $session?->userId();
        if ($userId === null) {
            return Response::json([
                'error' => ['code' => 'not_authenticated', 'message' => 'Sign in to manage your AT Protocol identity.'],
            ], 401);
        }

        try {
            if ($request->method === 'GET' && $request->path === self::PREFIX) {
                return Response::json($this->status($userId));
            }

            if ($request->method === 'POST' && $request->path === self::PREFIX . '/identity') {
                return $this->createIdentity($request, $userId);
            }

            if ($request->method === 'POST' && $request->path === self::PREFIX . '/identity/rotate') {
                return $this->rotate($userId);
            }

            if ($request->method === 'GET' && $request->path === self::PREFIX . '/sessions') {
                return Response::json([
                    'sessions' => $this->atprotoSessions->listForAccount(
                        $userId,
                        Time::format($this->runtime->clock->now()),
                    ),
                ]);
            }

            if ($request->method === 'DELETE'
                && preg_match('#^' . preg_quote(self::PREFIX . '/sessions/', '#') . '([0-9a-f]{16,64})$#', $request->path, $match) === 1
            ) {
                $removed = $this->atprotoSessions->revokeForAccount($userId, $match[1]);

                return Response::json(['removed' => $removed], $removed ? 200 : 404);
            }
        } catch (PdsError $error) {
            return Response::json([
                'error' => ['code' => $error->error, 'message' => $error->getMessage()],
            ], $error->status);
        }

        return Response::json([
            'error' => ['code' => 'not_found', 'message' => 'Unknown AT Protocol route.'],
        ], 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function status(string $userId): array
    {
        $identity = $this->runtime->pdsRepository->identityForUser($userId);
        $external = $this->runtime->blueskyAccounts->status($userId);

        return [
            'enabled' => $this->runtime->config->atprotoPds,
            'publish' => $this->runtime->config->atprotoPublish,
            'handle_domain' => $this->runtime->config->atprotoPdsHandleDomain,
            'pds_url' => $this->runtime->config->atprotoPdsUrl,
            'identity' => $identity === null ? null : [
                'did' => $identity->did,
                'handle' => $identity->handle,
                'pds_url' => $this->runtime->config->atprotoPdsUrl,
                'created_at' => $identity->createdAt,
                // The AppView verifies a handle either through this TXT record
                // or through https://<handle>/.well-known/atproto-did. A
                // wildcard certificate frequently does not cover a handle two
                // levels below the zone (alice.a.example.com), so the record is
                // the part an operator can always rely on.
                'verification' => [
                    'dns_name' => '_atproto.' . $identity->handle,
                    'dns_value' => $identity->did,
                    'well_known_url' => 'https://' . $identity->handle . '/.well-known/atproto-did',
                ],
            ],
            'external' => $external,
        ];
    }

    private function createIdentity(Request $request, string $userId): Response
    {
        if ($this->runtime->pds === null) {
            return Response::json([
                'error' => [
                    'code' => 'pds_disabled',
                    'message' => 'This instance does not host a native PDS.',
                ],
            ], 409);
        }

        $existing = $this->runtime->pdsRepository->identityForUser($userId);
        if ($existing !== null) {
            return Response::json(['identity' => [
                'did' => $existing->did,
                'handle' => $existing->handle,
            ]]);
        }

        $body = json_decode($request->body, true);
        $handle = is_array($body) && is_string($body['handle'] ?? null) ? trim($body['handle']) : '';
        $identity = $this->runtime->pds->createIdentity($userId, $handle === '' ? null : $handle);

        return Response::json(['identity' => $identity], 201);
    }

    private function rotate(string $userId): Response
    {
        if ($this->runtime->pds === null) {
            return Response::json([
                'error' => ['code' => 'pds_disabled', 'message' => 'This instance does not host a native PDS.'],
            ], 409);
        }

        return Response::json(['identity' => $this->runtime->pds->rotateKeys($userId)]);
    }
}
