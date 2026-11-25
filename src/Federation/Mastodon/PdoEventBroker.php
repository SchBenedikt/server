<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Federation\Mastodon;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\TransactionRunner;
use AlohaSocial\Core\Platform\Events\EventBroker;
use AlohaSocial\Core\Platform\Events\StreamAudience;
use AlohaSocial\Core\Platform\Events\StreamEvent;
use AlohaSocial\Core\Platform\Events\StreamEventDraft;
use AlohaSocial\Core\Platform\Events\StreamSubscription;
use AlohaSocial\Core\Support\RandomIdGenerator;
use AlohaSocial\Core\Support\Time;
use InvalidArgumentException;

/** Durable SQL broker; recipient rows make home-stream reads indexed. */
final class PdoEventBroker implements EventBroker
{
    private const MAX_READ = 100;

    private readonly RandomIdGenerator $ids;

    private ?string $lastPrunedAt = null;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    )
    {
        $this->ids = new RandomIdGenerator();
    }

    public function publish(StreamEventDraft $event): void
    {
        $this->pruneExpired();
        $write = function () use ($event): void {
            $id = $this->ids->generate();
            $this->db->execute(
                'INSERT INTO mastodon_stream_event'
                . ' (event_id, event_name, audience, actor_id, payload_json, occurred_at)'
                . ' VALUES (:event_id, :event_name, :audience, :actor_id, :payload_json, :occurred_at)',
                [
                    'event_id' => $id,
                    'event_name' => $event->name,
                    'audience' => $event->audience->value,
                    'actor_id' => $event->actorId,
                    'payload_json' => json_encode($event->payload, JSON_THROW_ON_ERROR),
                    'occurred_at' => $event->occurredAt,
                ],
            );
            $sequence = $this->db->fetchValue(
                'SELECT sequence FROM mastodon_stream_event WHERE event_id = :event_id',
                ['event_id' => $id],
            );
            if (!is_numeric($sequence)) {
                throw new \RuntimeException('The stream event was stored without a replay sequence.');
            }

            foreach ($event->recipientIds as $recipientId) {
                $this->db->execute(
                    'INSERT INTO mastodon_stream_event_recipient (event_sequence, account_id)'
                    . ' VALUES (:event_sequence, :account_id)',
                    ['event_sequence' => (int) $sequence, 'account_id' => $recipientId],
                );
            }
        };

        if ($this->db instanceof TransactionRunner) {
            $this->db->transaction($write);
            return;
        }

        $write();
    }

    public function read(StreamSubscription $subscription, ?string $cursor, int $limit): array
    {
        $this->pruneExpired();
        if ($limit < 1) {
            throw new InvalidArgumentException('A stream event read limit must be positive.');
        }
        $limit = min(self::MAX_READ, $limit);
        $afterSequence = 0;
        if ($cursor !== null && $cursor !== '') {
            $sequence = $this->db->fetchValue(
                'SELECT sequence FROM mastodon_stream_event WHERE event_id = :event_id',
                ['event_id' => $cursor],
            );
            if (!is_numeric($sequence)) {
                throw new InvalidArgumentException('The stream replay cursor is unknown or expired.');
            }
            $afterSequence = (int) $sequence;
        }

        $channels = [];
        foreach ($subscription->channels as $channel) {
            $channels[$channel] = match ($channel) {
                'public' => "e.event_name IN ('status.created', 'status.updated', 'status.deleted') AND e.audience = 'public'",
                'public:local' => "e.event_name IN ('status.created', 'status.updated', 'status.deleted') AND e.audience = 'public'"
                    . ' AND EXISTS (SELECT 1 FROM account local_actor WHERE local_actor.id = e.actor_id)',
                'home' => "e.event_name IN ('status.created', 'status.updated', 'status.deleted') AND e.audience IN ('public', 'followers') AND recipient.account_id IS NOT NULL",
                'list' => "e.event_name IN ('status.created', 'status.updated', 'status.deleted')"
                    . " AND (e.audience = 'public' OR (e.audience = 'followers' AND recipient.account_id IS NOT NULL))"
                    . ' AND EXISTS (SELECT 1 FROM social_account_list owned_list'
                    . ' JOIN social_account_list_member list_member ON list_member.list_id = owned_list.id'
                    . ' WHERE owned_list.id = :stream_list_id AND owned_list.user_id = :recipient_user_id'
                    . ' AND list_member.account_id = e.actor_id)',
                'user' => 'e.actor_id = :actor_user_id',
                'user:notification' => "e.event_name = 'notification.created' AND recipient.account_id IS NOT NULL",
                'direct' => "e.event_name IN ('status.created', 'status.updated', 'status.deleted') AND e.audience = 'direct' AND recipient.account_id IS NOT NULL",
                default => throw new InvalidArgumentException('This Mastodon stream channel is not available yet.'),
            };
        }
        $conditions = '(' . implode(' OR ', array_values($channels)) . ')';
        $parameters = [
            'recipient_user_id' => $subscription->userId,
            'after_sequence' => $afterSequence,
            'limit' => $limit,
        ];
        if (isset($channels['user'])) {
            $parameters['actor_user_id'] = $subscription->userId;
        }
        if (isset($channels['list'])) {
            $parameters['stream_list_id'] = $subscription->listId;
        }
        $rows = $this->db->fetchAll(
            'SELECT DISTINCT e.sequence, e.event_id, e.event_name, e.audience, e.actor_id, e.payload_json, e.occurred_at'
            . ' FROM mastodon_stream_event e'
            . ' LEFT JOIN mastodon_stream_event_recipient recipient'
            . ' ON recipient.event_sequence = e.sequence AND recipient.account_id = :recipient_user_id'
            . ' WHERE e.sequence > :after_sequence AND ' . $conditions
            . ' ORDER BY e.sequence ASC LIMIT :limit',
            $parameters,
        );

        $sequences = array_values(array_filter(array_map(
            static fn (array $row): int => (int) $row['sequence'],
            $rows,
        )));
        $recipientsBySequence = $this->recipients($sequences);

        return array_values(array_map(
            static function (array $row) use ($recipientsBySequence): StreamEvent {
                $payload = json_decode((string) $row['payload_json'], true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($payload)) {
                    throw new \UnexpectedValueException('A stored stream event payload is not an object.');
                }

                return new StreamEvent(
                    id: (string) $row['event_id'],
                    name: (string) $row['event_name'],
                    audience: StreamAudience::from((string) $row['audience']),
                    actorId: (string) $row['actor_id'],
                    recipientIds: $recipientsBySequence[(int) $row['sequence']] ?? [],
                    payload: $payload,
                    occurredAt: (string) $row['occurred_at'],
                );
            },
            $rows,
        ));
    }

    private function pruneExpired(): void
    {
        $now = $this->clock->now();
        if ($this->lastPrunedAt !== null
            && $now < new \DateTimeImmutable($this->lastPrunedAt)->modify('+1 minute')
        ) {
            return;
        }
        $this->db->execute(
            'DELETE FROM mastodon_stream_event WHERE occurred_at < :cutoff',
            ['cutoff' => Time::format($now->modify('-7 days'))],
        );
        $this->lastPrunedAt = Time::format($now);
    }

    /** @param list<int> $sequences @return array<int, list<string>> */
    private function recipients(array $sequences): array
    {
        if ($sequences === []) {
            return [];
        }

        $parameters = [];
        $placeholders = [];
        foreach ($sequences as $index => $sequence) {
            $key = 'sequence_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $sequence;
        }
        $rows = $this->db->fetchAll(
            'SELECT event_sequence, account_id FROM mastodon_stream_event_recipient'
            . ' WHERE event_sequence IN (' . implode(', ', $placeholders) . ')'
            . ' ORDER BY event_sequence, account_id',
            $parameters,
        );

        $recipients = [];
        foreach ($rows as $row) {
            $recipients[(int) $row['event_sequence']][] = (string) $row['account_id'];
        }

        return $recipients;
    }
}
