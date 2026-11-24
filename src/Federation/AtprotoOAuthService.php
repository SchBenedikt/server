<?php

declare(strict_types=1);

/** SPDX-License-Identifier: AGPL-3.0-or-later */

namespace AlohaSocial\Server\Federation;

use AlohaSocial\Core\Domain\Atproto\AtprotoOAuthAuthorizationRequest;
use AlohaSocial\Core\Domain\Atproto\AtprotoOAuthServer;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Http\BoundedHttpClient;
use AlohaSocial\Core\Platform\Http\HttpClient;
use AlohaSocial\Core\Protocol\Atproto\AtprotoOAuthMetadataResolver;
use AlohaSocial\Core\Protocol\Atproto\Es256DpopProofProvider;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoOAuthClient;
use AlohaSocial\Core\Security\SecretBox;
use AlohaSocial\Core\Service\Federation\FederationTargetGuard;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Server\Config\HostConfig;
use RuntimeException;

/** Owns browser-bound OAuth handshakes; secrets are sealed and state is one-use. */
final class AtprotoOAuthService
{
    private string $clientId;
    private string $redirectUri;

    public function __construct(
        private readonly Connection $db,
        private readonly HttpClient $http,
        private readonly SecretBox $box,
        private readonly Clock $clock,
        private readonly FederationTargetGuard $guard,
        private readonly BlueskyAccounts $accounts,
        HostConfig $config,
    ) {
        $base = rtrim($config->instanceUrl, '/');
        $this->clientId = $base . '/oauth/atproto/client-metadata.json';
        $this->redirectUri = $base . '/oauth/atproto/callback';
    }

    /** @return array<string, mixed> */
    public function clientMetadata(): array
    {
        return [
            'client_id' => $this->clientId,
            'client_name' => 'Aloha Social',
            'client_uri' => preg_replace('#/oauth/atproto/client-metadata\\.json$#', '', $this->clientId),
            'redirect_uris' => [$this->redirectUri],
            'scope' => 'atproto',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'application_type' => 'web',
            'dpop_bound_access_tokens' => true,
        ];
    }

    public function begin(string $userId, string $browserSessionHash, string $handle): string
    {
        if ($browserSessionHash === '') throw new BlueskyException(401, 'not_authenticated', 'Sign in to connect Bluesky.');
        $identity = $this->accounts->resolveIdentity($handle);
        $server = (new AtprotoOAuthMetadataResolver($this->boundedHttp(), $this->guard))->resolve($identity['pds_url']);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false || !openssl_pkey_export($key, $pem)) throw new RuntimeException('Unable to create DPoP signing key.');
        $dpop = new Es256DpopProofProvider($pem, $this->clock);
        $client = $this->client($dpop);
        $request = $client->begin($server, $identity['did'], $identity['pds_url'], $identity['handle']);
        $payload = [
            'verifier' => $request->codeVerifier, 'did' => $request->did,
            'pds' => $request->pdsUrl, 'authorization_url' => $request->authorizationUrl,
            'nonce' => $request->dpopNonce, 'issuer' => $server->issuer,
            'authorization_endpoint' => $server->authorizationEndpoint,
            'token_endpoint' => $server->tokenEndpoint, 'par_endpoint' => $server->parEndpoint,
            'revocation_endpoint' => $server->revocationEndpoint, 'handle' => $identity['handle'], 'pem' => $pem,
        ];
        $now = $this->clock->now();
        $this->db->execute('DELETE FROM atproto_oauth_flow WHERE expires_at <= :now', ['now' => Time::format($now)]);
        $this->db->execute(
            'INSERT INTO atproto_oauth_flow (state_hash, account_id, browser_session_hash, payload, created_at, expires_at) VALUES (:state, :account, :session, :payload, :created, :expires)',
            ['state' => hash('sha256', $request->state), 'account' => $userId, 'session' => $browserSessionHash,
                'payload' => $this->box->seal(json_encode($payload, JSON_THROW_ON_ERROR)),
                'created' => Time::format($now), 'expires' => Time::format($now->modify('+10 minutes'))],
        );
        return $request->authorizationUrl;
    }

    public function complete(string $userId, string $browserSessionHash, string $state, string $code, string $issuer): void
    {
        if ($state === '' || $code === '') throw new BlueskyException(400, 'bluesky_oauth_callback_invalid', 'The Bluesky authorization response is incomplete.');
        $hash = hash('sha256', $state);
        $flow = $this->db->transaction(function () use ($hash, $userId, $browserSessionHash): ?array {
            $row = $this->db->fetchOne('SELECT * FROM atproto_oauth_flow WHERE state_hash = :state', ['state' => $hash]);
            if ($row === null || $row['account_id'] !== $userId || !hash_equals((string) $row['browser_session_hash'], $browserSessionHash)
                || (string) $row['expires_at'] <= Time::format($this->clock->now())) return null;
            $this->db->execute('DELETE FROM atproto_oauth_flow WHERE state_hash = :state', ['state' => $hash]);
            return $row;
        });
        if ($flow === null) throw new BlueskyException(400, 'bluesky_oauth_state_invalid', 'This Bluesky authorization request expired or does not match your session.');
        try { $data = json_decode($this->box->open((string) $flow['payload']), true, 32, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new BlueskyException(400, 'bluesky_oauth_flow_invalid', 'The Bluesky authorization request could not be verified.'); }
        $server = new AtprotoOAuthServer($data['issuer'], $data['authorization_endpoint'], $data['token_endpoint'], $data['par_endpoint'], $data['revocation_endpoint']);
        $request = new AtprotoOAuthAuthorizationRequest($state, $data['verifier'], $data['did'], $data['pds'], $server, $data['authorization_url'], $data['nonce']);
        $dpop = new Es256DpopProofProvider($data['pem'], $this->clock);
        $session = $this->client($dpop)->complete($request, $code, $issuer);
        if ($session->did !== $data['did']) throw new BlueskyException(400, 'bluesky_identity_mismatch', 'The authorized account does not match the requested handle.');
        $this->accounts->storeOAuth($userId, $data['handle'], $data['pds'], $data['issuer'], $session, $data['pem']);
    }

    private function client(Es256DpopProofProvider $dpop): XrpcAtprotoOAuthClient
    {
        return new XrpcAtprotoOAuthClient($this->http, $dpop, $this->clock, $this->clientId, $this->redirectUri);
    }

    private function boundedHttp(): BoundedHttpClient
    {
        if (!$this->http instanceof BoundedHttpClient) throw new RuntimeException('AT Protocol OAuth requires bounded HTTP requests.');
        return $this->http;
    }
}
