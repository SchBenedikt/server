<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Http;

use AlohaSocial\Core\Http\Response;

use function str_starts_with;

/**
 * Cross-origin policy for the bearer-token client API.
 *
 * Browser clients (Elk, Phanpy, Pinafore and the like) call the Mastodon
 * compatibility surface from another origin. The reference host answers
 * those routes — and only those — with a wildcard origin, never reflecting
 * the request origin and never sending Allow-Credentials, so a browser
 * cannot expose a session-cookie answer to another origin. The
 * session-authenticated first-party API stays same-origin only.
 */
final class ClientApiCors
{
    /**
     * Routes outside the Mastodon prefix that bearer clients call
     * cross-origin: app registration and the token endpoints.
     *
     * @var list<string>
     */
    private const EXACT_PATHS = ['/api/v1/apps', '/api/v2/instance', '/oauth/token', '/oauth/revoke'];

    public function __construct(
        private readonly string $mastodonPrefix,
    ) {
    }

    public function isScoped(string $path): bool
    {
        $prefix = rtrim($this->mastodonPrefix, '/');
        if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
            return true;
        }

        foreach (self::EXACT_PATHS as $exact) {
            if ($path === $exact) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Idempotency-Key',
            'Access-Control-Expose-Headers' => 'Link, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset',
        ];
    }

    public function preflight(): Response
    {
        return new Response(204, $this->headers());
    }

    public function apply(Response $response): Response
    {
        foreach ($this->headers() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
