<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Core\Security\OpenSslSecretBox;
use AlohaSocial\Server\Mail\MailOutbox;
use AlohaSocial\Server\Tests\Support\RecordingMailer;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class MailOutboxTest extends TestCase
{
    public function testQueuedMailIsDeliveredOnceAndPayloadStaysSealed(): void
    {
        $server = new TestServer();
        $mailer = new RecordingMailer();
        $outbox = $this->outbox($server, $mailer);

        $outbox->enqueue(
            MailOutbox::KIND_PASSWORD_RESET,
            'alice',
            'token-hash-1',
            'alice@example.test',
            'Reset your password',
            'Open https://social.example.test/reset-password?token=secret-token',
        );

        $stored = (string) $server->runtime->database->fetchValue(
            'SELECT payload FROM mail_outbox',
        );
        self::assertStringNotContainsString('alice@example.test', $stored);
        self::assertStringNotContainsString('secret-token', $stored);

        $result = $outbox->dispatch();
        self::assertSame(['sent' => 1, 'retried' => 0, 'failed' => 0, 'cancelled' => 0], $result);
        self::assertCount(1, $mailer->messages);
        self::assertSame('alice@example.test', $mailer->messages[0]['to']);
        self::assertStringContainsString('secret-token', $mailer->messages[0]['body']);

        // A sent row is never delivered twice.
        self::assertSame(0, $outbox->dispatch()['sent']);
        self::assertCount(1, $mailer->messages);
    }

    public function testTransientFailureRetriesWithBackoffThenDelivers(): void
    {
        $server = new TestServer();
        $mailer = new RecordingMailer();
        $mailer->failuresRemaining = 2;
        $outbox = $this->outbox($server, $mailer);

        $outbox->enqueue(MailOutbox::KIND_PASSWORD_RESET, 'alice', 'k1', 'a@b.test', 's', 'b');

        self::assertSame(1, $outbox->dispatch()['retried']);
        self::assertCount(0, $mailer->messages);

        $row = $server->runtime->database->fetchOne('SELECT * FROM mail_outbox');
        self::assertSame(1, (int) $row['attempts']);
        self::assertStringContainsString('MailException', (string) $row['last_error']);
        self::assertStringNotContainsString('a@b.test', (string) $row['last_error']);

        // The backoff moved next_attempt_at into the future; make it due.
        $server->runtime->database->execute(
            "UPDATE mail_outbox SET next_attempt_at = '2000-01-01T00:00:00Z'",
        );
        self::assertSame(1, $outbox->dispatch()['retried']);

        $server->runtime->database->execute(
            "UPDATE mail_outbox SET next_attempt_at = '2000-01-01T00:00:00Z'",
        );
        self::assertSame(1, $outbox->dispatch()['sent']);
        self::assertCount(1, $mailer->messages);
    }

    public function testExhaustedRetriesMarkTheMessageFailed(): void
    {
        $server = new TestServer();
        $mailer = new RecordingMailer();
        $mailer->failuresRemaining = 100;
        $outbox = $this->outbox($server, $mailer);

        $outbox->enqueue(MailOutbox::KIND_PASSWORD_RESET, 'alice', 'k1', 'a@b.test', 's', 'b');
        $server->runtime->database->execute('UPDATE mail_outbox SET max_attempts = 2');

        self::assertSame(1, $outbox->dispatch()['retried']);
        $server->runtime->database->execute(
            "UPDATE mail_outbox SET next_attempt_at = '2000-01-01T00:00:00Z'",
        );
        self::assertSame(1, $outbox->dispatch()['failed']);

        $status = $server->runtime->database->fetchValue("SELECT status FROM mail_outbox");
        self::assertSame('failed', $status);
        self::assertSame(0, $outbox->dispatch()['sent']);
    }

    public function testCancelPendingSupersedesQueuedMailAndDuplicateKeysAreIgnored(): void
    {
        $server = new TestServer();
        $mailer = new RecordingMailer();
        $outbox = $this->outbox($server, $mailer);

        $outbox->enqueue(MailOutbox::KIND_PASSWORD_RESET, 'alice', 'old-token', 'a@b.test', 's', 'old body');
        $outbox->enqueue(MailOutbox::KIND_PASSWORD_RESET, 'alice', 'old-token', 'a@b.test', 's', 'old body');
        $outbox->cancelPending(MailOutbox::KIND_PASSWORD_RESET, 'alice');
        $outbox->enqueue(MailOutbox::KIND_PASSWORD_RESET, 'alice', 'new-token', 'a@b.test', 's', 'new body');

        self::assertSame(2, (int) $server->runtime->database->fetchValue('SELECT COUNT(*) FROM mail_outbox'));

        $result = $outbox->dispatch();
        self::assertSame(1, $result['sent']);
        self::assertSame(1, $result['cancelled']);
        self::assertCount(1, $mailer->messages);
        self::assertStringContainsString('new body', $mailer->messages[0]['body']);
    }

    private function outbox(TestServer $server, RecordingMailer $mailer): MailOutbox
    {
        return new MailOutbox(
            $server->runtime->database,
            $mailer,
            OpenSslSecretBox::fromBase64Key(OpenSslSecretBox::generateKey()),
            new SystemClock(),
        );
    }
}
