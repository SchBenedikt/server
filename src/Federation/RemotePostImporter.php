<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Federation;

use AlohaSocial\Core\Domain\Federation\PdoRemotePostRepository;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use Throwable;

use function filter_var;
use function in_array;
use function is_array;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function preg_match;
use function trim;

use const FILTER_VALIDATE_URL;

/**
 * Fills the remote-post cache for a post the reader found live.
 *
 * The federated timeline and the home timeline only show cached posts, but
 * search and remote profiles read an actor's outbox directly. A like or reply
 * on such a card must not answer 404 just because the post has not been
 * imported yet: the interaction resolves the public object once, stores the
 * same row an inbound Create would have stored, and continues. A failure to
 * reach the origin leaves the post uncached, so the caller keeps its normal
 * not-found answer.
 */
final class RemotePostImporter
{
    private readonly PdoRemotePostRepository $posts;

    public function __construct(
        private readonly Connection $db,
        private readonly RemoteDirectory $directory,
        private readonly Clock $clock,
    ) {
        $this->posts = new PdoRemotePostRepository($db);
    }

    /**
     * Returns the cached post, importing it from its origin when necessary.
     *
     * Only a public HTTP(S) object is imported. AT records have their own
     * identity and interaction path, and a non-URL id never reaches the cache.
     */
    public function ensure(string $objectId): ?RemotePost
    {
        $objectId = trim($objectId);
        if ($objectId === ''
            || mb_strlen($objectId) > RemotePost::MAX_ID_LENGTH
            || preg_match('#^https?://#i', $objectId) !== 1
            || filter_var($objectId, FILTER_VALIDATE_URL) === false
        ) {
            return null;
        }

        $cached = $this->posts->findPostByObjectId($objectId);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $data = $this->directory->remoteObject($objectId);
        } catch (RemoteDirectoryException $exception) {
            // An authorized-fetch refusal or a broken origin is not the same
            // as a post that does not exist. Keep the peer's status and
            // message so the like or reply can report why it failed.
            throw $exception;
        } catch (Throwable) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        // The interaction cache feeds public timelines. A followers-only
        // object that an origin happens to serve unsigned must not enter it.
        $visibility = is_string($data['visibility'] ?? null) ? $data['visibility'] : 'public';
        if (!in_array($visibility, ['public', 'unlisted'], true)) {
            return null;
        }

        $author = is_string($data['author_id'] ?? null) ? trim($data['author_id']) : '';
        if ($author === ''
            || mb_strlen($author) > RemotePost::MAX_ACTOR_LENGTH
            || preg_match('#^https?://#i', $author) !== 1
        ) {
            return null;
        }

        $content = is_string($data['content'] ?? null) ? $data['content'] : '';
        $warning = is_string($data['content_warning'] ?? null) ? trim($data['content_warning']) : null;
        if ($warning === '') {
            $warning = null;
        }
        if ($warning !== null && mb_strlen($warning) > RemotePost::MAX_WARNING_LENGTH) {
            $warning = mb_substr($warning, 0, RemotePost::MAX_WARNING_LENGTH);
        }

        $publishedAt = $this->clock->now();
        if (is_string($data['published_at'] ?? null) && trim($data['published_at']) !== '') {
            try {
                $publishedAt = Time::parse(trim($data['published_at']));
            } catch (Throwable) {
                // Keep the local receive time for a malformed origin date.
            }
        }

        $inReplyTo = is_string($data['in_reply_to_id'] ?? null) ? trim($data['in_reply_to_id']) : '';
        $post = new RemotePost(
            activityId: $objectId,
            objectId: $objectId,
            actorId: $author,
            kind: RemotePostKind::Post,
            content: $content,
            contentWarning: $warning,
            publishedAt: $publishedAt,
            objectAuthorId: $author,
            inReplyToId: $inReplyTo !== '' ? $inReplyTo : null,
        );

        try {
            $this->posts->insert($post);
        } catch (DatabaseException) {
            // Another request imported the same object first. Its row is the
            // one the reaction service has to use.
            return $this->posts->findPostByObjectId($objectId);
        }

        return $post;
    }
}
