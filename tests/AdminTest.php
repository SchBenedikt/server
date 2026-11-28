<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Platform\Storage\S3CompatibleClient;
use AlohaSocial\Core\Platform\Storage\S3ObjectPage;
use AlohaSocial\Core\Jobs\JobSubject;
use AlohaSocial\Server\Support\RuntimeLogger;
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

    public function testAnonymousSessionIsRedirectedToLoginInsteadOfForbidden(): void
    {
        $server = new TestServer();
        $login = $server->send('GET', '/login');
        self::assertSame(200, $login->status);
        $cookies = $server->cookiesFrom($login);
        self::assertNotSame([], $cookies);

        $admin = $server->send('GET', '/admin', ['cookies' => $cookies]);
        self::assertSame(303, $admin->status);
        self::assertStringContainsString('/login?next=%2Fadmin', (string) $admin->header('Location'));
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

    public function testCommunicationErrorsAreListedForTheAdministration(): void
    {
        ['server' => $server, 'cookies' => $cookies] = $this->admin();

        (new RuntimeLogger($server->runtime->database, $server->runtime->clock))->warning(
            'Remote delivery to {domain} failed with {code}.',
            ['domain' => 'peer.example', 'code' => 'timeout'],
        );

        $response = $server->send('GET', '/api/v1/host/admin/communications', [
            'cookies' => $cookies,
        ]);

        self::assertSame(200, $response->status);
        $body = $this->decoded($response);
        self::assertSame(1, $body['counts']['last_24h']);
        self::assertSame('peer.example', $body['errors'][0]['source']);
        self::assertStringContainsString('peer.example', $body['errors'][0]['message']);
        self::assertSame('timeout', $body['errors'][0]['context']['code']);
    }

    public function testAdminCanBlockAndUnblockADomain(): void
    {
        ['server' => $server, 'cookies' => $cookies, 'csrf' => $csrf] = $this->admin();

        $blocked = $server->json('POST', '/api/v1/host/admin/moderation', [
            'kind' => 'block_domain',
            'target' => 'https://bad.example/@spam',
            'note' => 'Harassment campaign',
        ], $cookies, $csrf);
        self::assertSame(201, $blocked->status);
        $action = $this->decoded($blocked)['action'];
        self::assertSame('block_domain', $action['kind']);
        self::assertSame('bad.example', $action['target']);

        $list = $this->decoded($server->send('GET', '/api/v1/host/admin/moderation', ['cookies' => $cookies]));
        self::assertSame(1, $list['active']);
        self::assertSame('bad.example', $list['actions'][0]['target']);

        $reversed = $server->json(
            'POST',
            '/api/v1/host/admin/moderation/' . rawurlencode($action['id']) . '/reverse',
            ['note' => 'False positive'],
            $cookies,
            $csrf,
        );
        self::assertSame(200, $reversed->status);

        $after = $this->decoded($server->send('GET', '/api/v1/host/admin/moderation', ['cookies' => $cookies]));
        self::assertSame(0, $after['active']);
        self::assertNotNull($after['actions'][0]['reversed_at']);

        $audit = $this->decoded($server->send('GET', '/api/v1/host/admin/audit', ['cookies' => $cookies]));
        $actions = array_column($audit['entries'], 'action');
        self::assertContains('moderation.reverse', $actions);
        self::assertContains('moderation.block_domain', $actions);
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

    public function testS3StorageIsUsedAndReportedWithoutLeakingCredentials(): void
    {
        $s3 = new class implements S3CompatibleClient {
            public function putObject(string $bucket, string $key, mixed $body, int $contentLength, ?string $serverSideEncryption, ?string $kmsKeyId): void
            {
            }

            public function getObject(string $bucket, string $key, int $maxBytes): mixed
            {
                return null;
            }

            public function headObject(string $bucket, string $key): ?int
            {
                return null;
            }

            public function deleteObject(string $bucket, string $key): void
            {
            }

            public function listObjects(string $bucket, string $prefix, ?string $continuationToken, int $maxKeys): S3ObjectPage
            {
                return new S3ObjectPage([], null);
            }
        };
        $server = new TestServer([
            'storage_driver' => 's3',
            's3_bucket' => 'aloha-media-test',
            's3_endpoint' => 'https://objects.example.test',
            's3_access_key' => 'access-key-secret',
            's3_secret_key' => 'private-key-secret',
        ], null, null, $s3);

        $overview = $server->runtime->admin->overview();
        $instance = $server->runtime->admin->instance();
        $storageHealth = array_values(array_filter(
            $overview['health'],
            static fn (array $check): bool => $check['id'] === 'storage',
        ))[0];

        self::assertSame('s3', $overview['stats']['media']['storage_driver']);
        self::assertNull($overview['stats']['media']['blobs']);
        self::assertNull($overview['stats']['media']['blob_bytes']);
        self::assertSame('ok', $storageHealth['status']);
        self::assertSame('s3://aloha-media-test', $instance['paths']['blobs']);
        self::assertSame('s3', $instance['features']['storage_driver']);
        self::assertFalse(str_contains(json_encode($instance, JSON_THROW_ON_ERROR), 'private-key-secret'));
        self::assertFalse(str_contains(json_encode($instance, JSON_THROW_ON_ERROR), 'access-key-secret'));
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

        $server->runtime->app->queue->push(
            'test.account-work',
            [],
            $server->runtime->clock->now()->modify('-1 minute'),
            JobSubject::account('bob'),
        );
        $server->runtime->app->queue->push('test.global-work', []);
        self::assertSame(2, $server->runtime->app->queue->pendingCount());
        $reserved = $server->runtime->app->queue->reserve(1, $server->runtime->clock->now());
        self::assertCount(1, $reserved);
        self::assertSame('bob', $reserved[0]->subject?->id);

        $disabled = $server->json('POST', '/api/v1/host/admin/users/bob/disable', [], $cookies, $csrf);
        self::assertSame(200, $disabled->status);
        self::assertNotNull($this->decoded($disabled)['user']['disabled_at']);
        self::assertSame(1, $server->runtime->app->queue->pendingCount());
        $remaining = $server->runtime->app->queue->reserve(10, $server->runtime->clock->now());
        self::assertCount(1, $remaining);
        self::assertSame('test.global-work', $remaining[0]->type);

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

    public function testAdminCannotReactivateAnErasedAccount(): void
    {
        ['server' => $server, 'cookies' => $adminCookies, 'csrf' => $adminCsrf] = $this->admin();
        $server->createAccount('bob', 'bob@example.test');
        $bob = $server->signIn('bob');

        $deleted = $server->json(
            'DELETE',
            '/api/v1/account/data',
            [],
            $bob['cookies'],
            $bob['csrf'],
        );
        self::assertSame(200, $deleted->status, $deleted->body);

        $enable = $server->json(
            'POST',
            '/api/v1/host/admin/users/bob/enable',
            [],
            $adminCookies,
            $adminCsrf,
        );

        self::assertSame(409, $enable->status);
        self::assertStringContainsString('cannot be reactivated', $enable->body);
        self::assertTrue($server->runtime->accounts->find('bob')?->isDisabled());
        self::assertSame('erased', $server->runtime->app->accountData?->deletionStatus('bob')->state);
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

        $console = $server->send('GET', '/admin', ['cookies' => $cookies]);
        self::assertSame(200, $console->status);
        self::assertStringContainsString('admin.css', $console->body);
        self::assertStringContainsString('admin.js', $console->body);
    }

    public function testDeadJobsExposeTheirLastErrorAndRetryOnlyWhenDead(): void
    {
        ['server' => $server, 'cookies' => $cookies, 'csrf' => $csrf] = $this->admin();
        $db = $server->runtime->database;
        $now = '2026-11-28T00:00:00Z';

        $db->execute(
            'INSERT INTO social_job (id, type, payload, available_at, attempts, reserved_at, dead_at,'
            . ' subject_type, subject_id, last_error)'
            . " VALUES ('job-dead', 'deliver', '{}', :now, 5, NULL, :now, NULL, NULL,"
            . " 'RuntimeException: remote inbox rejected the activity')",
            ['now' => $now],
        );
        $db->execute(
            'INSERT INTO social_job (id, type, payload, available_at, attempts, reserved_at, dead_at,'
            . ' subject_type, subject_id, last_error)'
            . " VALUES ('job-live', 'deliver', '{}', :now, 0, NULL, NULL, NULL, NULL, NULL)",
            ['now' => $now],
        );
        $db->execute(
            'INSERT INTO social_job (id, type, payload, available_at, attempts, reserved_at, dead_at,'
            . ' subject_type, subject_id, last_error)'
            . " VALUES ('job-dead-2', 'deliver', '{}', :now, 5, NULL, :now, NULL, NULL,"
            . " 'RuntimeException: remote inbox rejected the activity')",
            ['now' => $now],
        );

        $dead = $server->send('GET', '/api/v1/host/admin/jobs?state=dead', ['cookies' => $cookies]);
        self::assertSame(200, $dead->status);
        $jobs = $this->decoded($dead)['jobs'];
        $byId = array_column($jobs, null, 'id');
        self::assertArrayHasKey('job-dead', $byId);
        self::assertSame('RuntimeException: remote inbox rejected the activity', $byId['job-dead']['last_error']);
        self::assertTrue($byId['job-dead']['retryable']);

        // Dead jobs cluster into failure classes so one cause is visible
        // without reading every row; live jobs never classify.
        $body = $this->decoded($dead);
        self::assertSame(['RuntimeException' => 2], $body['error_classes']);

        // A live job cannot be "retried" — there is nothing to recover.
        $live = $server->send('POST', '/api/v1/host/admin/jobs/job-live/retry', [
            'cookies' => $cookies,
            'headers' => ['X-CSRF-Token' => $csrf],
        ]);
        self::assertSame(409, $live->status, $live->body);
        self::assertSame('conflict', $this->decoded($live)['error']['code']);

        $retry = $server->send('POST', '/api/v1/host/admin/jobs/job-dead/retry', [
            'cookies' => $cookies,
            'headers' => ['X-CSRF-Token' => $csrf],
        ]);
        self::assertSame(200, $retry->status, $retry->body);
        self::assertSame('pending', $this->decoded($retry)['job']['state']);
        self::assertSame(0, (int) $db->fetchValue(
            'SELECT attempts FROM social_job WHERE id = :id',
            ['id' => 'job-dead'],
        ));
        self::assertSame(1, (int) $db->fetchValue(
            "SELECT COUNT(*) FROM admin_audit WHERE action = 'job.retry' AND target_id = 'job-dead'",
        ));
    }
}
