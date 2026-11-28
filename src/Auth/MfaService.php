<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Auth;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Security\SecretBox;
use AlohaSocial\Core\Security\SecretBoxException;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Server\Accounts\AccountRepository;
use AlohaSocial\Server\Config\HostConfig;
use RuntimeException;

use function bin2hex;
use function hash;
use function hash_equals;
use function is_array;
use function is_string;
use function random_bytes;
use function random_int;
use function str_replace;
use function strlen;
use function strtolower;
use function substr;
use function trim;

/**
 * TOTP enrollment, login challenges and recovery codes.
 *
 * The base32 secret is SecretBox-sealed at rest; only its fingerprint of
 * knowledge matters — a database leak without the instance key reveals
 * nothing usable. Recovery codes and pending-login tokens are stored as
 * SHA-256 hashes and are single-use.
 */
final class MfaService
{
    private const PENDING_TTL_SECONDS = 300;

    private const PENDING_MAX_ATTEMPTS = 5;

    private const RECOVERY_CODE_COUNT = 8;

    public function __construct(
        private readonly Connection $db,
        private readonly AccountRepository $accounts,
        private readonly SecretBox $box,
        private readonly HostConfig $config,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Starts (or restarts) enrollment: stores a fresh sealed secret with
     * `totp_enabled_at` still NULL until `confirm()` accepts a code.
     *
     * Refuses while a factor is already enabled — re-enrollment goes through
     * `disable()` first, which requires a live code, so a stolen session
     * cannot silently strip the second factor.
     *
     * @return array{secret: string, otpauth_uri: string}|null null when enabled
     */
    public function begin(string $accountId): ?array
    {
        if ($this->isEnabled($accountId)) {
            return null;
        }

        $secret = Totp::generateSecret();
        $this->db->execute(
            'UPDATE account SET totp_secret = :secret, totp_enabled_at = NULL WHERE id = :id',
            ['secret' => $this->box->seal($secret), 'id' => $accountId],
        );

        $account = $this->accounts->find($accountId);
        $handle = $account?->handle ?? $accountId;

        return [
            'secret' => $secret,
            'otpauth_uri' => Totp::provisioningUri($secret, $handle, $this->config->instanceName),
        ];
    }

    /**
     * @return array{recovery_codes: list<string>}|null null when the code is wrong
     * @throws RuntimeException when no enrollment is pending
     */
    public function confirm(string $accountId, string $code): ?array
    {
        $secret = $this->pendingSecret($accountId);
        if ($secret === null) {
            throw new RuntimeException('No two-factor enrollment is in progress.');
        }
        if (!Totp::verify($secret, $code)) {
            return null;
        }

        $now = Time::format($this->clock->now());
        $this->db->execute(
            'UPDATE account SET totp_enabled_at = :now WHERE id = :id',
            ['now' => $now, 'id' => $accountId],
        );
        $this->db->execute('DELETE FROM mfa_recovery_code WHERE account_id = :id', ['id' => $accountId]);

        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $code = $this->newRecoveryCode();
            $codes[] = $code;
            $this->db->execute(
                'INSERT INTO mfa_recovery_code (account_id, code_hash, created_at) VALUES (:account, :hash, :now)',
                ['account' => $accountId, 'hash' => $this->hashRecoveryCode($code), 'now' => $now],
            );
        }

        return ['recovery_codes' => $codes];
    }

    public function isEnabled(string $accountId): bool
    {
        $row = $this->db->fetchOne(
            'SELECT totp_enabled_at FROM account WHERE id = :id',
            ['id' => $accountId],
        );

        return is_array($row) && is_string($row['totp_enabled_at'] ?? null) && $row['totp_enabled_at'] !== '';
    }

    public function status(string $accountId): array
    {
        return [
            'enabled' => $this->isEnabled($accountId),
            'pending' => $this->pendingSecret($accountId) !== null,
        ];
    }

    /**
     * Disables the second factor. Requires a currently valid TOTP code so a
     * stolen session alone cannot strip the protection.
     */
    public function disable(string $accountId, string $code): bool
    {
        $secret = $this->secret($accountId);
        if ($secret === null || !Totp::verify($secret, $code)) {
            return false;
        }

        $this->db->execute(
            'UPDATE account SET totp_secret = NULL, totp_enabled_at = NULL WHERE id = :id',
            ['id' => $accountId],
        );
        $this->db->execute('DELETE FROM mfa_recovery_code WHERE account_id = :id', ['id' => $accountId]);

        return true;
    }

    /**
     * Creates a short-lived login challenge after the password verified.
     * Returns the raw token for the form; only its hash is stored.
     */
    public function beginLogin(string $accountId, string $returnTo): string
    {
        $this->db->execute('DELETE FROM mfa_pending WHERE expires_at <= :now', [
            'now' => Time::format($this->clock->now()),
        ]);
        // A fresh password verification supersedes outstanding challenges so
        // attempts cannot be multiplied by minting new tokens.
        $this->db->execute('DELETE FROM mfa_pending WHERE account_id = :account', [
            'account' => $accountId,
        ]);

        $token = bin2hex(random_bytes(32));
        $now = $this->clock->now();
        $this->db->execute(
            'INSERT INTO mfa_pending (token_hash, account_id, return_to, attempts, created_at, expires_at)'
            . ' VALUES (:hash, :account, :return_to, 0, :created, :expires)',
            [
                'hash' => hash('sha256', $token),
                'account' => $accountId,
                'return_to' => substr($returnTo, 0, 255),
                'created' => Time::format($now),
                'expires' => Time::format($now->modify('+' . self::PENDING_TTL_SECONDS . ' seconds')),
            ],
        );

        return $token;
    }

    /**
     * The post-password destination: verifies the TOTP or a recovery code
     * against the pending challenge.
     *
     * @return array{account_id: string, return_to: string}|null null on failure
     */
    public function completeLogin(string $token, string $code): ?array
    {
        $hash = hash('sha256', $token);
        $row = $this->db->fetchOne('SELECT * FROM mfa_pending WHERE token_hash = :hash', ['hash' => $hash]);
        if (!is_array($row)) {
            return null;
        }

        $now = Time::format($this->clock->now());
        if ((string) $row['expires_at'] <= $now) {
            $this->db->execute('DELETE FROM mfa_pending WHERE token_hash = :hash', ['hash' => $hash]);

            return null;
        }

        $accountId = (string) $row['account_id'];
        $attempts = (int) $row['attempts'] + 1;
        $secret = $this->secret($accountId);
        $ok = $secret !== null && Totp::verify($secret, $code);

        if (!$ok) {
            $ok = $this->consumeRecoveryCode($accountId, $code);
        }

        if (!$ok) {
            if ($attempts >= self::PENDING_MAX_ATTEMPTS) {
                $this->db->execute('DELETE FROM mfa_pending WHERE token_hash = :hash', ['hash' => $hash]);
            } else {
                $this->db->execute(
                    'UPDATE mfa_pending SET attempts = :attempts WHERE token_hash = :hash',
                    ['attempts' => $attempts, 'hash' => $hash],
                );
            }

            return null;
        }

        $this->db->execute('DELETE FROM mfa_pending WHERE token_hash = :hash', ['hash' => $hash]);

        return [
            'account_id' => $accountId,
            'return_to' => (string) ($row['return_to'] ?? ''),
        ];
    }

    /**
     * Sealed TOTP secret for an enabled account, or the pending enrollment
     * secret (enabled_at still NULL).
     */
    private function secret(string $accountId): ?string
    {
        $row = $this->db->fetchOne(
            'SELECT totp_secret, totp_enabled_at FROM account WHERE id = :id',
            ['id' => $accountId],
        );
        if (!is_array($row) || !is_string($row['totp_secret'] ?? null) || $row['totp_secret'] === '') {
            return null;
        }

        try {
            return $this->box->open($row['totp_secret']);
        } catch (SecretBoxException) {
            return null;
        }
    }

    /**
     * The enrollment secret while confirmation is still outstanding.
     */
    private function pendingSecret(string $accountId): ?string
    {
        $row = $this->db->fetchOne(
            'SELECT totp_secret, totp_enabled_at FROM account WHERE id = :id',
            ['id' => $accountId],
        );
        if (!is_array($row)
            || !is_string($row['totp_secret'] ?? null)
            || $row['totp_secret'] === ''
            || ($row['totp_enabled_at'] ?? null) !== null
        ) {
            return null;
        }

        try {
            return $this->box->open($row['totp_secret']);
        } catch (SecretBoxException) {
            return null;
        }
    }

    private function consumeRecoveryCode(string $accountId, string $code): bool
    {
        $normalized = strtolower(str_replace([' ', '-'], '', trim($code)));
        if ($normalized === '') {
            return false;
        }
        $hash = $this->hashRecoveryCode($normalized);

        $rows = $this->db->fetchAll(
            'SELECT id, code_hash FROM mfa_recovery_code WHERE account_id = :account AND used_at IS NULL',
            ['account' => $accountId],
        );
        foreach ($rows as $row) {
            if (is_string($row['code_hash'] ?? null) && hash_equals($row['code_hash'], $hash)) {
                $this->db->execute(
                    'UPDATE mfa_recovery_code SET used_at = :now WHERE id = :id',
                    ['now' => Time::format($this->clock->now()), 'id' => $row['id']],
                );

                return true;
            }
        }

        return false;
    }

    private function hashRecoveryCode(string $code): string
    {
        return hash('sha256', strtolower(str_replace([' ', '-'], '', $code)));
    }

    private function newRecoveryCode(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return substr($code, 0, 4) . '-' . substr($code, 4);
    }
}
