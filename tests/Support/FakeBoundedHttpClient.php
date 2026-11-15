<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests\Support;

use AlohaSocial\Core\Platform\Http\BoundedHttpClient;
use AlohaSocial\Core\Platform\Http\HttpResponse;

/**
 * An in-memory HTTP client for remote-profile tests.
 *
 * Requests are matched by exact URL, so a typo in a WebFinger, actor or
 * AppView URL fails the test instead of silently reaching the network.
 */
final class FakeBoundedHttpClient implements BoundedHttpClient
{
    /** @var array<string, HttpResponse> */
    private array $responses = [];

    /** @var list<string> */
    public array $requests = [];

    /**
     * @param array<string, mixed> $data
     */
    public function json(string $url, array $data, int $status = 200): void
    {
        $this->responses[$url] = new HttpResponse(
            $status,
            ['content-type' => 'application/json'],
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    public function html(string $url, string $body, int $status = 401): void
    {
        $this->responses[$url] = new HttpResponse(
            $status,
            ['content-type' => 'text/plain'],
            $body,
        );
    }

    public function request(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
        int $timeoutSeconds = 10,
    ): HttpResponse {
        return $this->requestBounded($method, $url, $headers, $body, $timeoutSeconds);
    }

    public function requestBounded(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
        int $timeoutSeconds = 10,
        int $maxBytes = 1_048_576,
        array $resolvedIps = [],
    ): HttpResponse {
        $this->requests[] = $url;

        return $this->responses[$url] ?? new HttpResponse(404, ['content-type' => 'text/plain'], 'not found');
    }
}
