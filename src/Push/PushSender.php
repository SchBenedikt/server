<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Push;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Http\BoundedHttpClient;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Platform\Http\ResponseLimitExceededException;
use AlohaSocial\Core\Service\Federation\FederationTargetGuard;
use AlohaSocial\Core\Service\Federation\RemoteFetchException;
use Psr\Log\LoggerInterface;
use Throwable;

use function is_int;
use function is_string;
use function parse_url;

use const PHP_URL_HOST;
use const PHP_URL_SCHEME;

/**
 * Delivers one encrypted Web Push message to a browser endpoint.
 *
 * The endpoint was validated at subscribe time, but the worker re-checks it
 * through the federation target guard anyway: DNS can change after the fact,
 * and the guard both refuses private answers and hands the HTTP client the
 * resolved public addresses to pin. A 404/410 means the browser dropped the
 * subscription, so the row is removed instead of retried forever.
 */
final class PushSender
{
    private const TIMEOUT_SECONDS = 10;

    private const MAX_RESPONSE_BYTES = 4096;

    public function __construct(
        private readonly PushService $subscriptions,
        private readonly BoundedHttpClient $http,
        private readonly FederationTargetGuard $guard,
        private readonly string $instanceUrl,
        private readonly string $contactEmail,
        private readonly Clock $clock,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Sends $payload to every subscription. Returns per-endpoint outcomes so
     * a caller (or the worker log) can see partial failures.
     *
     * @return array{sent: int, gone: int, failed: int}
     */
    public function deliverAll(string $payload): array
    {
        $outcome = ['sent' => 0, 'gone' => 0, 'failed' => 0];
        foreach ($this->subscriptions->deliverableSubscriptions() as $subscription) {
            $result = $this->deliver($subscription, $payload);
            ++$outcome[$result];
        }

        return $outcome;
    }

    /**
     * Sends $payload to one account's subscriptions — the worker resolves the
     * recipient's endpoints at send time so a revoked row never fires.
     *
     * @return array{sent: int, gone: int, failed: int}
     */
    public function deliverForAccount(string $accountId, string $payload): array
    {
        $outcome = ['sent' => 0, 'gone' => 0, 'failed' => 0];
        foreach ($this->subscriptions->deliverableForAccount($accountId) as $subscription) {
            $result = $this->deliver($subscription, $payload);
            ++$outcome[$result];
        }

        return $outcome;
    }

    /**
     * @param array<string, mixed> $subscription deliverableSubscriptions() row
     */
    public function deliver(array $subscription, string $payload): string
    {
        $endpoint = (string) ($subscription['endpoint'] ?? '');
        try {
            $encrypted = WebPush::encrypt(
                (string) ($subscription['p256dh'] ?? ''),
                (string) ($subscription['auth'] ?? ''),
                $payload,
            );
            $addresses = $this->guard->addressesFor($endpoint);
        } catch (Throwable $exception) {
            $this->logger?->warning('Push delivery could not be prepared.', [
                'reason' => $exception instanceof RemoteFetchException
                    ? $exception->reason
                    : 'push_crypto_failed',
            ]);

            return 'failed';
        }

        $keyPair = $this->subscriptions->vapidKeyPair();
        $jwt = WebPush::vapidJwt(
            $this->origin($endpoint),
            $this->vapidSubject(),
            $keyPair['private_pem'],
            $this->clock->now()->getTimestamp(),
        );
        $publicKey = $keyPair['public_key'];

        try {
            $response = $this->http->requestBounded(
                'POST',
                $endpoint,
                [
                    'Authorization' => 'vapid t=' . $jwt . ', k=' . $publicKey,
                    'Content-Encoding' => $encrypted['content_encoding'],
                    'Content-Type' => 'application/octet-stream',
                    'TTL' => '86400',
                    'Urgency' => 'normal',
                ],
                $encrypted['body'],
                self::TIMEOUT_SECONDS,
                self::MAX_RESPONSE_BYTES,
                $addresses,
            );
        } catch (ResponseLimitExceededException|HttpClientException $exception) {
            $this->logger?->warning('Push delivery failed in transport.', [
                'reason' => $exception::class,
            ]);

            return 'failed';
        }

        if ($response->status === 404 || $response->status === 410) {
            $this->subscriptions->removeEndpoint($endpoint);

            return 'gone';
        }

        if ($response->isSuccessful()) {
            return 'sent';
        }

        $this->logger?->warning('Push service rejected a delivery.', [
            'status' => $response->status,
        ]);

        return 'failed';
    }

    private function origin(string $endpoint): string
    {
        $scheme = parse_url($endpoint, PHP_URL_SCHEME);
        $host = parse_url($endpoint, PHP_URL_HOST);
        $port = parse_url($endpoint, PHP_URL_PORT);

        return (is_string($scheme) ? $scheme : 'https') . '://' . (is_string($host) ? $host : '')
            . (is_int($port) ? ':' . $port : '');
    }

    private function vapidSubject(): string
    {
        $email = trim($this->contactEmail);
        if ($email !== '') {
            return 'mailto:' . $email;
        }

        return $this->instanceUrl;
    }
}
