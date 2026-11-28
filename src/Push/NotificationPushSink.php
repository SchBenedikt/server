<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Push;

use AlohaSocial\Core\Domain\Notification\Notification;
use AlohaSocial\Core\Domain\Notification\NotificationEventSink;
use AlohaSocial\Core\Domain\Notification\NotificationType;
use AlohaSocial\Server\Community\Preferences;

/**
 * Enqueues a Web Push delivery for each notification the policy allowed.
 *
 * The payload stays deliberately generic: a notification can point at a
 * private post or conversation, so the push body never carries post text,
 * actor names or even the notification type. The service worker shows a
 * neutral "new notification" that deep-links to the bell.
 *
 * Delivery honors the account's notification preferences: a type the reader
 * muted in Settings produces no push, though the in-app notification is
 * still recorded.
 */
final class NotificationPushSink implements NotificationEventSink
{
    public function __construct(
        private readonly PushService $push,
        private readonly Preferences $preferences,
        private readonly string $instanceName,
    ) {
    }

    public function emit(Notification $notification): void
    {
        if (!$this->allowed($notification->recipientId, $notification->type)) {
            return;
        }

        $payload = json_encode([
            'title' => $this->instanceName !== '' ? $this->instanceName : 'Aloha Social',
            'body' => 'You have a new notification.',
            'tag' => 'notifications',
            'url' => '#/notifications',
        ], JSON_THROW_ON_ERROR);

        $this->push->enqueueDelivery($notification->recipientId, $payload);
    }

    private function allowed(string $accountId, NotificationType $type): bool
    {
        $preferences = $this->preferences->forAccount($accountId);

        return match ($type) {
            NotificationType::Favourite => (bool) ($preferences['notify_favourites'] ?? true),
            NotificationType::Follow => (bool) ($preferences['notify_follows'] ?? true),
            NotificationType::Mention => (bool) ($preferences['notify_mentions'] ?? true),
            NotificationType::Reblog => (bool) ($preferences['notify_boosts'] ?? true),
        };
    }
}
