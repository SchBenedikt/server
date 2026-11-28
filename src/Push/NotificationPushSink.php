<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Push;

use AlohaSocial\Core\Domain\Notification\Notification;
use AlohaSocial\Core\Domain\Notification\NotificationEventSink;

/**
 * Enqueues a Web Push delivery for each notification the policy allowed.
 *
 * The payload stays deliberately generic: a notification can point at a
 * private post or conversation, so the push body never carries post text,
 * actor names or even the notification type. The service worker shows a
 * neutral "new notification" that deep-links to the bell.
 */
final class NotificationPushSink implements NotificationEventSink
{
    public function __construct(
        private readonly PushService $push,
        private readonly string $instanceName,
    ) {
    }

    public function emit(Notification $notification): void
    {
        $payload = json_encode([
            'title' => $this->instanceName !== '' ? $this->instanceName : 'Aloha Social',
            'body' => 'You have a new notification.',
            'tag' => 'notifications',
            'url' => '#/notifications',
        ], JSON_THROW_ON_ERROR);

        $this->push->enqueueDelivery($notification->recipientId, $payload);
    }
}
