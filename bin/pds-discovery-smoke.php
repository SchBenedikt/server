<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use AlohaSocial\Server\Config\HostConfig;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this from the command line.\n");
    exit(2);
}

$arguments = array_values(array_filter(
    array_slice($argv, 1),
    static fn (string $argument): bool => $argument !== '--help',
));
if (in_array('--help', array_slice($argv, 1), true)) {
    fwrite(STDOUT, "Usage: php bin/pds-discovery-smoke.php <handle> <at://did:plc:.../app.bsky.feed.post/rkey> [appview-xrpc-base]\n");
    fwrite(STDOUT, "Checks public HTTPS handle resolution, PLC DID linkage, the configured PDS and external AppView indexing. It is read-only.\n");
    exit(0);
}

$handle = strtolower(trim((string) ($arguments[0] ?? '')));
$postUri = trim((string) ($arguments[1] ?? ''));
$appView = rtrim(trim((string) ($arguments[2] ?? 'https://public.api.bsky.app/xrpc')), '/');

if (preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $handle) !== 1) {
    fwrite(STDERR, "Provide a valid AT Protocol handle.\n");
    exit(2);
}
if (preg_match('#^at://(did:plc:[a-z2-7]+)/app\.bsky\.feed\.post/([a-zA-Z0-9._~:-]+)$#D', $postUri, $uriMatch) !== 1) {
    fwrite(STDERR, "Provide a public app.bsky.feed.post AT URI for the account.\n");
    exit(2);
}
$postDid = $uriMatch[1];

try {
    $config = HostConfig::load($root);
    if (!$config->atprotoPds) {
        throw new RuntimeException('The native AT Protocol PDS is disabled in this configuration.');
    }
    $pdsOrigin = parse_url($config->atprotoPdsUrl);
    if (!is_array($pdsOrigin) || ($pdsOrigin['scheme'] ?? '') !== 'https'
        || !isset($pdsOrigin['host']) || isset($pdsOrigin['user']) || isset($pdsOrigin['pass'])
        || isset($pdsOrigin['query']) || isset($pdsOrigin['fragment'])
        || (($pdsOrigin['path'] ?? '') !== '' && $pdsOrigin['path'] !== '/')) {
        throw new RuntimeException('The configured native PDS URL must be a public HTTPS origin.');
    }

    $wellKnownUrl = 'https://' . $handle . '/.well-known/atproto-did';
    $did = trim(pdsDiscoveryRequest($wellKnownUrl, 'text/plain'));
    if (preg_match('/^did:plc:[a-z2-7]+$/D', $did) !== 1) {
        throw new RuntimeException('The handle well-known endpoint did not return a valid PLC DID.');
    }
    pdsDiscoveryPass('handle HTTPS endpoint resolves to ' . $did);

    if ($did !== $postDid) {
        throw new RuntimeException('The post URI DID does not match the DID resolved from the handle.');
    }

    $plcBase = rtrim($config->atprotoPlcUrl, '/');
    $didDocument = pdsDiscoveryJson($plcBase . '/' . $did);
    if (($didDocument['id'] ?? null) !== $did) {
        throw new RuntimeException('The PLC DID document id does not match the resolved DID.');
    }
    if (!in_array('at://' . $handle, $didDocument['alsoKnownAs'] ?? [], true)) {
        throw new RuntimeException('The PLC DID document does not claim this handle.');
    }

    $pdsEndpoint = null;
    foreach ($didDocument['service'] ?? [] as $service) {
        if (!is_array($service)) {
            continue;
        }
        $serviceId = (string) ($service['id'] ?? '');
        if (str_ends_with($serviceId, '#atproto_pds') || $serviceId === 'atproto_pds') {
            $pdsEndpoint = $service['serviceEndpoint'] ?? null;
            break;
        }
    }
    if (!is_string($pdsEndpoint) || rtrim($pdsEndpoint, '/') !== rtrim($config->atprotoPdsUrl, '/')) {
        throw new RuntimeException('The PLC DID document does not point at the configured native PDS.');
    }
    pdsDiscoveryPass('PLC document claims the handle and configured PDS');

    $description = pdsDiscoveryJson(
        rtrim($config->atprotoPdsUrl, '/') . '/xrpc/com.atproto.server.describeServer',
    );
    if (!isset($description['did']) || !is_string($description['did'])) {
        throw new RuntimeException('The configured PDS did not return a valid describeServer response.');
    }
    pdsDiscoveryPass('configured PDS serves com.atproto.server.describeServer');

    $resolved = pdsDiscoveryJson($appView . '/com.atproto.identity.resolveHandle?' . http_build_query(
        ['handle' => $handle],
        '',
        '&',
        PHP_QUERY_RFC3986,
    ));
    if (($resolved['did'] ?? null) !== $did) {
        throw new RuntimeException('The external AppView resolves the handle to a different DID.');
    }
    pdsDiscoveryPass('external AppView resolves the handle to ' . $did);

    $threadUrl = $appView . '/app.bsky.feed.getPostThread?' . http_build_query(
        ['uri' => $postUri, 'depth' => 0],
        '',
        '&',
        PHP_QUERY_RFC3986,
    );
    $thread = pdsDiscoveryJson($threadUrl);
    $indexedUri = $thread['thread']['post']['uri'] ?? null;
    if ($indexedUri !== $postUri) {
        throw new RuntimeException('The external AppView has not indexed the supplied post URI.');
    }
    pdsDiscoveryPass('external AppView can read the public post (relay indexing verified)');
} catch (Throwable $exception) {
    fwrite(STDERR, 'PDS discovery smoke failed: ' . $exception->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "PDS external discovery smoke passed.\n");

function pdsDiscoveryRequest(string $url, string $accept): string
{
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Could not initialize the HTTP client.');
    }

    $body = '';
    $tooLarge = false;
    curl_setopt_array($handle, [
        CURLOPT_HTTPGET => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['Accept: ' . $accept],
        CURLOPT_USERAGENT => 'AlohaSocial-PDS-Discovery-Smoke/1.0',
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge): int {
            if (strlen($body) + strlen($chunk) > 2_097_152) {
                $tooLarge = true;

                return 0;
            }
            $body .= $chunk;

            return strlen($chunk);
        },
    ]);
    $completed = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    if ($tooLarge) {
        throw new RuntimeException('Response exceeded the 2 MiB safety limit: ' . $url);
    }
    if ($completed !== true) {
        throw new RuntimeException('Request failed for ' . $url . ': ' . $error);
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Request returned HTTP ' . $status . ': ' . $url);
    }

    return $body;
}

/** @return array<string, mixed> */
function pdsDiscoveryJson(string $url): array
{
    try {
        $decoded = json_decode(pdsDiscoveryRequest($url, 'application/json'), true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Response was not valid JSON: ' . $url, previous: $exception);
    }
    if (!is_array($decoded)) {
        throw new RuntimeException('Response was not a JSON object: ' . $url);
    }

    return $decoded;
}

function pdsDiscoveryPass(string $message): void
{
    fwrite(STDOUT, 'PASS ' . $message . "\n");
}
