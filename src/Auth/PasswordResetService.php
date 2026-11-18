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
use AlohaSocial\Server\Accounts\AccountRepository;
use AlohaSocial\Server\Mail\Mailer;

use function base64_encode;
use function hash;
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
 */
final class PasswordResetService
{
    public const TTL_SECONDS = 3600;

    public const MIN_PASSWORD_LENGTH = 8;

    public const MAX_PASSWORD_LENGTH = 4096;

    public function __construct(
        private readonly Connection $db,
        private readonly AccountRepository $accounts,
        private readonly PasswordHasher $hasher,
        private readonly Mailer $mailer,
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

        $this->db->transaction(function () use ($account, $token, $now, $expires): void {
            $this->db->execute('DELETE FROM password_reset WHERE account_id = :account', ['account' => $account->id]);
            $this->db->execute(
                'INSERT INTO password_reset (token_hash, account_id, created_at, expires_at, used_at)'
                . ' VALUES (:hash, :account, :created, :expires, NULL)',
                [
                    'hash' => self::hashToken($token),
                    'account' => $account->id,
                    'created' => Time::format($now),
                    'expires' => Time::format($expires),
                ],
            );
        });

        $link = rtrim($this->instanceUrl, '/') . '/reset-password?token=' . rawurlencode($token);
        $name = $account->name();

        $this->mailer->send(
            $account->email,
            'Password for ' . $this->instanceUrl . ' reset',
            "Hello " . $name . ",\n\n"
            . "for your account @" . $account->handle . " a new password was requested.\n"
            . "Open this link to set it:\n\n"
            . $link . "\n\n"
            . "The link is valid for one hour and can only be used once.\n"
            . "If you did not request this, ignore this email; your password stays unchanged.\n",
        );

        return true;
    }

    public function isValid(string $token): bool
    {
        $row = $this->find($token);

        return $row !== null
            && $row['used_at'] === null
            && Time::parse((string) $row['expires_at'])->getTimestamp() > $this->clock->now()->getTimestamp();
    }

    /**
     * @throws PasswordResetException
     */
    public function reset(string $token, string $password): void
    {
        $length = strlen($password);
        if ($length < self::MIN_PASSWORD_LENGTH) {
            throw new PasswordResetException(
                'weak_password',
                'The password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.',
            );
        }

        if ($length > self::MAX_PASSWORD_LENGTH) {
            throw new PasswordResetException('weak_password', 'The password is too long.');
        }

        $row = $this->find($token);
        if ($row === null || $row['used_at'] !== null) {
            throw new PasswordResetException(
                'invalid_token',
                'The link is invalid or has already been used.',
            );
        }

        if (Time::parse((string) $row['expires_at'])->getTimestamp() <= $this->clock->now()->getTimestamp()) {
            throw new PasswordResetException(
                'expired_token',
                'The link has expired. Please request a new one.',
            );
        }

        $account = $this->accounts->find((string) $row['account_id']);
        if ($account === null || $account->isDisabled()) {
            throw new PasswordResetException('invalid_token', 'The link is invalid or has already been used.');
        }

        $hash = $this->hasher->hash($password);
        $now = $this->clock->now();

        $this->db->transaction(function () use ($account, $hash, $now): void {
            $this->accounts->updatePasswordHash($account->id, $hash);
            $this->db->execute('DELETE FROM session WHERE account_id = :account', ['account' => $account->id]);
            $this->db->execute('DELETE FROM atproto_session WHERE account_id = :account', ['account' => $account->id]);
            $this->db->execute('DELETE FROM password_reset WHERE account_id = :account', ['account' => $account->id]);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $token): ?array
    {
        if ($token === '' || strlen($token) > 200) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT token_hash, account_id, created_at, expires_at, used_at FROM password_reset WHERE token_hash = :hash',
            ['hash' => self::hashToken($token)],
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
