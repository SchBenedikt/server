<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Federation\Atproto;

use AlohaSocial\Core\Domain\Atproto\AtprotoPublication;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;

/** Reads the durable delivery state of one local post for one AT Protocol DID. */
final class AtprotoPublicationDeliveryStatus
{
	public const PUBLISHED = 'published';

	public const PENDING = 'pending';

	public const FAILED = 'failed';

	public const NOT_QUEUED = 'not_queued';

	public function __construct(
		private readonly Connection $db,
	) {
	}

	/**
	 * @return array{state: string, publication: array<string, mixed>|null, attempts: int, retryable: bool}
	 */
	public function forPost(
		string $userId,
		string $postId,
		string $accountDid,
		?string $defaultAccountDid,
		?AtprotoPublication $publication,
	): array {
		$jobs = $this->db->fetchAll(
			' SELECT payload, attempts, available_at, dead_at FROM social_job'
			. ' WHERE type = :type AND subject_type = :subject_type AND subject_id = :user_id'
			. ' ORDER BY CASE WHEN dead_at IS NULL THEN available_at ELSE dead_at END DESC, id DESC LIMIT 1000',
			[
				'type' => JobType::ATPROTO_PUBLISH,
				'subject_type' => 'account',
				'user_id' => $userId,
			],
		);

		foreach ($jobs as $job) {
			$payload = json_decode((string) ($job['payload'] ?? ''), true);
			if (!is_array($payload)
				|| ($payload['user_id'] ?? null) !== $userId
				|| ($payload['post_id'] ?? null) !== $postId
			) {
				continue;
			}

			$jobAccountDid = $payload['account_did'] ?? null;
			if (is_string($jobAccountDid)) {
				if ($jobAccountDid !== $accountDid) {
					continue;
				}
			} elseif ($defaultAccountDid !== $accountDid) {
				// Jobs queued by older Core versions did not snapshot the DID. They
				// can only be attributed to the currently selected default identity.
				continue;
			}
			$jobTime = is_string($job['dead_at'] ?? null) && $job['dead_at'] !== ''
				? $job['dead_at']
				: (string) ($job['available_at'] ?? '');
			if ($publication !== null && $jobTime !== '' && $jobTime <= Time::format($publication->publishedAt)) {
				// A later manual retry may have succeeded while the old dead letter
				// remains in the queue for operator review. It is no longer the
				// current delivery state once a newer record was confirmed.
				continue;
			}

			$failed = is_string($job['dead_at'] ?? null) && $job['dead_at'] !== '';

			return [
				'state' => $failed ? self::FAILED : self::PENDING,
				'publication' => $publication?->toArray(),
				'attempts' => max(0, (int) ($job['attempts'] ?? 0)),
				'retryable' => $failed,
			];
		}

		return [
			'state' => $publication === null ? self::NOT_QUEUED : self::PUBLISHED,
			'publication' => $publication?->toArray(),
			'attempts' => 0,
			'retryable' => false,
		];
	}
}
