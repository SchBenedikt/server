<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Auth;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Server\Accounts\Account;
use AlohaSocial\Server\Accounts\AccountRepository;
use AlohaSocial\Server\Mail\MailOutbox;

use function base64_encode;
use function bin2hex;
use function hash;
use function json_encode;
use function random_bytes;
use function rtrim;
use function strlen;
use function strtolower;
use function strtr;
use function substr;
use function trim;

/**
 * Emailed, single-use password reset links.
 *
 * Accounts are host-owned (ADR 0006), so the reset flow lives here, not in
 * the core. Only the hash of a token is stored; the plain token exists only in
 * the mail. A completed reset revokes every session of the account, so a
 * stolen session cannot survive a password change.
 *
 * Issuance and consumption both lock the account row first. That serializes
 * two submissions of the same link, a request racing a reset, and the unique
 * per-account token row. The claim itself is a conditional UPDATE, so even a
 * database without usable row locks cannot let two requests both consume it.
 */
final class PasswordResetService
{
    public const TTL_SECONDS = 3600;

    public const MIN_PASSWORD_LENGTH = PasswordPolicy::MIN_LENGTH;

    public const MAX_PASSWORD_LENGTH = PasswordPolicy::MAX_LENGTH;

    public function __construct(
        private readonly Connection $db,
        private readonly AccountRepository $accounts,
        private readonly PasswordHasher $hasher,
        private readonly MailOutbox $outbox,
        private readonly Clock $clock,
        private readonly string $instanceUrl,
    ) {
    }

    /**
     * Queues a reset mail when the identifier belongs to an enabled account.
     *
     * The caller shows the same answer either way, so this return value is for
     * tests and logs, never for the response body.
     */
    public function request(string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return false;
        }

        $account = $this->accounts->findByHandle($identifier)
            ?? $this->accounts->findByEmail($identifier);

        if ($account === null || $account->isDisabled()) {
            return false;
        }

        $token = $this->newToken();
        $now = $this->clock->now();
        $expires = $now->modify('+' . self::TTL_SECONDS . ' seconds');

        $link = rtrim($this->instanceUrl, '/') . '/reset-password?token=' . rawurlencode($token);
        $name = $account->name();
        $body = "Hello " . $name . ",\n\n"
            . "for your account @" . $account->handle . " a new password was requested.\n"
            . "Open this link to set it:\n\n"
            . $link . "\n\n"
            . "The link is valid for one hour and can only be used once.\n"
            . "If you did not request this, ignore this email; your password stays unchanged.\n";

        // The lock makes two simultaneous requests for one account serialize:
        // the later request replaces the earlier token instead of leaving two
        // live links behind. The mail is queued in the same transaction, so
        // an SMTP outage delays delivery instead of losing the reset or
        // failing the request; the superseded link is cancelled before the
        // new one is queued, so an obsolete token is never mailed.
        $current = $this->db->transaction(function () use ($account, $token, $now, $expires, $body): ?Account {
            $this->lockAccount($account->id);

            $current = $this->accounts->find($account->id);
            if ($current === null || $current->isDisabled()) {
                return null;
            }

            $this->db->execute('DELETE FROM password_reset WHERE account_id = :account', ['account' => $current->id]);
            $this->db->execute(
                'INSERT INTO password_reset (token_hash, account_id, created_at, expires_at, used_at, credential_version)'
                . ' VALUES (:hash, :account, :created, :expires, NULL, :credential_version)',
                [
                    'hash' => self::hashToken($token),
                    'account' => $current->id,
                    'created' => Time::format($now),
                    'expires' => Time::format($expires),
                    'credential_version' => $current->credentialVersion,
                ],
            );

            $this->outbox->cancelPending(MailOutbox::KIND_PASSWORD_RESET, $current->id);
            $this->outbox->enqueue(
                MailOutbox::KIND_PASSWORD_RESET,
                $current->id,
                self::hashToken($token),
                $current->email,
                'Password for ' . $this->instanceUrl . ' reset',
                $body,
            );

            return $current;
        });

        if ($current === null) {
            // The account was disabled or removed while the request waited.
            return false;
        }

        return true;
    }

    /**
     * Delete token rows that can no longer be used. Queued mail for an
     * expired token is cancelled by the outbox dispatcher instead.
     */
    public function prune(): int
    {
        return $this->db->execute(
            'DELETE FROM password_reset WHERE expires_at < :now',
            ['now' => Time::format($this->clock->now())],
        );
    }

    public function isValid(string $token): bool
    {
        $row = $this->find($token);

        return $row !== null
            && $row['used_at'] === null
            && ($row['account_credential_version'] ?? null) !== null
            && $row['disabled_at'] === null
            && (int) $row['reset_credential_version'] === (int) $row['account_credential_version']
            && Time::parse((string) $row['expires_at'])->getTimestamp() > $this->clock->now()->getTimestamp();
    }

    /**
     * @throws PasswordResetException
     */
    public function reset(string $token, string $password): void
    {
        $passwordError = PasswordPolicy::error($password);
        if ($passwordError !== null) {
            throw new PasswordResetException('weak_password', $passwordError);
        }

        if ($token === '' || strlen($token) > 200) {
            throw new PasswordResetException(
                'invalid_token',
                'The link is invalid or has already been used.',
            );
        }

        $hash = $this->hasher->hash($password);
        $now = $this->clock->now();
        $nowValue = Time::format($now);
        $tokenHash = self::hashToken($token);

        $this->db->transaction(function () use ($hash, $now, $nowValue, $tokenHash): void {
            // First statement in the transaction: lock the account through
            // the token row before reading anything. On SQLite an earlier read
            // would create a snapshot that cannot be upgraded under a racing
            // writer; a write-first transaction waits cleanly instead.
            $this->lockAccountForToken($tokenHash);

            $row = $this->findByHash($tokenHash);
            if ($row === null) {
                throw new PasswordResetException(
                    'invalid_token',
                    'The link is invalid or has already been used.',
                );
            }

            $account = $this->accounts->find((string) $row['account_id']);
            if ($account === null || $account->isDisabled()) {
                throw new PasswordResetException('invalid_token', 'The link is invalid or has already been used.');
            }

            if ($row['used_at'] !== null) {
                throw new PasswordResetException(
                    'invalid_token',
                    'The link is invalid or has already been used.',
                );
            }

            if ((int) $row['reset_credential_version'] !== $account->credentialVersion) {
                // A password changed through the admin console or CLI after
                // this link was issued. The stale link must not overwrite the
                // new credential.
                throw new PasswordResetException(
                    'invalid_token',
                    'The link is invalid or has already been used.',
                );
            }

            $expiresAt = Time::parse((string) $row['expires_at'])->getTimestamp();
            if ($expiresAt <= $now->getTimestamp()) {
                throw new PasswordResetException(
                    'expired_token',
                    'The link has expired. Please request a new one.',
                );
            }

            $claimed = $this->db->execute(
                'UPDATE password_reset SET used_at = :now'
                . ' WHERE token_hash = :hash AND account_id = :account'
                . ' AND used_at IS NULL AND expires_at > :now AND credential_version = :credential_version',
                [
                    'now' => $nowValue,
                    'hash' => $tokenHash,
                    'account' => $account->id,
                    'credential_version' => $account->credentialVersion,
                ],
            );

            if ($claimed !== 1) {
                if ($expiresAt <= $now->getTimestamp()) {
                    throw new PasswordResetException(
                        'expired_token',
                        'The link has expired. Please request a new one.',
                    );
                }

                throw new PasswordResetException(
                    'invalid_token',
                    'The link is invalid or has already been used.',
                );
            }

            $this->accounts->updatePasswordHash($account->id, $hash);
            $this->db->execute('DELETE FROM session WHERE account_id = :account', ['account' => $account->id]);
            $this->db->execute('DELETE FROM atproto_session WHERE account_id = :account', ['account' => $account->id]);
            $this->db->execute(
                'UPDATE mastodon_oauth_token SET revoked_at = :now WHERE account_id = :account AND revoked_at IS NULL',
                ['account' => $account->id, 'now' => $nowValue],
            );
            $this->db->execute(
                'UPDATE mastodon_oauth_code SET consumed_at = :now WHERE account_id = :account AND consumed_at IS NULL',
                ['account' => $account->id, 'now' => $nowValue],
            );
            // Invalidate every other link only after this exact token was
            // claimed. A failed reset rolls this deletion back as well.
            $this->db->execute('DELETE FROM password_reset WHERE account_id = :account', ['account' => $account->id]);
            $this->db->execute(
                'INSERT INTO admin_audit (id, actor_id, action, target_type, target_id, details, created_at)'
                . ' VALUES (:id, :actor, :action, :target_type, :target_id, :details, :created_at)',
                [
                    'id' => bin2hex(random_bytes(16)),
                    'actor' => $account->id,
                    'action' => 'account.password_reset',
                    'target_type' => 'account',
                    'target_id' => $account->id,
                    'details' => json_encode(['method' => 'emailed_token'], JSON_THROW_ON_ERROR),
                    'created_at' => $nowValue,
                ],
            );
        });
    }

    /**
     * Serializes reset issuance and consumption for one account.
     *
     * The no-op update is deliberate: it takes the row lock on PostgreSQL and
     * the write lock on SQLite without changing the credential generation.
     */
    private function lockAccount(string $accountId): void
    {
        $this->db->execute(
            'UPDATE account SET credential_version = credential_version WHERE id = :id',
            ['id' => $accountId],
        );
    }

    /**
     * Locks the account that owns a token with the transaction's first write.
     */
    private function lockAccountForToken(string $tokenHash): void
    {
        $this->db->execute(
            'UPDATE account SET credential_version = credential_version'
            . ' WHERE id = (SELECT account_id FROM password_reset WHERE token_hash = :hash)',
            ['hash' => $tokenHash],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $token): ?array
    {
        if ($token === '' || strlen($token) > 200) {
            return null;
        }

        return $this->findByHash(self::hashToken($token));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByHash(string $tokenHash): ?array
    {
        return $this->db->fetchOne(
            'SELECT reset.token_hash, reset.account_id, reset.created_at, reset.expires_at, reset.used_at,'
            . ' reset.credential_version AS reset_credential_version,'
            . ' account.disabled_at, account.credential_version AS account_credential_version'
            . ' FROM password_reset reset'
            . ' LEFT JOIN account ON account.id = reset.account_id'
            . ' WHERE reset.token_hash = :hash',
            ['hash' => $tokenHash],
        );
    }

    private static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
