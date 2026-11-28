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

use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function mb_strlen;
use function min;
use function trim;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

/**
 * Unsent compositions, one per account and context.
 *
 * The standalone host keeps drafts on the server instead of in browser
 * storage so they follow the account to another device and survive a cleared
 * cache. A draft never holds uploaded media itself: it keeps the attachment
 * ids the upload step already produced, exactly like a scheduled post.
 *
 * The context is what the composer is answering: '' for a post of its own,
 * otherwise the reply or quote target. Saving twice for the same context
 * replaces the older draft; the list therefore has one row per conversation.
 */
final class Drafts
{
    public const MAX_CONTENT = 5000;

    public const MAX_WARNING = 200;

    public const MAX_ATTACHMENTS = 4;

    public const MAX_POLL_OPTIONS = 4;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forAccount(string $accountId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM draft WHERE account_id = :account_id ORDER BY updated_at DESC, id DESC LIMIT 100',
            ['account_id' => $accountId],
        );

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id, string $accountId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM draft WHERE id = :id AND account_id = :account_id',
            ['id' => $id, 'account_id' => $accountId],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Creates or replaces the draft for one context.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function save(string $accountId, array $payload): array
    {
        $draft = $this->normalize($payload);
        $now = Time::format($this->clock->now());
        $existing = $this->db->fetchOne(
            'SELECT id, created_at FROM draft WHERE account_id = :account_id AND context = :context',
            ['account_id' => $accountId, 'context' => $draft['context']],
        );

        if ($existing === null) {
            $id = bin2hex(random_bytes(12));
            $this->db->execute(
                'INSERT INTO draft (id, account_id, context, content, content_warning, visibility, language, attachment_ids,'
                . ' in_reply_to_id, quoted_post_id, poll_options, poll_multiple, poll_hide_totals, poll_expires_in,'
                . ' created_at, updated_at)'
                . ' VALUES (:id, :account_id, :context, :content, :content_warning, :visibility, :language, :attachment_ids,'
                . ' :in_reply_to_id, :quoted_post_id, :poll_options, :poll_multiple, :poll_hide_totals, :poll_expires_in,'
                . ' :created_at, :updated_at)',
                $this->parameters($id, $accountId, $draft, $now) + ['created_at' => $now],
            );
        } else {
            $id = (string) $existing['id'];
            $this->db->execute(
                'UPDATE draft SET context = :context, content = :content, content_warning = :content_warning,'
                . ' visibility = :visibility, language = :language, attachment_ids = :attachment_ids, in_reply_to_id = :in_reply_to_id,'
                . ' quoted_post_id = :quoted_post_id, poll_options = :poll_options, poll_multiple = :poll_multiple,'
                . ' poll_hide_totals = :poll_hide_totals, poll_expires_in = :poll_expires_in, updated_at = :updated_at'
                . ' WHERE id = :id AND account_id = :account_id',
                $this->parameters($id, $accountId, $draft, $now),
            );
        }

        return $this->find($id, $accountId)
            ?? throw CommunityException::notFound('The draft was not saved.');
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $id, string $accountId): array
    {
        $draft = $this->find($id, $accountId);
        if ($draft === null) {
            throw CommunityException::notFound('This draft no longer exists.');
        }

        $this->db->execute(
            'DELETE FROM draft WHERE id = :id AND account_id = :account_id',
            ['id' => $id, 'account_id' => $accountId],
        );

        return $draft;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function normalize(array $payload): array
    {
        $content = trim(is_string($payload['content'] ?? null) ? $payload['content'] : '');
        $warning = trim(is_string($payload['content_warning'] ?? null) ? $payload['content_warning'] : '');
        $visibility = is_string($payload['visibility'] ?? null) ? $payload['visibility'] : 'public';
        $context = trim(is_string($payload['context'] ?? null) ? $payload['context'] : '');
        $language = is_string($payload['language'] ?? null) ? trim($payload['language']) : '';

        $attachmentIds = $this->stringList($payload['attachment_ids'] ?? [], self::MAX_ATTACHMENTS);
        $poll = $this->poll($payload['poll'] ?? null);

        if (mb_strlen($content) > self::MAX_CONTENT) {
            throw CommunityException::invalid('The draft is too long.', ['content' => 'At most ' . self::MAX_CONTENT . ' characters.']);
        }
        if (mb_strlen($warning) > self::MAX_WARNING) {
            throw CommunityException::invalid('The content warning is too long.', ['content_warning' => 'At most ' . self::MAX_WARNING . ' characters.']);
        }
        if ($context !== '' && mb_strlen($context) > 200) {
            throw CommunityException::invalid('The context is too long.', ['context' => 'At most 200 characters.']);
        }
        if (!in_array($visibility, ['public', 'unlisted', 'followers'], true)) {
            throw CommunityException::invalid('This visibility is invalid.', ['visibility' => 'Please choose public, unlisted or followers.']);
        }
        if (mb_strlen($language) > 35) {
            throw CommunityException::invalid('The language tag is too long.', ['language' => 'At most 35 characters.']);
        }
        if ($content === '' && $attachmentIds === [] && $poll === null) {
            throw CommunityException::invalid('The draft is empty.', ['content' => 'Write something or attach an image.']);
        }

        return [
            'context' => $context,
            'content' => $content,
            'content_warning' => $warning === '' ? null : $warning,
            'visibility' => $visibility,
            'language' => $language === '' ? null : $language,
            'attachment_ids' => $attachmentIds,
            'in_reply_to_id' => $this->optionalId($payload['in_reply_to_id'] ?? null),
            'quoted_post_id' => $this->optionalId($payload['quoted_post_id'] ?? null),
            'poll' => $poll,
        ];
    }

    /**
     * @return array{options: list<string>, multiple: bool, hide_totals: bool, expires_in: int}|null
     */
    private function poll(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_array($value)) {
            throw CommunityException::invalid('The poll is invalid.', ['poll' => 'The poll must be an object.']);
        }

        $options = $this->stringList($value['options'] ?? [], self::MAX_POLL_OPTIONS);
        if (count($options) < 2) {
            throw CommunityException::invalid('A poll needs at least two options.', ['poll' => 'At least two options.']);
        }
        foreach ($options as $option) {
            if (mb_strlen($option) > 100) {
                throw CommunityException::invalid('An option is too long.', ['poll' => 'At most 100 characters per option.']);
            }
        }

        $expiresIn = is_numeric($value['expires_in'] ?? null) ? (int) $value['expires_in'] : 86_400;
        if ($expiresIn < 300 || $expiresIn > 604_800) {
            throw CommunityException::invalid('The poll duration is invalid.', ['poll' => 'Between 5 minutes and 7 days.']);
        }

        return [
            'options' => $options,
            'multiple' => $value['multiple'] === true,
            'hide_totals' => $value['hide_totals'] === true,
            'expires_in' => $expiresIn,
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value, int $max): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $entry) {
            $item = is_string($entry) ? trim($entry) : '';
            if ($item === '' || mb_strlen($item) > 100 || in_array($item, $result, true)) {
                continue;
            }
            $result[] = $item;
            if (count($result) >= $max) {
                break;
            }
        }

        return $result;
    }

    private function optionalId(mixed $value): ?string
    {
        $id = is_string($value) ? trim($value) : '';

        return $id === '' || mb_strlen($id) > 64 ? null : $id;
    }

    /**
     * @param array<string, mixed> $draft
     *
     * @return array<string, mixed>
     */
    private function parameters(string $id, string $accountId, array $draft, string $updatedAt): array
    {
        $poll = $draft['poll'];

        return [
            'id' => $id,
            'account_id' => $accountId,
            'context' => $draft['context'],
            'content' => $draft['content'],
            'content_warning' => $draft['content_warning'],
            'visibility' => $draft['visibility'],
            'language' => $draft['language'],
            'attachment_ids' => json_encode($draft['attachment_ids'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'in_reply_to_id' => $draft['in_reply_to_id'],
            'quoted_post_id' => $draft['quoted_post_id'],
            'poll_options' => $poll === null ? null : json_encode($poll['options'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'poll_multiple' => $poll !== null && $poll['multiple'] ? 1 : 0,
            'poll_hide_totals' => $poll !== null && $poll['hide_totals'] ? 1 : 0,
            'poll_expires_in' => $poll === null ? null : $poll['expires_in'],
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $attachments = json_decode(is_string($row['attachment_ids'] ?? null) ? $row['attachment_ids'] : '[]', true);
        $pollOptions = json_decode(is_string($row['poll_options'] ?? null) ? $row['poll_options'] : 'null', true);

        return [
            'id' => (string) $row['id'],
            'account_id' => (string) $row['account_id'],
            'context' => (string) ($row['context'] ?? ''),
            'content' => (string) $row['content'],
            'content_warning' => is_string($row['content_warning'] ?? null) ? $row['content_warning'] : null,
            'visibility' => (string) $row['visibility'],
            'language' => is_string($row['language'] ?? null) ? $row['language'] : null,
            'attachment_ids' => is_array($attachments) ? array_values(array_filter($attachments, 'is_string')) : [],
            'in_reply_to_id' => is_string($row['in_reply_to_id'] ?? null) ? $row['in_reply_to_id'] : null,
            'quoted_post_id' => is_string($row['quoted_post_id'] ?? null) ? $row['quoted_post_id'] : null,
            'poll' => is_array($pollOptions) ? [
                'options' => array_values(array_filter($pollOptions, 'is_string')),
                'multiple' => (bool) ($row['poll_multiple'] ?? false),
                'hide_totals' => (bool) ($row['poll_hide_totals'] ?? false),
                'expires_in' => (int) ($row['poll_expires_in'] ?? 86_400),
            ] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
