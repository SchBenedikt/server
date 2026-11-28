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
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Core\Service\ReactionException;
use AlohaSocial\Core\Service\ReactionService;
use AlohaSocial\Core\Service\ValidationException;
use Throwable;

use function is_string;
use function preg_match;
use function trim;

/**
 * Replies to posts that live on another server.
 *
 * The core resolves an absolute `in_reply_to_id` against its stored remote
 * posts, authorizes it with the shared read policy, persists the remote
 * parent edge and delivers the signed Create to the parent author's inbox.
 * This service keeps the host-specific steps around that write: importing a
 * not-yet-cached search result, mapping authorization failures to stable
 * API errors and refusing to publish a reply whose parent inbox cannot be
 * resolved at all.
 */
final class RemoteReplies
{
    public function __construct(
        private readonly Connection $db,
        private readonly PostService $posts,
        private readonly ActivityFactory $activities,
        private readonly RemoteDirectory $directory,
        private readonly ?ReactionService $authorization = null,
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

        // The core policy owns both block directions, the audience and the
        // accepted-follow rule. A policy-store failure is an unavailable
        // authorization service, never permission to write or deliver.
        try {
            $authorized = $this->authorization?->remotePostFor($userId, $target);
        } catch (ReactionException $exception) {
            // A cached row without a stored audience keeps the old follow
            // gate; let the origin prove the object public once before
            // reporting the refusal.
            if ($exception->reason === 'remote_post_not_followed' && $this->importer !== null) {
                $this->importer->refreshAudience($target);

                try {
                    $authorized = $this->authorization?->remotePostFor($userId, $target);
                } catch (ReactionException $retry) {
                    [$status, $code, $message] = $this->replyDenial($retry);

                    throw new RemoteReplyException($status, $code, $message);
                } catch (Throwable) {
                    throw new RemoteReplyException(
                        503,
                        'remote_reply_authorization_unavailable',
                        'Remote reply authorization is temporarily unavailable.',
                    );
                }
            } else {
                [$status, $code, $message] = $this->replyDenial($exception);

                throw new RemoteReplyException($status, $code, $message);
            }
        } catch (Throwable) {
            throw new RemoteReplyException(
                503,
                'authorization_unavailable',
                'Remote interaction authorization is temporarily unavailable.',
            );
        }

        if ($authorized === null) {
            throw new RemoteReplyException(
                503,
                'authorization_unavailable',
                'Remote interaction authorization is temporarily unavailable.',
            );
        }

        $actorId = $authorized->objectAuthorId;
        if ($actorId === null || $actorId === '') {
            $actorId = $authorized->actorId;
        }
        if ($actorId === '') {
            throw new RemoteReplyException(404, 'remote_post_not_found', 'This post is no longer available.');
        }

        $inbox = $this->storedInbox($userId, $actorId);
        if ($inbox === null) {
            try {
                $inbox = $this->directory->resolveActorInbox($actorId);
            } catch (RemoteDirectoryException $exception) {
                throw new RemoteReplyException(502, 'remote_reply_unreachable', $exception->getMessage());
            }
        }

        // The core write resolves the same stored parent, applies the shared
        // audience policy, persists the remote parent edge, emits the
        // canonical inReplyTo with the parent author in cc and queues the
        // delivery to this inbox even when the author has no followers.
        return $this->posts->create(new CreatePost(
            authorId: $userId,
            content: $content,
            visibility: $visibility,
            contentWarning: $contentWarning,
            attachmentIds: $attachmentIds,
            inReplyToId: $target,
            quotedPostId: $quotedPostId,
            language: $language,
        ));
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

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function replyDenial(ReactionException $exception): array
    {
        return match ($exception->reason) {
            'blocked_actor' => [403, 'blocked_actor', $exception->getMessage()],
            'remote_post_not_found', 'remote_post_not_followed' => [
                404,
                'remote_post_not_found',
                'This post is no longer available.',
            ],
            default => [403, 'remote_interaction_not_allowed', 'You may not reply to this post.'],
        };
    }
}
