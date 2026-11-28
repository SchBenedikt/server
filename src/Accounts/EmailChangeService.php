<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Accounts;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Server\Auth\PasswordHasher;
use AlohaSocial\Server\Mail\MailOutbox;

use function base64_encode;
use function filter_var;
use function hash;
use function is_string;
use function random_bytes;
use function rtrim;
use function strtr;
use function strlen;
use function strtolower;
use function trim;

use const FILTER_VALIDATE_EMAIL;

/**
 * Verified email-address changes for signed-in members.
 *
 * The candidate address is reserved in `account.pending_email` and proven by
 * a single-use link mailed to it; `account.email` — the address password
 * recovery and federation notices use — only switches when the link is
 * consumed. Both the confirm mail (to the new address) and an alert mail (to
 * the old one) go through the durable outbox. Requesting a change requires
 * the account password, so a stolen session alone cannot move the address.
 */
final class EmailChangeService
{
    public const TTL_SECONDS = 86400;

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
     * Reserve a new address and queue the confirmation mails.
     *
     * Returns null on success or a stable error key for the caller to map:
     * `invalid_password`, `invalid_email`, `email_unchanged`, `email_taken`.
     * Runs inside one transaction so the reservation, token and mails commit
     * or roll back together; a repeat request supersedes the earlier one.
     */
    public function request(string $accountId, string $newEmail, string $password): ?string
    {
        $account = $this->accounts->find($accountId);
        if ($account === null || $account->isDisabled()) {
            return 'invalid_password';
        }
        if (!$this->hasher->verify($password, $account->passwordHash)) {
            return 'invalid_password';
        }

        $newEmail = strtolower(trim($newEmail));
        if (filter_var($newEmail, FILTER_VALIDATE_EMAIL) === false || strlen($newEmail) > 254) {
            return 'invalid_email';
        }
        if ($newEmail === strtolower($account->email)) {
            return 'email_unchanged';
        }

        $owner = $this->accounts->findByEmail($newEmail);
        if ($owner !== null && $owner->id !== $account->id) {
            return 'email_taken';
        }

        $this->db->transaction(function () use ($account, $newEmail): void {
            // Write-first: the pending_email UPDATE locks the account row for
            // the rest of the transaction, so two racing requests serialize
            // and the later one cleanly supersedes the earlier.
            $this->db->execute(
                'UPDATE account SET pending_email = :email WHERE id = :id',
                ['email' => $newEmail, 'id' => $account->id],
            );

            $this->db->execute(
                'DELETE FROM email_change WHERE account_id = :account',
                ['account' => $account->id],
            );

            $token = self::newToken();
            $now = $this->clock->now();
            $this->db->execute(
                'INSERT INTO email_change (token_hash, account_id, new_email, created_at, expires_at, used_at)'
                . ' VALUES (:hash, :account, :email, :created, :expires, NULL)',
                [
                    'hash' => self::hashToken($token),
                    'account' => $account->id,
                    'email' => $newEmail,
                    'created' => Time::format($now),
                    'expires' => Time::format($now->modify('+' . self::TTL_SECONDS . ' seconds')),
                ],
            );

            $this->outbox->cancelPending(MailOutbox::KIND_EMAIL_CHANGE, $account->id);
            $this->outbox->cancelPending(MailOutbox::KIND_EMAIL_CHANGE_NOTICE, $account->id);

            $link = rtrim($this->instanceUrl, '/') . '/verify-email-change?token=' . rawurlencode($token);
            $name = $account->name();

            $this->outbox->enqueue(
                MailOutbox::KIND_EMAIL_CHANGE,
                $account->id,
                self::hashToken($token),
                $newEmail,
                'Confirm your new email for ' . $this->instanceUrl,
                "Hello " . $name . ",\n\n"
                . "a change of the email address for @" . $account->handle . " on " . $this->instanceUrl . " was requested.\n"
                . "Confirm the new address by opening this link:\n\n"
                . $link . "\n\n"
                . "The link is valid for 24 hours and can only be used once.\n"
                . "Your current address keeps working until the link is used.\n"
                . "If you did not request this, ignore this email and consider changing your password.\n",
            );

            $this->outbox->enqueue(
                MailOutbox::KIND_EMAIL_CHANGE_NOTICE,
                $account->id,
                self::hashToken($token) . ':notice',
                $account->email,
                'Email change requested for ' . $this->instanceUrl,
                "Hello " . $name . ",\n\n"
                . "a change of the email address for @" . $account->handle . " on " . $this->instanceUrl . " was requested.\n"
                . "The new address must confirm the change before it takes effect.\n"
                . "If you did not request this, someone may know your password: change it now and contact the administrator.\n",
            );
        });

        return null;
    }

    /**
     * Consume a confirmation token and switch the address atomically.
     *
     * The switch only happens while `pending_email` still matches the
     * address the token was issued for; a newer request or a cleared
     * reservation makes the older link stale. Outstanding password-reset
     * links are deleted so recovery mail queued for the old address cannot
     * outlive the change.
     */
    public function confirm(string $token): bool
    {
        if ($token === '' || strlen($token) > 200) {
            return false;
        }

        $hash = self::hashToken($token);
        $now = Time::format($this->clock->now());

        try {
            return $this->db->transaction(function () use ($hash, $now): bool {
                $row = $this->db->fetchOne(
                    'SELECT account_id, new_email, expires_at, used_at FROM email_change WHERE token_hash = :hash',
                    ['hash' => $hash],
                );
                if ($row === null || $row['used_at'] !== null
                    || Time::parse((string) $row['expires_at'])->getTimestamp() <= $this->clock->now()->getTimestamp()) {
                    return false;
                }

                $accountId = (string) $row['account_id'];
                $newEmail = (string) $row['new_email'];

                $account = $this->db->fetchOne(
                    'SELECT pending_email, disabled_at FROM account WHERE id = :id',
                    ['id' => $accountId],
                );
                if ($account === null || $account['disabled_at'] !== null
                    || $account['pending_email'] !== $newEmail) {
                    return false;
                }

                // Re-check uniqueness inside the transaction: another account
                // may have registered the address while the link was pending.
                $owner = $this->accounts->findByEmail($newEmail);
                if ($owner !== null && $owner->id !== $accountId) {
                    return false;
                }

                $claimed = $this->db->execute(
                    'UPDATE email_change SET used_at = :now'
                    . ' WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now',
                    ['now' => $now, 'hash' => $hash],
                );
                if ($claimed !== 1) {
                    return false;
                }

                $switched = $this->db->execute(
                    'UPDATE account SET email = :email, pending_email = NULL, email_verified_at = :now'
                    . ' WHERE id = :id AND pending_email = :pending',
                    ['email' => $newEmail, 'now' => $now, 'id' => $accountId, 'pending' => $newEmail],
                );
                if ($switched !== 1) {
                    throw new \RuntimeException('pending email changed during confirmation');
                }

                $this->db->execute(
                    'DELETE FROM email_change WHERE account_id = :account',
                    ['account' => $accountId],
                );
                $this->db->execute(
                    'DELETE FROM password_reset WHERE account_id = :account',
                    ['account' => $accountId],
                );

                return true;
            });
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * The account's outstanding change, if any: the reserved address and
     * when its confirmation link expires. Read-only; used by the settings
     * surface so a member can see and cancel a pending request.
     *
     * @return array{email: string, expires_at: string}|null
     */
    public function pendingFor(string $accountId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT a.pending_email AS pending_email, e.expires_at AS expires_at'
            . ' FROM account a LEFT JOIN email_change e'
            . '   ON e.account_id = a.id AND e.used_at IS NULL'
            . ' WHERE a.id = :id',
            ['id' => $accountId],
        );
        if ($row === null || !is_string($row['pending_email']) || $row['pending_email'] === '') {
            return null;
        }

        return [
            'email' => $row['pending_email'],
            'expires_at' => is_string($row['expires_at']) ? $row['expires_at'] : '',
        ];
    }

    /**
     * Drop every pending change for an account: tokens, the reserved
     * address and queued mails. Called on credential reset and suspension
     * so a stale candidate can never complete later. Plain statements — the
     * caller decides whether a transaction wraps them.
     */
    public function cancelForAccount(string $accountId): void
    {
        $this->db->execute(
            'DELETE FROM email_change WHERE account_id = :account',
            ['account' => $accountId],
        );
        $this->db->execute(
            'UPDATE account SET pending_email = NULL WHERE id = :id',
            ['id' => $accountId],
        );
        $this->outbox->cancelPending(MailOutbox::KIND_EMAIL_CHANGE, $accountId);
        $this->outbox->cancelPending(MailOutbox::KIND_EMAIL_CHANGE_NOTICE, $accountId);
    }

    /** Delete expired change tokens. */
    public function prune(): int
    {
        return $this->db->execute(
            'DELETE FROM email_change WHERE expires_at < :now',
            ['now' => Time::format($this->clock->now())],
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
