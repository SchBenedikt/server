<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

/**
 * Export is the user-facing proof that a feature's data actually belongs to
 * the account. Pins and archives now live in core state; the implicit For You
 * impressions were previously deleted on erasure but missing from the export.
 */
final class ExportConsolidationTest extends TestCase
{
    public function testExportContainsCorePinsArchiveAndImpressions(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test');
        $login = $server->signIn('alice');

        $pinned = $server->runtime->app->posts
            ->create(new CreatePost('alice', 'Pinned post', Visibility::Public))
            ->id;
        $archived = $server->runtime->app->posts
            ->create(new CreatePost('alice', 'Archived post', Visibility::Public))
            ->id;

        $server->runtime->pinnedPosts->pin('alice', $pinned);
        $server->runtime->archives->archive('alice', $archived);
        $server->runtime->database->execute(
            'INSERT INTO recommendation_impression'
            . ' (account_id, post_id, author_id, seen_at, dwell_ms, times)'
            . ' VALUES (:account, :post, :author, :seen, 4200, 2)',
            [
                'account' => 'alice',
                'post' => $pinned,
                'author' => 'alice',
                'seen' => '2026-11-25T12:00:00Z',
            ],
        );

        $response = $server->send('GET', '/api/v1/host/export', ['cookies' => $login['cookies']]);
        self::assertSame(200, $response->status, $response->body);

        /** @var array<string, mixed> $export */
        $export = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([$pinned], array_column($export['pinned_posts'], 'post_id'));
        self::assertSame([$archived], array_column($export['archived_posts'], 'post_id'));
        self::assertSame([$pinned], array_column($export['recommendation_impressions'], 'post_id'));
        self::assertSame(4200, (int) $export['recommendation_impressions'][0]['dwell_ms']);
        self::assertSame(2, (int) $export['recommendation_impressions'][0]['times']);

        self::assertSame(
            [$pinned],
            array_map(
                static fn (\AlohaSocial\Core\Domain\Post\Post $post): string => $post->id,
                $server->runtime->app->pins?->featuredPosts('alice') ?? [],
            ),
        );
        self::assertSame(
            [$archived],
            array_map(
                static fn (\AlohaSocial\Core\Domain\Post\Post $post): string => $post->id,
                $server->runtime->app->posts->archived('alice'),
            ),
        );
    }
}
