<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Federation;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Protocol\ActivityPub\HttpSignature;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Protocol\ActivityPub\SignatureException;

use function is_array;
use function is_string;
use function strtolower;

/**
 * Signs outbound ActivityPub reads with a local actor key.
 *
 * Authorized-fetch peers answer an unsigned actor or object request with 401,
 * which used to make a like unqueueable and a reply impossible. This signer
 * covers the request target, host and date exactly as they go on the wire, so
 * the peer can verify the request the same way it verifies a delivery.
 */
final class ActivityPubRequestSigner
{
    /**
     * @param string|callable(): ?string $userId a local account id or a
     *        provider for the first existing account
     */
    public function __construct(
        private readonly RsaKeyPair $key,
        private readonly ActivityFactory $activities,
        private readonly Clock $clock,
        private readonly mixed $userId,
    ) {
    }

    /**
     * @return array<string, string> the headers to send
     *
     * @throws SignatureException when the request cannot be signed
     */
    public function __invoke(string $method, string $url, string $body = ''): array
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return [];
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return [];
        }

        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return [];
        }

        $userId = is_callable($this->userId) ? ($this->userId)() : $this->userId;
        if (!is_string($userId) || trim($userId) === '') {
            return [];
        }

        // curl omits the default port from the Host header it sends, so an
        // explicit :443 or :80 in the fetched URL must not enter the signature.
        $port = $parts['port'] ?? null;
        $defaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
        if (is_int($port) && !$defaultPort) {
            $host .= ':' . $port;
        }

        $path = $parts['path'] ?? '/';
        $target = (is_string($path) && $path !== '' ? $path : '/')
            . (isset($parts['query']) && is_string($parts['query']) ? '?' . $parts['query'] : '');

        return HttpSignature::sign(
            $this->key,
            $this->activities->keyId($userId),
            $method,
            $target,
            $host,
            $body,
            $this->clock->now(),
        );
    }
}
