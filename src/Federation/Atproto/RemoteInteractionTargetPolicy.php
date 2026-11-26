<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Federation\Atproto;

use AlohaSocial\Core\Domain\Atproto\AtprotoInboundRepository;
use AlohaSocial\Core\Domain\Atproto\AtprotoInteractionTargetPolicy;
use AlohaSocial\Core\Domain\Atproto\AtprotoPostProjection;
use AlohaSocial\Core\Domain\Federation\BlockRepository;

/** Enforces current-record, account-state and block policy for AT posts. */
final class RemoteInteractionTargetPolicy implements AtprotoInteractionTargetPolicy
{
	public function __construct(
		private readonly AtprotoInboundRepository $records,
		private readonly BlockRepository $blocks,
		private readonly string $source = JetstreamEventSource::NAME,
	) {
	}

	/**
	 * A public AT Protocol post may be liked from discovery without following
	 * its author, exactly like a public ActivityPub post. The record must still
	 * be live and unchanged in the ingest store, its account must not be
	 * deactivated, and a local block always wins.
	 */
	public function allows(string $localUserId, AtprotoPostProjection $target): bool
	{
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
