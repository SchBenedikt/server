<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Auth;

use AlohaSocial\Core\Platform\Db\Connection;

/** Hash-only persistence for Mastodon OAuth secrets and bearer credentials. */
final class MastodonOAuthRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @param list<string> $redirectUris @param list<string> $scopes */
    public function createApp(
        string $clientId,
        string $secretHash,
        string $name,
        array $redirectUris,
        array $scopes,
        ?string $website,
        string $createdAt,
    ): void {
        $this->db->execute(
            'INSERT INTO mastodon_oauth_app (client_id, client_secret_hash, name, redirect_uris, scopes, website, created_at)'
            . ' VALUES (:client_id, :secret_hash, :name, :redirect_uris, :scopes, :website, :created_at)',
            [
                'client_id' => $clientId,
                'secret_hash' => $secretHash,
                'name' => $name,
                'redirect_uris' => json_encode($redirectUris, JSON_THROW_ON_ERROR),
                'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
                'website' => $website,
                'created_at' => $createdAt,
            ],
        );
    }

    /** @return array{client_id:string,client_secret_hash:string,name:string,redirect_uris:list<string>,scopes:list<string>,website:?string}|null */
    public function app(string $clientId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT client_id, client_secret_hash, name, redirect_uris, scopes, website'
            . ' FROM mastodon_oauth_app WHERE client_id = :client_id',
            ['client_id' => $clientId],
        );
        if ($row === null) {
            return null;
        }

        $redirectUris = json_decode((string) $row['redirect_uris'], true);
        $scopes = json_decode((string) $row['scopes'], true);
        if (!is_array($redirectUris) || !is_array($scopes)) {
            return null;
        }

        return [
            'client_id' => (string) $row['client_id'],
            'client_secret_hash' => (string) $row['client_secret_hash'],
            'name' => (string) $row['name'],
            'redirect_uris' => array_values(array_filter($redirectUris, 'is_string')),
            'scopes' => array_values(array_filter($scopes, 'is_string')),
            'website' => is_string($row['website']) ? $row['website'] : null,
        ];
    }

    /** @param list<string> $scopes */
    public function createCode(
        string $codeHash,
        string $clientId,
        string $accountId,
        string $redirectUri,
        array $scopes,
        string $challenge,
        string $createdAt,
        string $expiresAt,
    ): void {
        $this->db->execute(
            'INSERT INTO mastodon_oauth_code'
            . ' (code_hash, client_id, account_id, redirect_uri, scopes, code_challenge, created_at, expires_at, consumed_at)'
            . ' VALUES (:code_hash, :client_id, :account_id, :redirect_uri, :scopes, :challenge, :created_at, :expires_at, NULL)',
            [
                'code_hash' => $codeHash,
                'client_id' => $clientId,
                'account_id' => $accountId,
                'redirect_uri' => $redirectUri,
                'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
                'challenge' => $challenge,
                'created_at' => $createdAt,
                'expires_at' => $expiresAt,
            ],
        );
    }

    /** Atomically consumes a valid, unexpired authorization code. @return array{client_id:string,account_id:string,redirect_uri:string,scopes:list<string>,code_challenge:string}|null */
    public function consumeCode(string $codeHash, string $clientId, string $now): ?array
    {
        return $this->db->transaction(function () use ($codeHash, $clientId, $now): ?array {
            $row = $this->db->fetchOne(
                'SELECT client_id, account_id, redirect_uri, scopes, code_challenge'
                . ' FROM mastodon_oauth_code'
                . ' WHERE code_hash = :hash AND client_id = :client_id AND consumed_at IS NULL AND expires_at > :valid_now',
                ['hash' => $codeHash, 'client_id' => $clientId, 'valid_now' => $now],
            );
            if ($row === null) {
                return null;
            }
            $scopes = json_decode((string) $row['scopes'], true);
            if (!is_array($scopes)) {
                return null;
            }
            if ($this->db->execute(
                'UPDATE mastodon_oauth_code SET consumed_at = :consumed_at'
                . ' WHERE code_hash = :hash AND client_id = :client_id AND consumed_at IS NULL AND expires_at > :valid_now',
                ['consumed_at' => $now, 'valid_now' => $now, 'hash' => $codeHash, 'client_id' => $clientId],
            ) !== 1) {
                return null;
            }

            return [
                'client_id' => (string) $row['client_id'],
                'account_id' => (string) $row['account_id'],
                'redirect_uri' => (string) $row['redirect_uri'],
                'scopes' => array_values(array_filter($scopes, 'is_string')),
                'code_challenge' => (string) $row['code_challenge'],
            ];
        });
    }

    /** @return array{client_id:string,account_id:string,redirect_uri:string,scopes:list<string>,code_challenge:string}|null */
    public function code(string $codeHash, string $clientId, string $now): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT client_id, account_id, redirect_uri, scopes, code_challenge'
            . ' FROM mastodon_oauth_code'
            . ' WHERE code_hash = :hash AND client_id = :client_id AND consumed_at IS NULL AND expires_at > :valid_now',
            ['hash' => $codeHash, 'client_id' => $clientId, 'valid_now' => $now],
        );
        if ($row === null) {
            return null;
        }
        $scopes = json_decode((string) $row['scopes'], true);
        if (!is_array($scopes)) {
            return null;
        }

        return [
            'client_id' => (string) $row['client_id'],
            'account_id' => (string) $row['account_id'],
            'redirect_uri' => (string) $row['redirect_uri'],
            'scopes' => array_values(array_filter($scopes, 'is_string')),
            'code_challenge' => (string) $row['code_challenge'],
        ];
    }

    /** @param list<string> $scopes */
    public function createToken(
        string $accessHash,
        string $refreshHash,
        string $clientId,
        string $accountId,
        array $scopes,
        string $createdAt,
        string $expiresAt,
        string $refreshExpiresAt,
    ): void {
        $this->db->execute(
            'INSERT INTO mastodon_oauth_token'
            . ' (access_hash, refresh_hash, client_id, account_id, scopes, created_at, expires_at, refresh_expires_at, revoked_at)'
            . ' VALUES (:access_hash, :refresh_hash, :client_id, :account_id, :scopes, :created_at, :expires_at, :refresh_expires_at, NULL)',
            [
                'access_hash' => $accessHash,
                'refresh_hash' => $refreshHash,
                'client_id' => $clientId,
                'account_id' => $accountId,
                'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
                'created_at' => $createdAt,
                'expires_at' => $expiresAt,
                'refresh_expires_at' => $refreshExpiresAt,
            ],
        );
    }

    /** @return array{account_id:string,scopes:list<string>}|null */
    public function accountForAccess(string $accessHash, string $now): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT account_id, scopes FROM mastodon_oauth_token'
            . ' WHERE access_hash = :hash AND revoked_at IS NULL AND expires_at > :access_now AND refresh_expires_at > :refresh_now',
            ['hash' => $accessHash, 'access_now' => $now, 'refresh_now' => $now],
        );
        if ($row === null) {
            return null;
        }
        $scopes = json_decode((string) $row['scopes'], true);
        if (!is_array($scopes)) {
            return null;
        }

        return ['account_id' => (string) $row['account_id'], 'scopes' => array_values(array_filter($scopes, 'is_string'))];
    }

    /** Atomically consumes a refresh token so it cannot be replayed. @return array{client_id:string,account_id:string,scopes:list<string>}|null */
    public function consumeRefresh(string $refreshHash, string $clientId, string $now): ?array
    {
        return $this->db->transaction(function () use ($refreshHash, $clientId, $now): ?array {
            $row = $this->db->fetchOne(
                'SELECT client_id, account_id, scopes FROM mastodon_oauth_token'
                . ' WHERE refresh_hash = :hash AND client_id = :client_id AND revoked_at IS NULL AND refresh_expires_at > :valid_now',
                ['hash' => $refreshHash, 'client_id' => $clientId, 'valid_now' => $now],
            );
            if ($row === null) {
                return null;
            }
            $scopes = json_decode((string) $row['scopes'], true);
            if (!is_array($scopes)) {
                return null;
            }
            if ($this->db->execute(
                'UPDATE mastodon_oauth_token SET revoked_at = :revoked_at'
                . ' WHERE refresh_hash = :hash AND client_id = :client_id AND revoked_at IS NULL AND refresh_expires_at > :valid_now',
                ['revoked_at' => $now, 'valid_now' => $now, 'hash' => $refreshHash, 'client_id' => $clientId],
            ) !== 1) {
                return null;
            }

            return [
                'client_id' => (string) $row['client_id'],
                'account_id' => (string) $row['account_id'],
                'scopes' => array_values(array_filter($scopes, 'is_string')),
            ];
        });
    }

    public function revoke(string $tokenHash, string $clientId, string $now): void
    {
        $this->db->execute(
            'UPDATE mastodon_oauth_token SET revoked_at = :now'
            . ' WHERE (access_hash = :access_hash OR refresh_hash = :refresh_hash) AND client_id = :client_id AND revoked_at IS NULL',
            ['access_hash' => $tokenHash, 'refresh_hash' => $tokenHash, 'client_id' => $clientId, 'now' => $now],
        );
    }

    public function revokeAccount(string $accountId, string $now): int
    {
        $revoked = $this->db->execute(
            'UPDATE mastodon_oauth_token SET revoked_at = :now WHERE account_id = :account_id AND revoked_at IS NULL',
            ['account_id' => $accountId, 'now' => $now],
        );
        return $revoked + $this->db->execute(
            'UPDATE mastodon_oauth_code SET consumed_at = :now WHERE account_id = :account_id AND consumed_at IS NULL',
            ['account_id' => $accountId, 'now' => $now],
        );
    }

    public function deleteExpired(string $now): int
    {
        $this->db->execute('DELETE FROM mastodon_oauth_code WHERE expires_at <= :now OR consumed_at IS NOT NULL', ['now' => $now]);
        return $this->db->execute('DELETE FROM mastodon_oauth_token WHERE refresh_expires_at <= :now OR revoked_at IS NOT NULL', ['now' => $now]);
    }
}
