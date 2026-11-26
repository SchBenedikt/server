<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Links direct-message roots that belong to the same conversation.
 *
 * Older hosts created every direct message without `in_reply_to_id`, so each
 * message became its own conversation root. The inbox groups by
 * `conversation_id`, which made one conversation with Bob appear several
 * times. This migration walks the existing direct posts in chronological
 * order per participant set and links each later root to the previous message,
 * exactly as a reply from the composer does today.
 *
 * The migration is idempotent: only rows with a null parent are changed, and a
 * second run finds no unlinked roots.
 */
final class Version202611280011RepairDirectMessageRoots implements Migration
{
	public function version(): string
	{
		return '202611280011';
	}

	public function description(): string
	{
		return 'Link legacy direct-message roots between the same participants.';
	}

	public function up(Connection $connection): void
	{
		$posts = $connection->fetchAll(
			'SELECT id, author_id, in_reply_to_id, published_at'
			. ' FROM social_post'
			. ' WHERE visibility = :visibility AND deleted_at IS NULL'
			. ' ORDER BY published_at ASC, id ASC',
			['visibility' => 'direct'],
		);
		if ($posts === []) {
			return;
		}

		$recipientRows = $connection->fetchAll(
			'SELECT recipient.post_id, recipient.recipient_id'
			. ' FROM social_post_recipient recipient'
			. ' INNER JOIN social_post post ON post.id = recipient.post_id'
			. ' WHERE post.visibility = :visibility AND post.deleted_at IS NULL',
			['visibility' => 'direct'],
		);
		$recipients = [];
		foreach ($recipientRows as $row) {
			$postId = (string) ($row['post_id'] ?? '');
			$recipientId = (string) ($row['recipient_id'] ?? '');
			if ($postId === '' || $recipientId === '') {
				continue;
			}
			$recipients[$postId][] = $recipientId;
		}

		$groups = [];
		foreach ($posts as $post) {
			$postId = (string) ($post['id'] ?? '');
			$authorId = (string) ($post['author_id'] ?? '');
			if ($postId === '' || $authorId === '') {
				continue;
			}

			$participants = array_values(array_unique(array_merge(
				[$authorId],
				$recipients[$postId] ?? [],
			)));
			sort($participants, SORT_STRING);
			$key = implode("\x1f", $participants);
			$groups[$key][] = $post;
		}

		foreach ($groups as $group) {
			$previousId = null;
			foreach ($group as $post) {
				$postId = (string) ($post['id'] ?? '');
				if ($postId === '') {
					continue;
				}

				if ($previousId !== null && ($post['in_reply_to_id'] ?? null) === null) {
					$connection->execute(
						'UPDATE social_post SET in_reply_to_id = :parent'
						. ' WHERE id = :post AND in_reply_to_id IS NULL',
						['parent' => $previousId, 'post' => $postId],
					);
				}

				$previousId = $postId;
			}
		}
	}

	public function down(Connection $connection): void
	{
		// The original root split cannot be reconstructed reliably: a post that
		// was sent as a reply must stay attached to its conversation.
	}
}
