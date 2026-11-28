<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests\Support;

use AlohaSocial\Server\Mail\Mailer;
use AlohaSocial\Server\Mail\MailException;

/**
 * Captures sent messages and can be told to fail a bounded number of times,
 * standing in for an unreachable SMTP server.
 */
final class RecordingMailer implements Mailer
{
    /** @var list<array{to: string, subject: string, body: string}> */
    public array $messages = [];

    public int $failuresRemaining = 0;

    public function send(string $to, string $subject, string $body): void
    {
        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;
            throw new MailException('SMTP server rejected the request with status 451.');
        }

        $this->messages[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    }
}
