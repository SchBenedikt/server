<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Interactions;

use AlohaSocial\Core\Domain\Atproto\AtprotoInboundRepository;
use AlohaSocial\Core\Domain\Atproto\AtprotoInteractionTargetPolicy;
use AlohaSocial\Core\Domain\Atproto\AtprotoPostProjection;
use AlohaSocial\Core\Domain\Atproto\AtprotoIdentifiers;
use AlohaSocial\Core\Domain\Atproto\AtprotoInteractionRecord;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Service\Atproto\AtprotoInteractionRejectedException;
use AlohaSocial\Core\Service\Atproto\AtprotoInteractionService;

/** Bridges feed record URIs to the core's outbound AT interaction service. */
final class AtprotoRemoteInteractions
{
	public function __construct(
		private readonly Connection $db,
		private readonly AtprotoInboundRepository $records,
		private readonly AtprotoInteractionService $interactions,
		private readonly AtprotoInteractionTargetPolicy $policy,
		private readonly string $source = 'jetstream',
	) {
	}

	public static function supports(string $uri): bool
	{
		return AtprotoIdentifiers::isAtUri($uri);
	}

	/** @return array{favourites_count: int, favourited: bool, reblogs_count: int, reblogged: bool, replies_count: int, emoji_reactions: list<array{emoji: string, count: int}>} */
	public function forUser(string $userId, string $uri): array
	{
		$target = $this->target($uri);
		if (!$this->policy->allows($userId, $target)) {
			throw new AtprotoInteractionRejectedException(AtprotoInteractionRejectedException::BLOCKED_ACTOR);
		}

		$likes = $this->count($userId, $target->uri, AtprotoInteractionRecord::LIKE) > 0;
		$reposts = $this->count($userId, $target->uri, AtprotoInteractionRecord::REPOST) > 0;

		return [
			'favourites_count' => 0,
			'favourited' => $likes,
			'reblogs_count' => 0,
			'reblogged' => $reposts,
			'replies_count' => 0,
			'emoji_reactions' => [],
		];
	}

	/** @return array{interaction: array<string, mixed>} */
	public function change(string $userId, string $uri, string $kind, bool $enabled, ?string $accountDid = null): array
	{
		$target = $this->target($uri);
		if ($kind === AtprotoInteractionRecord::LIKE) {
			if ($enabled) {
				$this->interactions->likeRemote($userId, $target, $accountDid);
			} else {
				$this->interactions->unlikeRemote($userId, $target, $accountDid);
			}
		} elseif ($enabled) {
			$this->interactions->repostRemote($userId, $target, $accountDid);
		} else {
			$this->interactions->undoRemoteRepost($userId, $target, $accountDid);
		}

		return ['interaction' => $this->snapshot($userId, $uri)];
	}

	private function target(string $uri): AtprotoPostProjection
	{
		$record = $this->records->record($this->source, $uri);
		$handle = $record === null ? null : $this->records->account($this->source, $record->did)?->handle;
		$target = $record === null ? null : AtprotoPostProjection::fromRecord($record, null, $handle);
		if ($target === null) {
			throw new AtprotoInteractionRejectedException(AtprotoInteractionRejectedException::SUBJECT_UNRESOLVABLE);
		}

		return $target;
	}

	private function count(string $userId, string $uri, string $kind): int
	{
		$value = $this->db->fetchValue(
			' SELECT COUNT(*) FROM social_atproto_interaction'
			. ' WHERE local_user_id = :user AND post_id = :post AND kind = :kind',
			['user' => $userId, 'post' => hash('sha256', $uri), 'kind' => $kind],
		);

		return (int) $value;
	}

	/** @return array{favourites_count: int, favourited: bool, reblogs_count: int, reblogged: bool, replies_count: int, emoji_reactions: list<array{emoji: string, count: int}>} */
	private function snapshot(string $userId, string $uri): array
	{
		return [
			'favourites_count' => 0,
			'favourited' => $this->count($userId, $uri, AtprotoInteractionRecord::LIKE) > 0,
			'reblogs_count' => 0,
			'reblogged' => $this->count($userId, $uri, AtprotoInteractionRecord::REPOST) > 0,
			'replies_count' => 0,
			'emoji_reactions' => [],
		];
	}
}
