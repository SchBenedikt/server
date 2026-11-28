<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

/**
 * The import preview is a dry run over an `aloha-account-export` document:
 * it validates the envelope, classifies every section and never writes.
 */
final class ImportsTest extends TestCase
{
    private TestServer $server;

    /** @var array<string, string> */
    private array $cookies;

    private string $csrf;

    protected function setUp(): void
    {
        $this->server = new TestServer();
        $this->server->createAccount('alice', 'alice@example.test', TestServer::PASSWORD, true);
        $login = $this->server->signIn('alice');
        $this->cookies = $login['cookies'];
        $this->csrf = $login['csrf'];
    }

    public function testPreviewRequiresAuthentication(): void
    {
        $response = $this->server->send('POST', '/api/v1/host/import/preview', [], '{"format":"aloha-account-export"}');
        self::assertSame(401, $response->status);
    }

    public function testPreviewRejectsForeignAndUnsupportedDocuments(): void
    {
        $foreign = $this->server->json('POST', '/api/v1/host/import/preview', [
            'format' => 'something-else',
            'version' => 1,
        ], $this->cookies, $this->csrf);
        self::assertSame(422, $foreign->status);

        $future = $this->server->json('POST', '/api/v1/host/import/preview', [
            'format' => 'aloha-account-export',
            'version' => 99,
        ], $this->cookies, $this->csrf);
        self::assertSame(422, $future->status);
    }

    public function testPreviewClassifiesSectionsOfARealExport(): void
    {
        $export = $this->server->send('GET', '/api/v1/host/export', ['cookies' => $this->cookies]);
        self::assertSame(200, $export->status);
        /** @var array<string, mixed> $document */
        $document = json_decode($export->body, true, 512, JSON_THROW_ON_ERROR);

        $response = $this->server->json('POST', '/api/v1/host/import/preview', $document, $this->cookies, $this->csrf);
        self::assertSame(200, $response->status, $response->body);
        $preview = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['preview'];

        self::assertSame('aloha-account-export', $preview['format']);
        self::assertSame('alice', $preview['source']['handle']);
        self::assertTrue($preview['apply']['available']);

        $byName = [];
        foreach ($preview['sections'] as $section) {
            $byName[$section['name']] = $section;
        }

        self::assertSame('local', $byName['preferences']['disposition']);
        self::assertSame('local', $byName['filters']['disposition']);
        self::assertSame('local', $byName['featured_tags']['disposition']);
        self::assertSame('local', $byName['followed_hashtags']['disposition']);

        self::assertSame('deferred', $byName['posts']['disposition']);
        self::assertSame('deferred', $byName['following']['disposition']);
        self::assertSame('deferred', $byName['followers']['disposition']);
        self::assertArrayNotHasKey('linked_bluesky_account', $byName);
        self::assertNotSame('', (string) $byName['posts']['reason']);

        $notTransferred = array_column($preview['not_transferred'], 'name');
        self::assertContains('linked_bluesky_account', $notTransferred);
        self::assertContains('core_archive', $notTransferred);

        self::assertGreaterThan(0, $preview['totals']['rows']);
        self::assertSame(
            $preview['totals']['local_rows'] + $preview['totals']['deferred_rows'] + $preview['totals']['unknown_rows'],
            $preview['totals']['rows'],
        );
    }

    public function testPreviewReportsUnknownSections(): void
    {
        $response = $this->server->json('POST', '/api/v1/host/import/preview', [
            'format' => 'aloha-account-export',
            'version' => 1,
            'future_section' => [['id' => 'x']],
        ], $this->cookies, $this->csrf);
        self::assertSame(200, $response->status, $response->body);
        $preview = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['preview'];

        self::assertSame('unknown', $preview['sections'][0]['disposition']);
        self::assertSame(1, $preview['totals']['unknown_rows']);
    }

    /**
     * @return array<string, mixed>
     */
    private function importDocument(): array
    {
        return [
            'format' => 'aloha-account-export',
            'version' => 1,
            'exported_at' => '2026-11-20T10:00:00+00:00',
            'instance' => ['url' => 'https://old.example', 'name' => 'Old instance'],
            'profile' => ['handle' => 'alice@old.example'],
            'preferences' => [
                'default_visibility' => 'unlisted',
                'notify_follows' => false,
                'mirror_likes_to_atproto' => true,
            ],
            'followed_hashtags' => [
                ['hashtag' => 'php', 'created_at' => '2026-11-20T10:00:00+00:00'],
                ['hashtag' => 'fediverse', 'created_at' => '2026-11-20T10:00:00+00:00'],
            ],
            'filters' => [
                [
                    'name' => 'crypto spam',
                    'action' => 'hide',
                    'contexts' => ['home', 'public'],
                    'keywords' => [['text' => 'crypto', 'whole_word' => true]],
                    'expires_at' => null,
                ],
            ],
            'featured_tags' => [
                ['tag' => 'travel', 'position' => 0],
                ['tag' => 'php', 'position' => 1],
            ],
            'posts' => [['id' => 'post-1', 'content' => 'hello']],
        ];
    }

    public function testApplyImportsLocalSectionsAndSkipsDeferred(): void
    {
        $response = $this->server->json(
            'POST',
            '/api/v1/host/import/apply',
            $this->importDocument(),
            $this->cookies,
            $this->csrf,
        );
        self::assertSame(200, $response->status, $response->body);
        $import = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['import'];

        $results = [];
        foreach ($import['results'] as $result) {
            $results[$result['name']] = $result;
        }

        self::assertSame('applied', $results['preferences']['status']);
        self::assertSame('applied', $results['followed_hashtags']['status']);
        self::assertSame('applied', $results['filters']['status']);
        self::assertSame('applied', $results['featured_tags']['status']);
        self::assertSame('skipped', $results['posts']['status']);
        self::assertStringContainsString('Move', (string) $results['posts']['reason']);

        // Preferences: whitelisted keys copied, instance-bound mirror state not.
        $preferences = $this->server->runtime->preferences->forAccount('alice');
        self::assertSame('unlisted', $preferences['default_visibility']);
        self::assertFalse($preferences['notify_follows']);
        self::assertFalse($preferences['mirror_likes_to_atproto']);

        // Hashtags dedupe through the core service.
        $hashtagRows = $this->server->runtime->database->fetchAll(
            'SELECT hashtag FROM social_followed_hashtag WHERE user_id = :id ORDER BY hashtag',
            ['id' => 'alice'],
        );
        self::assertSame(['fediverse', 'php'], array_column($hashtagRows, 'hashtag'));

        // Filters go through the core FilterService with its own validation.
        $filterRows = $this->server->runtime->database->fetchAll(
            'SELECT name, action FROM social_filter WHERE user_id = :id',
            ['id' => 'alice'],
        );
        self::assertCount(1, $filterRows);
        self::assertSame('crypto spam', $filterRows[0]['name']);
        self::assertSame('hide', $filterRows[0]['action']);

        $tags = array_column($this->server->runtime->featuredTags->forAccount('alice'), 'tag');
        self::assertSame(['travel', 'php'], $tags);
    }

    public function testApplyIsIdempotentAndSectionScoped(): void
    {
        $this->server->json('POST', '/api/v1/host/import/apply', $this->importDocument(), $this->cookies, $this->csrf);

        // Second run: no duplicate filters, hashtags or tags.
        $second = $this->server->json('POST', '/api/v1/host/import/apply', $this->importDocument(), $this->cookies, $this->csrf);
        self::assertSame(200, $second->status, $second->body);
        $results = json_decode($second->body, true, 512, JSON_THROW_ON_ERROR)['import']['results'];
        $byName = [];
        foreach ($results as $result) {
            $byName[$result['name']] = $result;
        }
        self::assertSame(0, $byName['filters']['rows']);
        self::assertSame(0, $byName['followed_hashtags']['rows']);
        self::assertSame(0, $byName['featured_tags']['rows']);

        // Section scoping: only filters requested.
        $scoped = $this->server->json('POST', '/api/v1/host/import/apply', [
            'export' => $this->importDocument(),
            'sections' => ['filters'],
        ], $this->cookies, $this->csrf);
        self::assertSame(200, $scoped->status, $scoped->body);
        $scopedResults = json_decode($scoped->body, true, 512, JSON_THROW_ON_ERROR)['import']['results'];
        foreach ($scopedResults as $result) {
            if ($result['name'] !== 'filters') {
                self::assertSame('skipped', $result['status']);
            }
        }

        self::assertSame(1, (int) $this->server->runtime->database->fetchValue(
            'SELECT COUNT(*) FROM social_filter WHERE user_id = :id',
            ['id' => 'alice'],
        ));
        self::assertSame(2, (int) $this->server->runtime->database->fetchValue(
            'SELECT COUNT(*) FROM social_followed_hashtag WHERE user_id = :id',
            ['id' => 'alice'],
        ));
    }

    public function testApplyRequiresAuthentication(): void
    {
        $response = $this->server->send('POST', '/api/v1/host/import/apply', [], '{}');
        self::assertSame(401, $response->status);
    }
}
