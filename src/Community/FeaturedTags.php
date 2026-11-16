<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Community;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;

use function array_slice;
use function array_unique;
use function array_values;
use function mb_strlen;
use function preg_replace;
use function strtolower;
use function trim;

/**
 * Hashtags an account pins to its profile.
 *
 * This is a profile decoration, not a follow: the tag is only a link to the
 * existing hashtag timeline. Featured tags are public and capped so a profile
 * stays readable.
 */
final class FeaturedTags
{
    public const MAX_TAGS = 10;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<array{tag: string, position: int}>
     */
    public function forAccount(string $accountId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT tag, position FROM featured_tag WHERE account_id = :account_id ORDER BY position ASC, tag ASC',
            ['account_id' => $accountId],
        );

        return array_map(
            static fn (array $row): array => [
                'tag' => (string) $row['tag'],
                'position' => (int) $row['position'],
            ],
            $rows,
        );
    }

    /**
     * Replaces the complete list, preserving the submitted order.
     *
     * Sending an empty list clears the profile. Unknown characters are
     * stripped instead of rejecting the whole request, which matches how a
     * hashtag is tokenised in a post.
     *
     * @param list<mixed> $tags
     *
     * @return list<array{tag: string, position: int}>
     */
    public function replace(string $accountId, array $tags): array
    {
        $normalized = [];
        foreach ($tags as $value) {
            $tag = $this->normalize($value);
            if ($tag === '' || isset($normalized[$tag])) {
                continue;
            }
            $normalized[$tag] = true;
            if (count($normalized) >= self::MAX_TAGS) {
                break;
            }
        }

        $this->db->transaction(function () use ($accountId, $normalized): void {
            $this->db->execute('DELETE FROM featured_tag WHERE account_id = :account_id', ['account_id' => $accountId]);

            $position = 0;
            foreach (array_keys($normalized) as $tag) {
                $this->db->execute(
                    'INSERT INTO featured_tag (account_id, tag, position, created_at)'
                    . ' VALUES (:account_id, :tag, :position, :created_at)',
                    [
                        'account_id' => $accountId,
                        'tag' => $tag,
                        'position' => $position,
                        'created_at' => Time::format($this->clock->now()),
                    ],
                );
                $position++;
            }
        });

        return $this->forAccount($accountId);
    }

    public function normalize(mixed $value): string
    {
        $tag = is_string($value) ? trim($value) : '';
        $tag = ltrim($tag, '#');
        $tag = preg_replace('/[^\p{L}\p{N}_]+/u', '', $tag) ?? '';
        $tag = strtolower($tag);
        if (mb_strlen($tag) > 100) {
            return '';
        }

        return $tag;
    }
}
