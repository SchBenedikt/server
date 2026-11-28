<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Mail;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Security\SecretBox;
use AlohaSocial\Core\Security\SecretBoxException;
use AlohaSocial\Core\Support\Time;

use function bin2hex;
use function get_class;
use function json_decode;
use function json_encode;
use function min;
use function random_bytes;
use function substr;

/**
 * Durable queue for transactional mail.
 *
 * Messages are committed together with the state that requires them (for
 * example the password_reset token row), so an SMTP outage delays delivery
 * instead of losing it or failing the request. The payload — recipient,
 * subject and body, which for a reset link contains a live token — is sealed
 * with the instance secret box; the table never holds it in clear.
 *
 * Delivery is at-least-once: a crash between the SMTP handoff and the status
 * update can repeat a message, which every transactional mail tolerates.
 * Permanent failures (unreadable payload, exhausted retries) are recorded
 * with a sanitized error class, never the payload.
 */
final class MailOutbox
{
    public const KIND_PASSWORD_RESET = 'password_reset';

    private const DEFAULT_MAX_ATTEMPTS = 8;

    private const BASE_RETRY_SECONDS = 60;

    private const MAX_RETRY_SECONDS = 3600;

    public function __construct(
        private readonly Connection $db,
        private readonly Mailer $mailer,
        private readonly SecretBox $box,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Queue one message. The dedup key makes a retried enqueue of the same
     * logical mail a no-op instead of a duplicate row; callers rotate the
     * key (for example the token hash) when a newer message supersedes an
     * older one and use cancelPending() for the superseded rows.
     *
     * Runs inside the caller's transaction when one is open, so the message
     * and the state requiring it commit or roll back together.
     */
    public function enqueue(
        string $kind,
        ?string $accountId,
        string $dedupKey,
        string $to,
        string $subject,
        string $body,
    ): void {
        $now = Time::format($this->clock->now());
        $payload = $this->box->seal(json_encode(
            ['to' => $to, 'subject' => $subject, 'body' => $body],
            JSON_THROW_ON_ERROR,
        ));

        $this->db->execute(
            'INSERT INTO mail_outbox'
            . ' (id, kind, account_id, dedup_key, payload, status, attempts, max_attempts,'
            . ' next_attempt_at, last_error, created_at, sent_at)'
            . ' VALUES (:id, :kind, :account, :dedup, :payload, \'pending\','
            . ' 0, :max_attempts, :next, NULL, :created, NULL)'
            . ' ON CONFLICT (kind, dedup_key) DO NOTHING',
            [
                'id' => bin2hex(random_bytes(16)),
                'kind' => $kind,
                'account' => $accountId,
                'dedup' => $dedupKey,
                'payload' => $payload,
                'max_attempts' => self::DEFAULT_MAX_ATTEMPTS,
                'next' => $now,
                'created' => $now,
            ],
        );
    }

    /**
     * Mark every still-pending message of one kind for an account as
     * cancelled. A newer request supersedes links that were queued but not
     * yet delivered, so an obsolete reset token never reaches a mailbox.
     */
    public function cancelPending(string $kind, string $accountId): void
    {
        $this->db->execute(
            "UPDATE mail_outbox SET status = 'cancelled'"
            . " WHERE kind = :kind AND account_id = :account AND status = 'pending'",
            ['kind' => $kind, 'account' => $accountId],
        );
    }

    /**
     * Deliver due messages, oldest first.
     *
     * @return array{sent: int, retried: int, failed: int, cancelled: int}
     */
    public function dispatch(int $limit = 10): array
    {
        $result = ['sent' => 0, 'retried' => 0, 'failed' => 0, 'cancelled' => 0];
        $now = $this->clock->now();
        $nowValue = Time::format($now);

        // Cancelled rows are never selected; sweep a bounded batch so the
        // table does not accumulate them forever.
        $result['cancelled'] = $this->db->execute(
            "DELETE FROM mail_outbox WHERE status = 'cancelled' AND id IN"
            . " (SELECT id FROM mail_outbox WHERE status = 'cancelled'"
            . " ORDER BY created_at LIMIT :limit)",
            ['limit' => $limit],
        );

        $rows = $this->db->fetchAll(
            "SELECT id, payload, attempts, max_attempts FROM mail_outbox"
            . " WHERE status = 'pending' AND next_attempt_at <= :now"
            . ' ORDER BY created_at LIMIT :limit',
            ['now' => $nowValue, 'limit' => $limit],
        );

        foreach ($rows as $row) {
            $id = (string) $row['id'];
            try {
                $message = json_decode(
                    $this->box->open((string) $row['payload']),
                    true,
                    8,
                    JSON_THROW_ON_ERROR,
                );
            } catch (SecretBoxException|\JsonException) {
                $this->markFailed($id, 'payload_unreadable');
                $result['failed']++;
                continue;
            }

            try {
                $this->mailer->send(
                    (string) $message['to'],
                    (string) $message['subject'],
                    (string) $message['body'],
                );
            } catch (MailException $exception) {
                $result[$this->recordFailure($row, $exception) ? 'failed' : 'retried']++;
                continue;
            }

            $this->db->execute(
                "UPDATE mail_outbox SET status = 'sent', sent_at = :now, last_error = NULL"
                . " WHERE id = :id AND status = 'pending'",
                ['now' => $nowValue, 'id' => $id],
            );
            $result['sent']++;
        }

        return $result;
    }

    /**
     * Number of messages still waiting for delivery, for diagnostics.
     */
    public function pendingCount(): int
    {
        return (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM mail_outbox WHERE status = 'pending'",
        );
    }

    /**
     * @return bool true when the message exhausted its retries
     */
    private function recordFailure(array $row, MailException $exception): bool
    {
        $attempts = (int) $row['attempts'] + 1;
        $maxAttempts = (int) $row['max_attempts'];

        if ($attempts >= $maxAttempts) {
            $this->markFailed((string) $row['id'], $this->errorLabel($exception));

            return true;
        }

        $delay = min(self::MAX_RETRY_SECONDS, self::BASE_RETRY_SECONDS * (2 ** min($attempts, 6)));
        $this->db->execute(
            "UPDATE mail_outbox SET attempts = :attempts, next_attempt_at = :next,"
            . ' last_error = :error WHERE id = :id AND status = \'pending\'',
            [
                'attempts' => $attempts,
                'next' => Time::format($this->clock->now()->modify('+' . $delay . ' seconds')),
                'error' => $this->errorLabel($exception),
                'id' => (string) $row['id'],
            ],
        );

        return false;
    }

    private function markFailed(string $id, string $error): void
    {
        $this->db->execute(
            "UPDATE mail_outbox SET status = 'failed', last_error = :error WHERE id = :id",
            ['error' => $error, 'id' => $id],
        );
    }

    /**
     * Sanitized outcome for operators: the exception class and a truncated
     * message. Mailer errors describe the transport, never the payload.
     */
    private function errorLabel(MailException $exception): string
    {
        return substr(get_class($exception) . ': ' . $exception->getMessage(), 0, 200);
    }
}
