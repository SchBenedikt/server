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
        self::assertFalse($preview['apply']['available']);

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
}
