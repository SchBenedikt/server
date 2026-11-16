<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class AdminTest extends TestCase
{
    /**
     * @return array{server: TestServer, cookies: array<string, string>, csrf: string}
     */
    private function admin(): array
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test', TestServer::PASSWORD, true);
        $session = $server->signIn('alice');

        return ['server' => $server, 'cookies' => $session['cookies'], 'csrf' => $session['csrf']];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function decoded(\AlohaSocial\Core\Http\Response $response): array
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        return $body;
    }

    public function testAdminApiRequiresAnAdminSession(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test');
        $session = $server->signIn('alice');

        $anonymous = $server->send('GET', '/api/v1/host/admin/overview');
        self::assertSame(401, $anonymous->status);

        $forbidden = $server->send('GET', '/api/v1/host/admin/overview', ['cookies' => $session['cookies']]);
        self::assertSame(403, $forbidden->status);
        self::assertStringContainsString('admin_required', $forbidden->body);
    }

    public function testAdminMutationsRequireCsrf(): void
    {
        ['server' => $server, 'cookies' => $cookies] = $this->admin();

        $response = $server->json('POST', '/api/v1/host/admin/users', [
            'handle' => 'bob',
            'email' => 'bob@example.test',
            'password' => 'a-long-enough-password',
        ], $cookies);

        self::assertSame(403, $response->status);
        self::assertStringContainsString('csrf_token_invalid', $response->body);
    }

    public function testOverviewReportsTheOperationalState(): void
    {
        ['server' => $server, 'cookies' => $cookies] = $this->admin();

        $response = $server->send('GET', '/api/v1/host/admin/overview', ['cookies' => $cookies]);
        self::assertSame(200, $response->status);

        $body = $this->decoded($response);
        self::assertArrayHasKey('stats', $body);
        self::assertArrayHasKey('health', $body);
        self::assertArrayHasKey('worker', $body);
        self::assertSame(1, $body['stats']['accounts']['total']);
        self::assertSame(1, $body['stats']['accounts']['admins']);
        self::assertIsArray($body['health']);
        self::assertNotEmpty($body['health']);
    }

    public function testWorkerHeartbeatMarksTheWorkerOnline(): void
    {
        $server = new TestServer();

        $server->runtime->admin->heartbeat();
        $overview = $server->runtime->admin->overview();

        self::assertTrue($overview['worker']['online']);
        self::assertNotNull($overview['worker']['last_beat']);
        self::assertNotNull($overview['worker']['updated_at']);
    }

    public function testAdminCanCreateDisableEnableAndResetAnAccount(): void
    {
        ['server' => $server, 'cookies' => $cookies, 'csrf' => $csrf] = $this->admin();

        $created = $server->json('POST', '/api/v1/host/admin/users', [
            'handle' => 'bob',
            'email' => 'bob@example.test',
            'password' => 'correct-horse-battery-staple',
            'role' => 'user',
            'display_name' => 'Bob',
        ], $cookies, $csrf);

        self::assertSame(201, $created->status);
        $bob = $this->decoded($created)['user'];
        self::assertSame('bob', $bob['handle']);
        self::assertSame('user', $bob['role']);
        self::assertSame('Bob', $bob['display_name']);

        $disabled = $server->json('POST', '/api/v1/host/admin/users/bob/disable', [], $cookies, $csrf);
        self::assertSame(200, $disabled->status);
        self::assertNotNull($this->decoded($disabled)['user']['disabled_at']);

        $login = $server->login('bob', 'correct-horse-battery-staple');
        self::assertNotSame(303, $login['response']->status);

        $enabled = $server->json('POST', '/api/v1/host/admin/users/bob/enable', [], $cookies, $csrf);
        self::assertSame(200, $enabled->status);
        self::assertNull($this->decoded($enabled)['user']['disabled_at']);

        $password = $server->json('POST', '/api/v1/host/admin/users/bob/password', [
            'password' => 'a-brand-new-password',
        ], $cookies, $csrf);
        self::assertSame(200, $password->status);

        $login = $server->login('bob', 'a-brand-new-password');
        self::assertSame(303, $login['response']->status);
    }

    public function testSelfDisableAndDemotingTheLastAdminAreRefused(): void
    {
        ['server' => $server, 'cookies' => $cookies, 'csrf' => $csrf] = $this->admin();

        $self = $server->json('POST', '/api/v1/host/admin/users/alice/disable', [], $cookies, $csrf);
        self::assertSame(409, $self->status);

        $demote = $server->json('PATCH', '/api/v1/host/admin/users/alice', ['role' => 'user'], $cookies, $csrf);
        self::assertSame(409, $demote->status);
        self::assertStringContainsString('last active administrator', $demote->body);
    }

    public function testReportLifecycleIsAudited(): void
    {
        ['server' => $server, 'cookies' => $cookies, 'csrf' => $csrf] = $this->admin();

        $created = $server->json('POST', '/api/v1/host/admin/users', [
            'handle' => 'bob',
            'email' => 'bob@example.test',
            'password' => 'correct-horse-battery-staple',
        ], $cookies, $csrf);
        self::assertSame(201, $created->status);

        $bobSession = $server->signIn('bob', 'correct-horse-battery-staple');
        $post = $server->json('POST', '/api/v1/posts', [
            'content' => 'A post that will be reported',
            'visibility' => 'public',
        ], $bobSession['cookies'], $bobSession['csrf']);
        self::assertSame(201, $post->status);
        $postId = $this->decoded($post)['post']['id'];

        $report = $server->json('POST', '/api/v1/host/reports', [
            'target_type' => 'post',
            'target_id' => $postId,
            'category' => 'spam',
            'comment' => 'This looks like advertising.',
        ], $bobSession['cookies'], $bobSession['csrf']);

        self::assertSame(201, $report->status);
        $reportId = $this->decoded($report)['report']['id'];
        self::assertSame('open', $this->decoded($report)['report']['state']);

        $duplicate = $server->json('POST', '/api/v1/host/reports', [
            'target_type' => 'post',
            'target_id' => $postId,
            'category' => 'spam',
        ], $bobSession['cookies'], $bobSession['csrf']);
        self::assertSame(409, $duplicate->status);

        $list = $server->send('GET', '/api/v1/host/admin/reports?state=open', ['cookies' => $cookies]);
        self::assertSame(200, $list->status);
        self::assertSame(1, $this->decoded($list)['counts']['open']);

        $resolved = $server->json('POST', '/api/v1/host/admin/reports/' . $reportId . '/resolve', [
            'state' => 'resolved',
            'note' => 'Reviewed and removed from the timeline.',
        ], $cookies, $csrf);
        self::assertSame(200, $resolved->status);
        self::assertSame('resolved', $this->decoded($resolved)['report']['state']);

        $audit = $server->send('GET', '/api/v1/host/admin/audit', ['cookies' => $cookies]);
        self::assertSame(200, $audit->status);
        $actions = array_column($this->decoded($audit)['entries'], 'action');
        self::assertContains('report.create', $actions);
        self::assertContains('report.resolved', $actions);
        self::assertContains('user.create', $actions);
    }

    public function testRemoteTargetsCanBeReportedWithoutALocalCacheEntry(): void
    {
        ['server' => $server, 'cookies' => $cookies] = $this->admin();
        $session = $server->signIn('alice');

        $response = $server->json('POST', '/api/v1/host/reports', [
            'target_type' => 'post',
            'target_id' => 'https://remote.example/notes/123',
            'category' => 'harassment',
        ], $session['cookies'], $session['csrf']);

        self::assertSame(201, $response->status);
        $report = $this->decoded($response)['report'];
        self::assertSame('remote', $report['target']['scope']);
        self::assertTrue($report['target']['exists']);
    }

    public function testAdminCanDeleteALocalPost(): void
    {
        ['server' => $server, 'cookies' => $cookies, 'csrf' => $csrf] = $this->admin();

        $post = $server->json('POST', '/api/v1/posts', [
            'content' => 'A post that the administration removes',
            'visibility' => 'public',
        ], $cookies, $csrf);
        self::assertSame(201, $post->status);
        $postId = $this->decoded($post)['post']['id'];

        $deleted = $server->send('DELETE', '/api/v1/host/admin/posts/' . $postId, [
            'cookies' => $cookies,
            'headers' => ['X-CSRF-Token' => $csrf],
        ]);
        self::assertSame(204, $deleted->status);

        $read = $server->send('GET', '/api/v1/posts/' . $postId);
        self::assertSame(404, $read->status);
    }

    public function testSignedInHomeLinksTheAdministration(): void
    {
        ['server' => $server, 'cookies' => $cookies, 'csrf' => $csrf] = $this->admin();

        $home = $server->send('GET', '/', ['cookies' => $cookies]);
        self::assertSame(200, $home->status);
        self::assertStringContainsString('/admin', $home->body);
        self::assertStringContainsString('Open admin interface', $home->body);

        $app = $server->send('GET', '/app', ['cookies' => $cookies]);
        self::assertSame(200, $app->status);
        self::assertStringContainsString('data-is-admin="1"', $app->body);

        $redirect = $server->send('GET', '/admin', ['cookies' => $cookies]);
        self::assertSame(303, $redirect->status);
        self::assertSame('/app#/admin', $redirect->header('Location'));
    }
}
