<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Federation\Atproto;

use AlohaSocial\Core\Domain\Atproto\AtprotoPostProjection;
use AlohaSocial\Core\Domain\Atproto\AtprotoRemoteRecord;
use AlohaSocial\Core\Domain\Atproto\AtprotoIdentifiers;
use AlohaSocial\Core\Domain\Federation\RemoteObjectType;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use Throwable;

use function hash;
use function is_array;
use function is_string;
use function json_decode;
use function max;
use function mb_strlen;
use function min;
use function substr;
use function trim;

/**
 * Projects reconciled AT Protocol records into the readable social tables.
 *
 * The core's inbound store keeps every record it accepted; this host-side
 * reader turns the ones a member can actually see into rows the existing
 * timelines already understand:
 *
 *  - `app.bsky.feed.post` from a followed account becomes a cached remote post
 *  - a reply whose parent is one of this instance's published records is
 *    indexed for the reply counter
 *  - a like on one of this instance's published records becomes an inbound
 *    reaction, so the card counter includes Bluesky likes
 *  - a repost of one of this instance's published records contributes to its
 *    reblog counter
 *
 * Every step is an idempotent upsert keyed on the AT record URI, and a
 * tombstone removes exactly the rows that record created. That makes the pass
 * safe to repeat after a crash, a reconnect or a reconciliation request.
 */
final class AtprotoReconciler
{
    public const POST = 'app.bsky.feed.post';

    public const LIKE = 'app.bsky.feed.like';

    public const REPOST = 'app.bsky.feed.repost';

    public const MAX_LIMIT = 1000;

    private const MAX_DID_LENGTH = 255;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly string $source = JetstreamEventSource::NAME,
    ) {
    }

    /**
     * @return array{projected: int, removed: int, skipped: int}
     */
    public function run(int $limit = 300): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $rows = $this->db->fetchAll(
            'SELECT uri, did, collection, rkey, cid, rev, deleted, record_json, sequence, received_at'
            . ' FROM social_atproto_remote_record'
            . ' WHERE source = :source AND collection IN (:post, :like, :repost)'
            . ' ORDER BY sequence DESC LIMIT ' . $limit,
            [
                'source' => $this->source,
                'post' => self::POST,
                'like' => self::LIKE,
                'repost' => self::REPOST,
            ],
        );

        $publications = $this->publications();
        $followed = $this->followedDids();
        $localDids = $this->localDids();
        $projected = 0;
        $removed = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $collection = (string) ($row['collection'] ?? '');
            $uri = (string) ($row['uri'] ?? '');
            if ($uri === '') {
                ++$skipped;
                continue;
            }

            if ((int) ($row['deleted'] ?? 0) === 1) {
                $removed += $this->remove($uri);
                continue;
            }

            if ($collection === self::POST) {
                $projected += $this->projectPost($row, $publications, $followed);
                continue;
            }

            if ($collection === self::LIKE) {
                $projected += $this->projectLike($row, $publications, $localDids);
                continue;
            }

            if ($collection === self::REPOST) {
                $projected += $this->projectRepost($row, $publications, $localDids, $followed);
                continue;
            }

            ++$skipped;
        }

        return ['projected' => $projected, 'removed' => $removed, 'skipped' => $skipped];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $publications
     * @param array<string, true> $followed
     */
    private function projectPost(array $row, array $publications, array $followed): int
    {
        $record = $this->recordBody($row);
        if ($record === null) {
            return 0;
        }

        $projection = AtprotoPostProjection::fromRecord($this->record($row, $record));
        if ($projection === null) {
            return 0;
        }

        $projected = 0;
        $did = $projection->did;

        // A reply to one of this instance's posts is readable even when the
        // author is not followed: it is part of a local conversation.
        $parentId = null;
        if ($projection->replyToUri !== null) {
            $parentId = $publications[$projection->replyToUri] ?? null;
        }

        if ($parentId !== null) {
            $this->indexReply($projection->uri, $parentId, $did, $projection->createdAt);
            ++$projected;
        }

        if (isset($followed[$did])) {
            $this->storeRemotePost($projection);
            ++$projected;
        } elseif ($parentId !== null) {
            // Keep the reply readable in the thread even though its author is
            // not followed; it is not added to any timeline.
            $this->storeRemotePost($projection);
            ++$projected;
        }

        return $projected;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $publications
     * @param array<string, true> $localDids
     */
    private function projectLike(array $row, array $publications, array $localDids): int
    {
        $record = $this->recordBody($row);
        if ($record === null) {
            return 0;
        }

        $subject = $record['subject'] ?? null;
        if (!is_array($subject) || !is_string($subject['uri'] ?? null)) {
            return 0;
        }

        $parentId = $publications[trim($subject['uri'])] ?? null;
        if ($parentId === null) {
            return 0;
        }

        $uri = (string) ($row['uri'] ?? '');
        $did = (string) ($row['did'] ?? '');
        if ($uri === '' || $did === '' || mb_strlen($did) > self::MAX_DID_LENGTH) {
            return 0;
        }

        // A mirrored local favourite already contributes to the local count;
        // its Jetstream echo must not appear as a second inbound reaction.
        if (isset($localDids[$did])) {
            $this->db->execute('DELETE FROM social_reaction WHERE activity_id = :activity_id', ['activity_id' => $uri]);

            return 0;
        }

        $createdAt = $this->timestamp($record['createdAt'] ?? null) ?? $this->clock->now();
        $id = 'atp_' . substr(hash('sha256', $uri), 0, 32);

        $this->db->execute(
            'INSERT INTO social_reaction'
            . ' (id, direction, subject_id, object_id, local_post_id, kind, emoji, icon_url, activity_id, created_at)'
            . " VALUES (:id, 'in', :subject, :object, :local_post_id, 'like', '', NULL, :activity_id, :created_at)"
            . ' ON CONFLICT DO NOTHING',
            [
                'id' => $id,
                'subject' => $did,
                'object' => trim($subject['uri']),
                'local_post_id' => $parentId,
                'activity_id' => $uri,
                'created_at' => Time::format($createdAt),
            ],
        );

        return 1;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $publications
     * @param array<string, true> $localDids
     * @param array<string, true> $followed
     */
    private function projectRepost(array $row, array $publications, array $localDids, array $followed): int
    {
        $record = $this->recordBody($row);
        if ($record === null) {
            return 0;
        }

        $subject = $record['subject'] ?? null;
        if (!is_array($subject)
            || !is_string($subject['uri'] ?? null)
            || !is_string($subject['cid'] ?? null)
            || trim($subject['cid']) === ''
        ) {
            return 0;
        }

        $parentId = $publications[trim($subject['uri'])] ?? null;
        $uri = (string) ($row['uri'] ?? '');
        $did = (string) ($row['did'] ?? '');
        if ($uri === '' || !AtprotoIdentifiers::isDid($did)
            || mb_strlen($did) > self::MAX_DID_LENGTH
        ) {
            return 0;
        }

        $id = 'atp_' . substr(hash('sha256', $uri), 0, 32);
        if (isset($localDids[$did])) {
            $this->db->execute('DELETE FROM social_boost WHERE id = :id', ['id' => $id]);
            $this->db->execute('DELETE FROM social_remote_post WHERE activity_id = :activity_id', ['activity_id' => $uri]);

            return 0;
        }

        $createdAt = $this->timestamp($record['createdAt'] ?? null) ?? $this->clock->now();
        if ($parentId === null) {
            return $this->projectRemoteRepost(trim($subject['uri']), $did, $uri, $createdAt, $followed);
        }

        $this->db->execute(
            'INSERT INTO social_boost (id, user_id, post_id, created_at)'
            . ' VALUES (:id, :user_id, :post_id, :created_at) ON CONFLICT DO NOTHING',
            [
                'id' => $id,
                'user_id' => $this->remoteBoostActorKey($did),
                'post_id' => $parentId,
                'created_at' => Time::format($createdAt),
            ],
        );

        return 1;
    }

    /**
     * Project a followed account's repost of an imported ATProto post into
     * the home timeline. The original subject is fetched only from the
     * already validated inbound-record store.
     *
     * @param array<string, true> $followed
     */
    private function projectRemoteRepost(
        string $subjectUri,
        string $reposterDid,
        string $repostUri,
        DateTimeImmutable $createdAt,
        array $followed,
    ): int {
        if (!isset($followed[$reposterDid])) {
            return 0;
        }

        $subjectRow = $this->db->fetchOne(
            'SELECT uri, did, collection, rkey, cid, rev, deleted, record_json, sequence, received_at'
            . ' FROM social_atproto_remote_record'
            . ' WHERE source = :source AND uri = :uri AND collection = :collection AND deleted = 0',
            ['source' => $this->source, 'uri' => $subjectUri, 'collection' => self::POST],
        );
        if ($subjectRow === null) {
            return 0;
        }

        $subjectBody = $this->recordBody($subjectRow);
        if ($subjectBody === null) {
            return 0;
        }

        $subject = AtprotoPostProjection::fromRecord($this->record($subjectRow, $subjectBody));
        if ($subject === null) {
            return 0;
        }

        $now = Time::format($this->clock->now());
        $this->db->execute(
            'INSERT INTO social_remote_post'
            . ' (activity_id, object_id, object_author_id, actor_id, kind, object_type, object_metadata,'
            . ' quoted_object_id, content, content_warning, published_at, updated_at, deleted_at)'
            . ' VALUES (:activity_id, :object_id, :object_author, :actor, :kind, :object_type, NULL,'
            . ' NULL, :content, NULL, :published_at, :updated_at, NULL)'
            . ' ON CONFLICT (activity_id) DO UPDATE SET object_id = excluded.object_id,'
            . ' object_author_id = excluded.object_author_id, actor_id = excluded.actor_id,'
            . ' kind = excluded.kind, object_type = excluded.object_type, content = excluded.content,'
            . ' published_at = excluded.published_at, updated_at = excluded.updated_at, deleted_at = NULL',
            [
                'activity_id' => $repostUri,
                'object_id' => $subjectUri,
                'object_author' => $subject->did,
                'actor' => $reposterDid,
                'kind' => 'boost',
                'object_type' => RemoteObjectType::Note->value,
                'content' => $subject->text,
                'published_at' => Time::format($createdAt),
                'updated_at' => $now,
            ],
        );

        return 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function storeRemotePost(AtprotoPostProjection $projection): void
    {
        $now = Time::format($this->clock->now());
        $existing = $this->db->fetchOne(
            'SELECT activity_id FROM social_remote_post WHERE activity_id = :activity_id',
            ['activity_id' => $projection->uri],
        );

        if ($existing === null) {
            $this->db->execute(
                'INSERT INTO social_remote_post'
                . ' (activity_id, object_id, object_author_id, actor_id, kind, object_type, object_metadata,'
                . ' quoted_object_id, content, content_warning, published_at, updated_at, deleted_at)'
                . " VALUES (:activity_id, :object_id, :author, :actor, 'post', 'note', NULL, NULL,"
                . ' :content, NULL, :published_at, :updated_at, NULL)',
                [
                    'activity_id' => $projection->uri,
                    'object_id' => $projection->uri,
                    'author' => $projection->did,
                    'actor' => $projection->did,
                    'content' => $projection->text,
                    'published_at' => Time::format($projection->createdAt),
                    'updated_at' => $now,
                ],
            );

            return;
        }

        $this->db->execute(
            'UPDATE social_remote_post SET content = :content, object_author_id = :author,'
            . ' published_at = :published_at, updated_at = :updated_at, deleted_at = NULL'
            . ' WHERE activity_id = :activity_id',
            [
                'content' => $projection->text,
                'author' => $projection->did,
                'published_at' => Time::format($projection->createdAt),
                'updated_at' => $now,
                'activity_id' => $projection->uri,
            ],
        );
    }

    private function indexReply(string $uri, string $parentId, string $did, DateTimeImmutable $createdAt): void
    {
        if (mb_strlen($did) > self::MAX_DID_LENGTH) {
            return;
        }

        $this->db->execute(
            'INSERT INTO social_remote_reply (activity_id, object_id, in_reply_to_id, actor_id, created_at)'
            . ' VALUES (:activity_id, :object_id, :in_reply_to_id, :actor_id, :created_at)'
            . ' ON CONFLICT DO NOTHING',
            [
                'activity_id' => $uri,
                'object_id' => $uri,
                'in_reply_to_id' => $parentId,
                'actor_id' => $did,
                'created_at' => Time::format($createdAt),
            ],
        );
    }

    /**
     * A tombstone removes the projection of exactly that record.
     */
    private function remove(string $uri): int
    {
        $removed = 0;
        $now = Time::format($this->clock->now());

        $removed += $this->db->execute(
            'UPDATE social_remote_post SET deleted_at = :deleted_at'
            . ' WHERE activity_id = :activity_id AND deleted_at IS NULL',
            ['deleted_at' => $now, 'activity_id' => $uri],
        );

        $removed += $this->db->execute(
            'DELETE FROM social_reaction WHERE activity_id = :activity_id',
            ['activity_id' => $uri],
        );

        $removed += $this->db->execute(
            'DELETE FROM social_boost WHERE id = :id',
            ['id' => 'atp_' . substr(hash('sha256', $uri), 0, 32)],
        );

        $removed += $this->db->execute(
            'DELETE FROM social_remote_reply WHERE activity_id = :activity_id',
            ['activity_id' => $uri],
        );

        return $removed;
    }

    /**
     * Local AT record URIs and the post they belong to.
     *
     * @return array<string, string>
     */
    private function publications(): array
    {
        $result = [];
        try {
            $rows = $this->db->fetchAll(
                'SELECT uri, post_id FROM social_atproto_publication ORDER BY published_at DESC LIMIT 10000',
            );
        } catch (Throwable) {
            return $result;
        }

        foreach ($rows as $row) {
            $uri = is_string($row['uri'] ?? null) ? trim($row['uri']) : '';
            $postId = is_string($row['post_id'] ?? null) ? $row['post_id'] : '';
            if ($uri !== '' && $postId !== '') {
                $result[$uri] = $postId;
            }
        }

        return $result;
    }

    /**
     * @return array<string, true>
     */
    private function followedDids(): array
    {
        $result = [];
        try {
            $rows = $this->db->fetchAll(
                "SELECT actor_id FROM social_following WHERE state = 'accepted'",
            );
        } catch (Throwable) {
            return $result;
        }

        foreach ($rows as $row) {
            $actor = is_string($row['actor_id'] ?? null) ? trim($row['actor_id']) : '';
            if ($actor !== '') {
                $result[$actor] = true;
            }
        }

        // AT Protocol follows are stored separately from ActivityPub follows.
        // Include active graph edges so Jetstream records from a Bluesky
        // follow can be projected into the shared remote-post cache.
        try {
            $rows = $this->db->fetchAll(
                "SELECT DISTINCT subject_did FROM social_atproto_follow WHERE state = 'active'",
            );
        } catch (Throwable) {
            return $result;
        }

        foreach ($rows as $row) {
            $actor = is_string($row['subject_did'] ?? null) ? trim($row['subject_did']) : '';
            if ($actor !== '') {
                $result[$actor] = true;
            }
        }

        return $result;
    }

    /** @return array<string, true> */
    private function localDids(): array
    {
        $result = [];
        foreach ([
            'SELECT did FROM atproto_account',
            'SELECT did FROM social_pds_identity',
            // Keep echo suppression after an external account is disconnected;
            // its durable interaction ledger retains the identity that wrote it.
            'SELECT DISTINCT account_did AS did FROM social_atproto_interaction',
        ] as $query) {
            try {
                $rows = $this->db->fetchAll($query);
            } catch (Throwable) {
                continue;
            }

            foreach ($rows as $row) {
                $did = is_string($row['did'] ?? null) ? trim($row['did']) : '';
                if (AtprotoIdentifiers::isDid($did)) {
                    $result[$did] = true;
                }
            }
        }

        return $result;
    }

    private function remoteBoostActorKey(string $did): string
    {
        // social_boost.user_id is limited to 64 characters; keep arbitrary
        // valid DIDs collision-resistant without truncating or invalidating them.
        return 'atp_' . substr(hash('sha256', 'atproto-did|' . $did), 0, 56);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function record(array $row, array $body): AtprotoRemoteRecord
    {
        $receivedAt = $this->timestamp($row['received_at'] ?? null) ?? $this->clock->now();
        $sequence = $row['sequence'] === null ? null : (int) $row['sequence'];

        return new AtprotoRemoteRecord(
            (string) $this->source,
            (string) $row['did'],
            (string) $row['collection'],
            (string) $row['rkey'],
            (string) $row['uri'],
            is_string($row['cid'] ?? null) ? $row['cid'] : null,
            is_string($row['rev'] ?? null) ? $row['rev'] : null,
            $sequence,
            $body,
            false,
            null,
            $receivedAt,
            $receivedAt,
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>|null
     */
    private function recordBody(array $row): ?array
    {
        $raw = $row['record_json'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function timestamp(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Time::parse(trim($value));
        } catch (Throwable) {
            return null;
        }
    }
}
