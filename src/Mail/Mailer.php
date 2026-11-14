<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Mail;

/**
 * The tiny outbound-mail port of the host.
 *
 * The core does not send mail: password reset and other account mail belong
 * to the host (ADR 0006). Two transports ship with the server: a file
 * transport for development and tests, and SMTP for deployments.
 */
interface Mailer
{
    /**
     * @throws MailException when the message could not be handed off
     */
    public function send(string $to, string $subject, string $body): void;
}
