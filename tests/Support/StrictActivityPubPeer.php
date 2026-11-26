<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests\Support;

use AlohaSocial\Core\Platform\Http\BoundedHttpClient;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Protocol\ActivityPub\HttpSignature;
use AlohaSocial\Core\Protocol\ActivityPub\SignatureException;
use DateTimeImmutable;

use function array_change_key_case;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function parse_url;
use function preg_match;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function trim;

use const CASE_LOWER;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_URL_HOST;
use const PHP_URL_PATH;
use const PHP_URL_QUERY;

/**
 * A strict ActivityPub peer for tests.
 *
 * It behaves like an authorized-fetch instance: actor and object reads need a
 * valid HTTP signature, inbox deliveries need a signed digest, an
 * activity+json content type and the expected body. Every accepted activity is
 * kept so a test can assert what the peer actually received.
 */
final class StrictActivityPubPeer implements BoundedHttpClient
{
    public const ACTOR = 'https://peer.test/users/carol';

    public const INBOX = 'https://peer.test/users/carol/inbox';

    public const NOTE = 'https://peer.test/users/carol/statuses/2';

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string, status: int}> */
    public array $requests = [];

    /** @var list<array<string, mixed>> */
    public array $accepted = [];

    public ?string $publicKeyPem = null;

    public function setPublicKeyPem(string $publicKeyPem): void
    {
        $this->publicKeyPem = $publicKeyPem;
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
        $lower = array_change_key_case($headers, CASE_LOWER);
        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        $target = $path . (is_string($query) ? '?' . $query : '');
        $response = $this->route($method, $url, $target, $lower, $body);

        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $lower,
            'body' => $body,
            'status' => $response->status,
        ];

        return $response;
    }

    /**
     * @param array<string, string> $headers lower-case
     */
    private function route(string $method, string $url, string $target, array $headers, string $body): HttpResponse
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($method === 'GET' && $path === '/users/carol') {
            $error = $this->signedGetError($method, $target, $headers);

            return $error ?? $this->json([
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => self::ACTOR,
                'type' => 'Person',
                'preferredUsername' => 'carol',
                'name' => 'Carol Remote',
                'inbox' => self::INBOX,
                'outbox' => self::ACTOR . '/outbox',
                'publicKey' => [
                    'id' => self::ACTOR . '#main-key',
                    'owner' => self::ACTOR,
                    'publicKeyPem' => "-----BEGIN PUBLIC KEY-----\nMFwwDQYJKoZIhvcNAQEBBQADSwAwSAJBAL7l9H0n\n-----END PUBLIC KEY-----\n",
                ],
            ]);
        }

        if ($method === 'GET' && $path === '/users/carol/statuses/2') {
            $error = $this->signedGetError($method, $target, $headers);

            return $error ?? $this->json($this->note());
        }

        if ($method === 'GET' && $path === '/api/v1/statuses/2') {
            $error = $this->signedGetError($method, $target, $headers);

            return $error ?? $this->json(['error' => 'Record not found', 'message' => 'No such status'], 404);
        }

        if ($method === 'POST' && $path === '/users/carol/inbox') {
            return $this->receive($target, $headers, $body);
        }

        return $this->json(['error' => 'not_found', 'message' => 'No such resource'], 404);
    }

    /**
     * @param array<string, string> $headers lower-case
     */
    private function signedGetError(string $method, string $target, array $headers): ?HttpResponse
    {
        if (!isset($headers['signature'])) {
            return $this->json([
                'error' => 'authorized_fetch_required',
                'message' => 'This resource requires an HTTP signature.',
            ], 401);
        }

        if (!str_contains(strtolower($headers['accept'] ?? ''), 'activity+json')) {
            return $this->json([
                'error' => 'not_acceptable',
                'message' => 'An ActivityPub accept header is required.',
            ], 406);
        }

        $error = $this->verificationError($method, $target, $headers, '');
        if ($error !== null) {
            return $this->json(['error' => 'invalid_signature', 'message' => $error], 401);
        }

        return null;
    }

    /**
     * @param array<string, string> $headers lower-case
     */
    private function receive(string $target, array $headers, string $body): HttpResponse
    {
        if (!str_contains(strtolower($headers['content-type'] ?? ''), 'application/activity+json')) {
            return $this->json([
                'error' => 'unsupported_media_type',
                'message' => 'An inbox delivery must be application/activity+json.',
            ], 415);
        }

        $error = $this->verificationError('POST', $target, $headers, $body);
        if ($error !== null) {
            return $this->json(['error' => 'invalid_signature', 'message' => $error], 401);
        }

        $activity = json_decode($body, true);
        if (!is_array($activity)) {
            return $this->json(['error' => 'invalid_json', 'message' => 'The body is not JSON.'], 400);
        }

        $keyId = $this->keyId($headers['signature'] ?? '');
        $actor = $keyId !== null ? preg_replace('/#.*$/', '', $keyId) : null;
        if (!is_string($actor) || ($activity['actor'] ?? null) !== $actor) {
            return $this->json([
                'error' => 'actor_mismatch',
                'message' => 'The activity actor does not own the signing key.',
            ], 401);
        }

        $type = $activity['type'] ?? null;
        if ($type === 'Like') {
            if (($activity['object'] ?? null) !== self::NOTE) {
                return $this->json(['error' => 'object_mismatch', 'message' => 'The Like does not target the note.'], 422);
            }
        } elseif ($type === 'Create') {
            $object = $activity['object'] ?? null;
            if (!is_array($object)
                || ($object['type'] ?? null) !== 'Note'
                || ($object['inReplyTo'] ?? null) !== self::NOTE
                || ($object['attributedTo'] ?? null) !== $actor
                || !is_string($object['content'] ?? null)
                || trim($object['content']) === ''
            ) {
                return $this->json([
                    'error' => 'invalid_object',
                    'message' => 'The Create does not contain a reply to the note.',
                ], 422);
            }
        } elseif ($type !== 'Undo') {
            return $this->json(['error' => 'unsupported_activity', 'message' => 'Unexpected activity type.'], 422);
        }

        $this->accepted[] = $activity;

        return new HttpResponse(202, ['content-type' => 'application/json'], '{"status":"accepted"}');
    }

    /**
     * @param array<string, string> $headers lower-case
     */
    private function verificationError(string $method, string $target, array $headers, string $body): ?string
    {
        if ($this->publicKeyPem === null) {
            return 'The peer has no trusted key.';
        }

        $host = $headers['host'] ?? null;
        if (!is_string($host) || !str_starts_with($host, 'peer.test')) {
            return 'The host header does not match the peer.';
        }

        try {
            HttpSignature::verify(
                $this->publicKeyPem,
                $method,
                $target,
                $headers,
                $body,
                300,
                new DateTimeImmutable('now'),
            );
        } catch (SignatureException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    private function keyId(string $signature): ?string
    {
        if (preg_match('/keyId="([^"]+)"/', $signature, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    /** @return array<string, mixed> */
    private function note(): array
    {
        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => self::NOTE,
            'type' => 'Note',
            'attributedTo' => self::ACTOR,
            'content' => '<p>Remote post from Carol.</p>',
            'published' => '2026-11-26T09:00:00Z',
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
        ];
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = 200): HttpResponse
    {
        return new HttpResponse(
            $status,
            ['content-type' => 'application/json'],
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }
}
