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
use AlohaSocial\Server\Mail\MailOutbox;

use function base64_encode;
use function hash;
use function random_bytes;
use function rtrim;
use function strtr;
use function strlen;
use function strtolower;
use function trim;

/**
 * Emailed, single-use verification links issued at registration.
 *
 * Only the token hash is stored; the plain token exists only inside the
 * queued mail. Verifying marks `account.email_verified_at`; when approval is
 * not required it also enables the account, so a registered user can sign in
 * only after proving mailbox ownership. With approval required the verified
 * flag lets an administrator distinguish "waiting on email" from "waiting on
 * review".
 */
final class EmailVerificationService
{
    public const TTL_SECONDS = 86400;

    public function __construct(
        private readonly Connection $db,
        private readonly AccountRepository $accounts,
        private readonly MailOutbox $outbox,
        private readonly Clock $clock,
        private readonly string $instanceUrl,
    ) {
    }

    /**
     * Queue a verification mail for a freshly created account. Runs inside
     * the caller's transaction so the account, token and mail commit
     * together.
     */
    public function issue(Account $account): void
    {
        $this->issueToken($account);
    }

    /**
     * Queue a fresh verification mail for an existing unverified account.
     * The identifier answer is deliberately the same for unknown, verified
     * and disabled accounts so the endpoint cannot enumerate accounts.
     */
    public function resend(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }

        $account = $this->accounts->findByHandle($identifier)
            ?? $this->accounts->findByEmail($identifier);

        if ($account === null) {
            return;
        }

        $this->db->transaction(function () use ($account): void {
            $current = $this->accounts->find($account->id);
            if ($current === null || $current->emailVerifiedAt !== null) {
                return;
            }

            $this->issueToken($current);
        });
    }

    /**
     * Consume a verification token. Returns true when the account's email is
     * now verified; with $activate the account is also enabled, which is the
     * path taken when the instance does not require admin approval.
     */
    public function verify(string $token, bool $activate): bool
    {
        if ($token === '' || strlen($token) > 200) {
            return false;
        }

        $hash = self::hashToken($token);
        $now = Time::format($this->clock->now());

        return $this->db->transaction(function () use ($hash, $now, $activate): bool {
            $row = $this->db->fetchOne(
                'SELECT account_id, expires_at, used_at FROM email_verification WHERE token_hash = :hash',
                ['hash' => $hash],
            );
            if ($row === null || $row['used_at'] !== null
                || Time::parse((string) $row['expires_at'])->getTimestamp() <= $this->clock->now()->getTimestamp()) {
                return false;
            }

            $claimed = $this->db->execute(
                'UPDATE email_verification SET used_at = :now'
                . ' WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now',
                ['now' => $now, 'hash' => $hash],
            );
            if ($claimed !== 1) {
                return false;
            }

            $accountId = (string) $row['account_id'];
            $this->db->execute(
                'UPDATE account SET email_verified_at = :now WHERE id = :id',
                ['now' => $now, 'id' => $accountId],
            );
            // Delete sibling tokens: one verified link ends all outstanding
            // verification mails for the account.
            $this->db->execute(
                'DELETE FROM email_verification WHERE account_id = :account',
                ['account' => $accountId],
            );

            if ($activate) {
                $this->accounts->enable($accountId);
            } else {
                // Mailbox proven but administrator approval still pending:
                // the account moves from the verification stage to approval.
                $this->accounts->setDisabledReason($accountId, 'approval');
            }

            return true;
        });
    }

    /** Delete expired or orphaned verification tokens. */
    public function prune(): int
    {
        return $this->db->execute(
            'DELETE FROM email_verification WHERE expires_at < :now',
            ['now' => Time::format($this->clock->now())],
        );
    }

    private function issueToken(Account $account): void
    {
        $this->db->execute(
            'DELETE FROM email_verification WHERE account_id = :account',
            ['account' => $account->id],
        );

        $token = self::newToken();
        $now = $this->clock->now();
        $link = rtrim($this->instanceUrl, '/') . '/verify-email?token=' . rawurlencode($token);

        $this->db->execute(
            'INSERT INTO email_verification (token_hash, account_id, created_at, expires_at, used_at)'
            . ' VALUES (:hash, :account, :created, :expires, NULL)',
            [
                'hash' => self::hashToken($token),
                'account' => $account->id,
                'created' => Time::format($now),
                'expires' => Time::format($now->modify('+' . self::TTL_SECONDS . ' seconds')),
            ],
        );

        $this->outbox->enqueue(
            MailOutbox::KIND_EMAIL_VERIFICATION,
            $account->id,
            self::hashToken($token),
            $account->email,
            'Verify your email for ' . $this->instanceUrl,
            "Hello " . $account->name() . ",\n\n"
            . "an account was registered for @" . $account->handle . " on " . $this->instanceUrl . ".\n"
            . "Confirm the address by opening this link:\n\n"
            . $link . "\n\n"
            . "The link is valid for 24 hours and can only be used once.\n"
            . "If you did not register, ignore this email.\n",
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
