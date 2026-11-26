<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Federation;

use AlohaSocial\Core\Platform\Http\BoundedHttpClient;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use Psr\Log\LoggerInterface;

use function array_unique;
use function implode;
use function is_array;
use function is_string;
use function json_decode;
use function mb_substr;
use function parse_url;
use function preg_match;
use function str_contains;
use function strtolower;
use function strtoupper;
use function trim;

use const PHP_URL_PATH;

/**
 * Keeps the peer's rejection reason for a failed inbox delivery.
 *
 * The delivery handler can only report the HTTP status to the job queue. This
 * wrapper does not change the request or the response; it writes the status
 * and the peer's own JSON message to the runtime log before the handler fails
 * the job, so an operator can tell a signature refusal from a validation
 * error instead of seeing a bare "failed with HTTP 401".
 */
final class FederationHttpDiagnostics implements BoundedHttpClient
{
    public function __construct(
        private readonly BoundedHttpClient $inner,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function request(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
        int $timeoutSeconds = 10,
    ): HttpResponse {
        return $this->observe($this->inner->request($method, $url, $headers, $body, $timeoutSeconds), $method, $url);
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
        return $this->observe(
            $this->inner->requestBounded($method, $url, $headers, $body, $timeoutSeconds, $maxBytes, $resolvedIps),
            $method,
            $url,
        );
    }

    private function observe(HttpResponse $response, string $method, string $url): HttpResponse
    {
        if (strtoupper($method) !== 'POST' || $response->isSuccessful()) {
            return $response;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        if (preg_match('#/inbox/?$#i', $path) !== 1) {
            return $response;
        }

        $this->logger->warning(
            'Federation delivery to {url} failed with HTTP {status}: {peer_message}',
            [
                'url' => $url,
                'status' => $response->status,
                'peer_message' => $this->detail($response),
            ],
        );

        return $response;
    }

    private function detail(HttpResponse $response): string
    {
        $body = trim($response->body);
        if ($body === '') {
            return '(empty response)';
        }

        $data = json_decode($body, true);
        if (is_array($data)) {
            $parts = [];
            foreach (['error', 'message'] as $key) {
                $value = $data[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $parts[] = trim($value);
                }
            }

            if ($parts !== []) {
                return mb_substr(implode(': ', array_unique($parts)), 0, 300);
            }
        }

        if (str_contains($body, '<')) {
            return '(non-JSON response)';
        }

        return mb_substr($body, 0, 300);
    }
}
