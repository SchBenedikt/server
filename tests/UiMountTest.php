<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class UiMountTest extends TestCase
{
    public function testSignedOutAppPageMountsTheSharedBundle(): void
    {
        $server = new TestServer();

        $response = $server->send('GET', '/app');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('id="aloha-social"', $response->body);
        self::assertStringContainsString('/ui/app.js', $response->body);
        self::assertStringContainsString('/ui/app.css', $response->body);
        self::assertStringContainsString('data-signed-in="0"', $response->body);
        self::assertStringContainsString('data-media="1"', $response->body);
        self::assertStringContainsString('class="app-shell"', $response->body);
        self::assertStringContainsString("script-src 'self'", (string) $response->header('Content-Security-Policy'));
    }

    public function testSignedInAppPageCarriesUserAndCsrfForTheBridge(): void
    {
        $server = new TestServer();
        $server->createAccount();
        $session = $server->signIn();

        $response = $server->send('GET', '/app', ['cookies' => $session['cookies']]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('data-user="alice"', $response->body);
        self::assertStringContainsString('data-signed-in="1"', $response->body);
        self::assertStringContainsString('data-media="1"', $response->body);
        self::assertStringContainsString('data-csrf="' . $session['csrf'] . '"', $response->body);
    }

    public function testNativePdsDoesNotAdvertiseTheSeparateBlueskyPublishAction(): void
    {
        $server = new TestServer([
            'atproto_publish' => false,
            'atproto_pds' => true,
        ]);

        $response = $server->send('GET', '/app');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('data-atproto="0"', $response->body);
    }

    public function testTheBuiltSharedBundleIsInTheRepository(): void
    {
        $root = dirname(__DIR__);

        self::assertFileExists($root . '/public/ui/app.js');
        self::assertFileExists($root . '/public/ui/app.css');

        $script = (string) file_get_contents($root . '/public/ui/app.js');
        self::assertStringContainsString('a-shell', $script);
        self::assertStringContainsString('For you', $script);
        self::assertStringContainsString('/api/v1/timelines/home', $script);
        self::assertStringContainsString('/api/v1/timelines/public', $script);
        self::assertStringContainsString('/api/v1/media', $script);
        self::assertStringContainsString('a-sidebar__more-menu', $script);
        self::assertStringContainsString('a-mobile-sheet', $script);
        self::assertStringContainsString('a-media__item--video', $script);
        self::assertStringContainsString('/api/mastodon/v1/accounts/', $script);
        self::assertStringContainsString('video/mp4', $script);
        self::assertStringContainsString('audio/wav', $script);
        self::assertStringContainsString('M16 3a2 2 0 0 0-2 2v6', $script);
        self::assertStringContainsString('Kokua', $script);
        self::assertStringContainsString('/api/v1/host/kokua', $script);
        self::assertStringContainsString('/api/v1/host/admin/communications', $script);
        self::assertStringContainsString('Delivery, federation and background errors', $script);
        self::assertStringContainsString('Connect a Bluesky account in Settings before publishing.', $script);
        self::assertStringContainsString('Post saved. Delivery to other servers runs in the background.', $script);

        $style = (string) file_get_contents($root . '/public/ui/app.css');
        self::assertStringContainsString('.a-sidebar__more-menu', $style);
        self::assertStringContainsString('.a-mobile-sheet', $style);
        self::assertStringContainsString('.a-media__item--video', $style);
        self::assertStringContainsString('.a-composer__audio', $style);
        self::assertStringContainsString('.a-kokua-card', $style);
        self::assertStringContainsString('.a-admin-error__message', $style);
    }

    public function testDashboardLinksTheSharedApp(): void
    {
        $server = new TestServer();
        $server->createAccount();
        $session = $server->signIn();

        self::assertStringContainsString('href="/app"', $session['home']->body);
    }
}
