<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Support;

use AlohaSocial\Core\Platform\Network\HostResolver;
use AlohaSocial\Core\Service\Federation\RemoteFetcher;
use RuntimeException;

/** Resolves and pins HTTPS destinations used by the read-only PDS smoke check. */
final class PdsDiscoveryTargetGuard
{
    public function __construct(private readonly HostResolver $resolver)
    {
    }

    /** @return list<string> CURLOPT_RESOLVE entries for the validated URL */
    public function curlResolveEntries(string $url): array
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $port = is_array($parts) ? (int) ($parts['port'] ?? 443) : 0;
        if ($scheme !== 'https' || $host === '' || $port < 1 || $port > 65535
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
        ) {
            throw new RuntimeException('Discovery requests must use an HTTPS URL without credentials or a fragment.');
        }

        $addresses = $this->resolver->resolve($host);
        if ($addresses === []) {
            throw new RuntimeException('Discovery host did not resolve: ' . $host);
        }

        $entries = [];
        foreach ($addresses as $address) {
            if (!RemoteFetcher::isPublicAddress($address)) {
                throw new RuntimeException('Discovery host resolved to a non-public address: ' . $address);
            }

            $curlAddress = str_contains($address, ':') ? '[' . $address . ']' : $address;
            $entries[] = $host . ':' . $port . ':' . $curlAddress;
        }

        return $entries;
    }
}
