<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Federation\Atproto;

use AlohaSocial\Core\Domain\Atproto\AtprotoFollow;
use AlohaSocial\Core\Domain\Atproto\AtprotoFollowRepository;
use AlohaSocial\Core\Domain\Atproto\AtprotoInboundRepository;
use AlohaSocial\Core\Domain\Atproto\AtprotoInteractionTargetPolicy;
use AlohaSocial\Core\Domain\Atproto\AtprotoPostProjection;
use AlohaSocial\Core\Domain\Federation\BlockRepository;

/** Enforces follow, current-record, account-state and block policy for AT posts. */
final class RemoteInteractionTargetPolicy implements AtprotoInteractionTargetPolicy
{
	public function __construct(
		private readonly AtprotoInboundRepository $records,
		private readonly AtprotoFollowRepository $follows,
		private readonly BlockRepository $blocks,
		private readonly string $source = JetstreamEventSource::NAME,
	) {
	}

	public function allows(string $localUserId, AtprotoPostProjection $target): bool
	{
		$follow = $this->follows->findForSubject($localUserId, $target->did);
		if ($follow === null || $follow->state !== AtprotoFollow::ACTIVE) {
			return false;
		}

		$record = $this->records->record($this->source, $target->uri);
		if ($record === null || !$record->isLive() || $record->cid !== $target->cid
			|| $record->did !== $target->did || !$record->isPost()
		) {
			return false;
		}

		$account = $this->records->account($this->source, $target->did);
		if ($account !== null && !$account->active) {
			return false;
		}

		foreach ($this->blocks->actorIds($localUserId) as $actorId) {
			if ($this->isBlockedDid($actorId, $target->did, $target->handle)) {
				return false;
			}
		}

		return true;
	}

	private function isBlockedDid(string $actorId, string $did, ?string $handle): bool
	{
		$parts = parse_url($actorId);
		if (!is_array($parts)
			|| !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
			|| !in_array(strtolower((string) ($parts['host'] ?? '')), ['bsky.app', 'bsky.social'], true)
		) {
			return false;
		}

		$path = trim((string) ($parts['path'] ?? ''), '/');
		if (!str_starts_with($path, 'profile/')) {
			return false;
		}

		$blockedIdentity = rawurldecode(substr($path, strlen('profile/')));

		return hash_equals($did, $blockedIdentity)
			|| ($handle !== null && strcasecmp($handle, $blockedIdentity) === 0);
	}
}
