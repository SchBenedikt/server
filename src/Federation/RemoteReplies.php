<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Federation;

use AlohaSocial\Core\Domain\Federation\PdoRemotePostRepository;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Jobs\JobQueue;
use AlohaSocial\Core\Jobs\JobSubject;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Core\Service\ValidationException;

use function array_values;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;
use function trim;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Replies to posts that live on another server.
 *
 * The core resolves every `in_reply_to_id` against its own post table. That
 * is correct for a reply inside the local instance, but a public conversation
 * also has remote parents. This service creates the local post through the
 * core while its parent is still absent, then retargets it and rewrites the
 * already queued Create inside the same database transaction. A worker can
 * therefore never observe a delivery without `inReplyTo` or a post without its
 * remote parent.
 *
 * The parent author's inbox is resolved before the post is written, so a
 * reply that cannot be delivered does not leave a half-published comment.
 */
final class RemoteReplies
{
    public function __construct(
        private readonly Connection $db,
        private readonly PostService $posts,
        private readonly ActivityFactory $activities,
        private readonly JobQueue $queue,
        private readonly RemoteDirectory $directory,
        private readonly Clock $clock,
        private readonly ?RemotePostImporter $importer = null,
    ) {
    }

    /**
     * The canonical ActivityPub URL of a local post.
     *
     * The thread view needs it to recognize its own reply when the origin
     * echoes the delivered Create back as a remote descendant.
     */
    public function noteUrlFor(string $postId): string
    {
        return $this->activities->noteIdFor($postId);
    }

    /**
     * @param list<string> $attachmentIds
     *
     * @throws ValidationException when the post itself is invalid
     * @throws RemoteReplyException when the parent or its inbox cannot be resolved
     */
    public function create(
        string $userId,
        string $target,
        string $content,
        Visibility $visibility,
        ?string $contentWarning = null,
        array $attachmentIds = [],
        ?string $quotedPostId = null,
        ?string $language = null,
    ): Post {
        $target = trim($target);
        if ($target === '' || preg_match('#^https?://#i', $target) !== 1) {
            throw new RemoteReplyException(
                422,
                'remote_reply_invalid',
                'The reply target must be a fediverse post URL.',
            );
        }

        if ($visibility === Visibility::Direct) {
            throw new RemoteReplyException(
                422,
                'remote_reply_direct_unsupported',
                'A reply to a remote post cannot be a direct message.',
            );
        }

        $posts = new PdoRemotePostRepository($this->db);
        $remote = $posts->findPostByObjectId($target);
        if (($remote === null || $remote->deletedAt !== null) && $this->importer !== null) {
            // A live search result has no cache row yet. Import the public
            // object so the parent, its author and later counts share the
            // same record an inbound Create would have produced.
            try {
                $remote = $this->importer->ensure($target) ?? $posts->findPostByObjectId($target);
            } catch (RemoteDirectoryException $exception) {
                if ($exception->status === 404) {
                    throw new RemoteReplyException(404, 'remote_post_not_found', 'This post is no longer available.');
                }

                throw new RemoteReplyException(502, 'remote_reply_unreachable', $exception->getMessage());
            }
        }
        if ($remote !== null && $remote->deletedAt !== null) {
            throw new RemoteReplyException(404, 'remote_post_not_found', 'This post is no longer available.');
        }

        $actorId = $remote?->objectAuthorId;
        if ($actorId === null || $actorId === '') {
            $actorId = $this->liveActorId($target);
        }
        if ($actorId === null || $actorId === '') {
            throw new RemoteReplyException(404, 'remote_post_not_found', 'This post is no longer available.');
        }

        // A block is local policy: the post stays readable only through an
        // explicit filter, so a reply must not reach the blocked actor.
        try {
            $blocked = (int) $this->db->fetchValue(
                'SELECT COUNT(*) FROM social_block WHERE local_user_id = :user AND actor_id = :actor',
                ['user' => $userId, 'actor' => $actorId],
            );
        } catch (DatabaseException) {
            $blocked = 0;
        }
        if ($blocked > 0) {
            throw new RemoteReplyException(403, 'blocked_actor', 'This actor is blocked.');
        }

        $inbox = $this->storedInbox($userId, $actorId);
        if ($inbox === null) {
            try {
                $inbox = $this->directory->resolveActorInbox($actorId);
            } catch (RemoteDirectoryException $exception) {
                throw new RemoteReplyException(502, 'remote_reply_unreachable', $exception->getMessage());
            }
        }

        return $this->db->transaction(function () use (
            $userId,
            $target,
            $content,
            $visibility,
            $contentWarning,
            $attachmentIds,
            $quotedPostId,
            $language,
            $actorId,
            $inbox,
        ): Post {
            $post = $this->posts->create(new CreatePost(
                authorId: $userId,
                content: $content,
                visibility: $visibility,
                contentWarning: $contentWarning,
                attachmentIds: $attachmentIds,
                inReplyToId: null,
                quotedPostId: $quotedPostId,
                language: $language,
            ));

            $this->db->execute(
                'UPDATE social_post SET in_reply_to_id = :target WHERE id = :id',
                ['target' => $target, 'id' => $post->id],
            );

            $noteUrl = $this->activities->noteIdFor($post->id);
            [$activity, $queuedInboxes] = $this->retargetDeliveries($userId, $noteUrl, $target, $actorId);
            if ($activity === null) {
                $activity = $this->activities->createNote($post);
                $activity['object']['inReplyTo'] = $target;
                $activity = $this->withParentAudience($activity, $actorId);
            }

            // The core may already have queued the same inbox because the
            // parent author follows this account. Delivering twice is only a
            // wasted request, but skipping the duplicate keeps the queue clean.
            if (!in_array($inbox, $queuedInboxes, true)) {
                $this->queue->push(JobType::FEDERATION_DELIVER, [
                    'inbox' => $inbox,
                    'activity' => $activity,
                    'key_id' => $this->activities->keyId($userId),
                ], subject: JobSubject::account($userId));
            }

            return $this->posts->get($post->id);
        });
    }

    /**
     * Rewrites every pending Create for this post so peers see the remote
     * parent instead of a top-level note. Returns the rewritten activity and
     * the inboxes the core already queued.
     *
     * @return array{0: ?array<string, mixed>, 1: list<string>}
     */
    private function retargetDeliveries(
        string $userId,
        string $noteUrl,
        string $target,
        string $actorId,
    ): array {
        $rows = $this->db->fetchAll(
            'SELECT id, payload FROM social_job'
            . ' WHERE type = :type AND dead_at IS NULL'
            . ' AND subject_type = :subject_type AND subject_id = :subject_id',
            [
                'type' => JobType::FEDERATION_DELIVER,
                'subject_type' => JobSubject::account($userId)->type,
                'subject_id' => $userId,
            ],
        );

        $activity = null;
        $inboxes = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload'], true);
            if (!is_array($payload) || !is_array($payload['activity'] ?? null)) {
                continue;
            }

            $queued = $payload['activity'];
            $object = $queued['object'] ?? null;
            if (!is_array($object) || ($object['id'] ?? null) !== $noteUrl) {
                continue;
            }

            if (is_string($payload['inbox'] ?? null)) {
                $inboxes[] = $payload['inbox'];
            }

            $object['inReplyTo'] = $target;
            $queued['object'] = $object;
            $queued = $this->withParentAudience($queued, $actorId);
            $payload['activity'] = $queued;

            $this->db->execute(
                'UPDATE social_job SET payload = :payload WHERE id = :id',
                [
                    'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'id' => (string) $row['id'],
                ],
            );

            $activity ??= $queued;
        }

        return [$activity, array_values(array_unique($inboxes))];
    }

    /**
     * Adds the parent author to the audience so a followers-only reply still
     * reaches the person it answers and every peer can thread it.
     *
     * @param array<string, mixed> $activity
     *
     * @return array<string, mixed>
     */
    private function withParentAudience(array $activity, string $actorId): array
    {
        $cc = is_array($activity['cc'] ?? null) ? array_values($activity['cc']) : [];
        if (!in_array($actorId, $cc, true)) {
            $cc[] = $actorId;
        }
        $activity['cc'] = $cc;

        if (is_array($activity['object'] ?? null)) {
            $activity['object']['cc'] = $cc;
        }

        return $activity;
    }

    /**
     * Reads the author of a post that has not reached the local cache yet.
     * The federated timeline stores what it imported, but a reply may start
     * from a search result or a direct post URL.
     */
    private function liveActorId(string $target): ?string
    {
        try {
            $thread = $this->directory->remotePost($target);
        } catch (\Throwable) {
            return null;
        }

        $post = is_array($thread['post'] ?? null) ? $thread['post'] : null;
        if ($post === null) {
            return null;
        }

        foreach (['author_id', 'object_author_id'] as $key) {
            $value = $post[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $profile = is_array($thread['profile'] ?? null) ? $thread['profile'] : [];
        $id = $profile['id'] ?? null;

        return is_string($id) && trim($id) !== '' ? trim($id) : null;
    }

    private function storedInbox(string $userId, string $actorId): ?string
    {
        try {
            $inbox = $this->db->fetchValue(
                'SELECT inbox_url FROM social_following'
                . ' WHERE local_user_id = :user AND actor_id = :actor AND state = :state'
                . ' LIMIT 1',
                ['user' => $userId, 'actor' => $actorId, 'state' => 'accepted'],
            );
        } catch (DatabaseException) {
            return null;
        }

        return is_string($inbox) && trim($inbox) !== '' ? trim($inbox) : null;
    }
}
