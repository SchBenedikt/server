<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Federation;

use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;

/** Owner-scoped status and manual retry for a post's pending AP deliveries. */
final readonly class ActivityPubDeliveryStatus
{
	public function __construct(
		private Connection $db,
		private ActivityFactory $activities,
		private Clock $clock,
	) {
	}

	/** @return array{state: string, pending: int, failed: int, attempts: int, retryable: bool} */
	public function forPost(string $userId, string $postId): array
	{
		$pending = 0;
		$failed = 0;
		$attempts = 0;
		$retryable = false;
		foreach ($this->matchingJobs($userId, $postId) as $job) {
			$attempts += max(0, (int) ($job['attempts'] ?? 0));
			if (is_string($job['dead_at'] ?? null) && $job['dead_at'] !== '') {
				++$failed;
				$retryable = $retryable || $this->isRetryableDelivery($job);
			} else {
				++$pending;
			}
		}

		return [
			'state' => $failed > 0 ? 'failed' : ($pending > 0 ? 'pending' : 'clear'),
			'pending' => $pending,
			'failed' => $failed,
			'attempts' => $attempts,
			'retryable' => $retryable,
		];
	}

	/** Retry only dead jobs whose immutable payload names this owner's post. */
	public function retry(string $userId, string $postId): int
	{
		$now = \AlohaSocial\Core\Support\Time::format($this->clock->now());

		return $this->db->transaction(function (Connection $db) use ($userId, $postId, $now): int {
			$retried = 0;
			foreach ($this->matchingJobs($userId, $postId, $db) as $job) {
				if (!is_string($job['dead_at'] ?? null) || $job['dead_at'] === '' || !$this->isRetryableDelivery($job)) {
					continue;
				}
				$retried += $db->execute(
					'UPDATE social_job SET attempts = 0, dead_at = NULL, reserved_at = NULL, available_at = :now'
					. ' WHERE id = :id AND type = :type AND subject_type = :subject_type AND subject_id = :user_id'
					. ' AND dead_at IS NOT NULL',
					[
						'now' => $now,
					'id' => (string) $job['id'],
					'type' => JobType::FEDERATION_DELIVER,
					'subject_type' => 'account',
					'user_id' => $userId,
					],
				);
			}

			return $retried;
		});
	}

	/** @return list<array<string, mixed>> */
	private function matchingJobs(string $userId, string $postId, ?Connection $db = null): array
	{
		$database = $db ?? $this->db;
		$noteId = $this->activities->noteIdFor($postId);
		$matching = [];
		$cursorTime = null;
		$cursorId = null;
		do {
			$sql = 'SELECT id, payload, attempts, dead_at, available_at FROM social_job'
				. ' WHERE type = :type AND subject_type = :subject_type AND subject_id = :user_id';
			$params = [
				'type' => JobType::FEDERATION_DELIVER,
				'subject_type' => 'account',
				'user_id' => $userId,
			];
			if ($cursorTime !== null && $cursorId !== null) {
				$sql .= ' AND (available_at < :cursor_time_before OR (available_at = :cursor_time_equal AND id < :cursor_id))';
				$params['cursor_time_before'] = $cursorTime;
				$params['cursor_time_equal'] = $cursorTime;
				$params['cursor_id'] = $cursorId;
			}
			$sql .= ' ORDER BY available_at DESC, id DESC LIMIT 250';
			$rows = $database->fetchAll($sql, $params);
			foreach ($rows as $row) {
				$payload = json_decode((string) ($row['payload'] ?? ''), true);
				$activity = is_array($payload) && is_array($payload['activity'] ?? null) ? $payload['activity'] : null;
				$object = $activity['object'] ?? null;
				$objectId = is_array($object) ? ($object['id'] ?? null) : $object;
				if (is_string($objectId) && hash_equals($noteId, $objectId)) {
					$matching[] = $row;
				}
			}
			$last = $rows === [] ? null : $rows[array_key_last($rows)];
			$cursorTime = is_array($last) ? (string) $last['available_at'] : null;
			$cursorId = is_array($last) ? (string) $last['id'] : null;
		} while (count($rows) === 250);

		return $matching;
	}

	/** @param array<string, mixed> $job */
	private function isRetryableDelivery(array $job): bool
	{
		$payload = json_decode((string) ($job['payload'] ?? ''), true);
		if (!is_array($payload) || !is_array($payload['activity'] ?? null)) {
			return false;
		}
		$inbox = $payload['inbox'] ?? null;
		$keyId = $payload['key_id'] ?? null;

		return is_string($inbox)
			&& filter_var($inbox, FILTER_VALIDATE_URL) !== false
			&& preg_match('#^https?://#i', $inbox) === 1
			&& is_string($keyId)
			&& $keyId !== '';
	}
}
