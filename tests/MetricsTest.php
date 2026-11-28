<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Support\Metrics;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class MetricsTest extends TestCase
{
    public function testRenderProducesPrometheusText(): void
    {
        $metrics = new Metrics();
        $metrics->increment('aloha_test_total', ['route' => '/api/v1/posts/{id}', 'status' => '2xx']);
        $metrics->increment('aloha_test_total', ['route' => '/api/v1/posts/{id}', 'status' => '2xx']);
        $metrics->observe('aloha_test_duration_seconds', 0.25, ['route' => '/x']);
        $metrics->set('aloha_test_gauge', 3.0);

        $text = $metrics->render(['aloha_scrape_gauge' => 1.0]);

        self::assertStringContainsString('# TYPE aloha_test_total counter', $text);
        self::assertStringContainsString('aloha_test_total{route="/api/v1/posts/{id}",status="2xx"} 2', $text);
        self::assertStringContainsString('aloha_test_duration_seconds_sum{route="/x"} 0.250000', $text);
        self::assertStringContainsString('aloha_test_duration_seconds_count{route="/x"} 1', $text);
        self::assertStringContainsString('aloha_test_gauge 3', $text);
        self::assertStringContainsString('aloha_scrape_gauge 1', $text);
    }

    public function testRenderProducesLabelledScrapeGauges(): void
    {
        $metrics = new Metrics();

        $text = $metrics->render([], [
            'aloha_sync_stale' => ['source=jetstream' => 1.0, 'source=relay' => 0.0],
        ]);

        self::assertStringContainsString('# TYPE aloha_sync_stale gauge', $text);
        self::assertStringContainsString('aloha_sync_stale{source="jetstream"} 1', $text);
        self::assertStringContainsString('aloha_sync_stale{source="relay"} 0', $text);
    }

    public function testLabelValuesAreSanitized(): void
    {
        self::assertSame('safe_value-1.2/x', Metrics::sanitizeLabelValue('safe_value-1.2/x'));
        self::assertSame('user_example.test', Metrics::sanitizeLabelValue('user@example.test'));
        self::assertSame('unknown', Metrics::sanitizeLabelValue(''));
        self::assertSame(str_repeat('a', 64), Metrics::sanitizeLabelValue(str_repeat('a', 200)));
    }

    public function testRouteTemplateCollapsesIdSegments(): void
    {
        self::assertSame('/api/v1/posts/{id}', Metrics::routeTemplate('/api/v1/posts/abc123def456ghi789jkl012mno345pqr678'));
        self::assertSame('/api/v1/posts/{id}', Metrics::routeTemplate('/api/v1/posts/987654321'));
        self::assertSame('/api/v1/host/profiles', Metrics::routeTemplate('/api/v1/host/profiles'));
        self::assertSame('/inbox/{id}', Metrics::routeTemplate('/inbox/user@remote.example'));
    }

    public function testMetricsEndpointIsHiddenWithoutACredential(): void
    {
        $server = new TestServer();

        $response = $server->send('GET', '/metrics');
        self::assertSame(404, $response->status);
    }

    public function testMetricsEndpointServesAdminSessions(): void
    {
        $server = new TestServer();
        $server->createAccount('root', admin: true);
        $session = $server->signIn('root');

        $response = $server->send('GET', '/metrics', ['cookies' => $session['cookies']]);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('aloha_http_requests_total', $response->body);
        self::assertStringContainsString('aloha_queue_jobs_pending', $response->body);
        self::assertStringContainsString('aloha_mail_outbox_pending', $response->body);
    }

    public function testMetricsEndpointExposesSyncHealthPerSource(): void
    {
        $server = new TestServer();
        $server->createAccount('root', admin: true);
        $session = $server->signIn('root');

        $db = $server->runtime->database;
        $db->execute(
            'INSERT INTO social_atproto_sync_checkpoint'
            . ' (source, cursor, last_sequence, last_event_at, last_attempt_at, last_error, stale, reconcile_required)'
            . " VALUES ('jetstream', NULL, NULL, NULL, '2026-11-28T00:00:00Z', 'timeout', 1, 0)",
        );
        $db->execute(
            'INSERT INTO push_delivery (id, account_id, payload, attempts, next_attempt_at, created_at)'
            . " VALUES ('d1', 'root', '{}', 0, '2026-11-28T00:00:00Z', '2026-11-28T00:00:00Z')",
        );

        $response = $server->send('GET', '/metrics', ['cookies' => $session['cookies']]);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('aloha_atproto_sync_stale{source="jetstream"} 1', $response->body);
        self::assertStringContainsString('aloha_atproto_sync_error{source="jetstream"} 1', $response->body);
        self::assertStringContainsString('aloha_atproto_sync_last_attempt_age_seconds{source="jetstream"}', $response->body);
        self::assertStringContainsString('aloha_push_delivery_pending 1', $response->body);
        self::assertStringContainsString('aloha_engagement_refresh_pending 0', $response->body);
    }

    public function testMetricsEndpointReportsQueueAgeAndStorage(): void
    {
        $server = new TestServer();
        $server->createAccount('root', admin: true);
        $session = $server->signIn('root');

        $db = $server->runtime->database;
        $db->execute(
            'INSERT INTO social_job (id, type, payload, available_at, attempts)'
            . " VALUES ('j1', 'test.job', '{}', '2020-01-01T00:00:00Z', 0)",
        );
        // A future-scheduled job must not skew the oldest-due age.
        $db->execute(
            'INSERT INTO social_job (id, type, payload, available_at, attempts)'
            . " VALUES ('j2', 'test.job', '{}', '2999-01-01T00:00:00Z', 0)",
        );
        $db->execute(
            'INSERT INTO social_job (id, type, payload, available_at, attempts, dead_at)'
            . " VALUES ('j3', 'federation.deliver', '{}', '2020-01-01T00:00:00Z', 5, '2020-01-02T00:00:00Z')",
        );

        $response = $server->send('GET', '/metrics', ['cookies' => $session['cookies']]);
        self::assertSame(200, $response->status);
        self::assertMatchesRegularExpression(
            '/aloha_queue_oldest_pending_age_seconds [1-9][0-9]{8,}/',
            $response->body,
        );
        self::assertStringContainsString('aloha_storage_free_bytes', $response->body);
        self::assertStringContainsString('aloha_storage_total_bytes', $response->body);
        self::assertMatchesRegularExpression(
            '/aloha_queue_jobs_dead_by_type\{type="federation\.deliver"\} 1/',
            $response->body,
        );
        self::assertMatchesRegularExpression(
            '/aloha_queue_jobs_pending_by_type\{type="test\.job"\} 2/',
            $response->body,
        );
    }

    public function testMetricsEndpointExposesPerTaskWorkerHealth(): void
    {
        $server = new TestServer();
        $server->createAccount('root', admin: true);
        $session = $server->signIn('root');

        $db = $server->runtime->database;
        $now = time();
        // mail recovered after a failure; queue is currently failing.
        $db->execute(
            "INSERT INTO runtime_state (name, value, updated_at) VALUES"
            . " ('worker_task.mail', :mail, '2026-11-28T00:00:00Z'),"
            . " ('worker_task.queue', :queue, '2026-11-28T00:00:00Z')",
            [
                'mail' => json_encode(['ok' => $now - 30, 'fail' => $now - 120]),
                'queue' => json_encode(['ok' => $now - 300, 'fail' => $now - 10, 'error' => 'boom']),
            ],
        );

        $response = $server->send('GET', '/metrics', ['cookies' => $session['cookies']]);
        self::assertSame(200, $response->status);
        self::assertMatchesRegularExpression(
            '/aloha_worker_task_last_ok_age_seconds\{task="mail"\} (2[5-9]|3[0-9]|4[0-9])/',
            $response->body,
        );
        self::assertMatchesRegularExpression(
            '/aloha_worker_task_last_failure_age_seconds\{task="queue"\} ([0-9]|1[0-9])/',
            $response->body,
        );
        self::assertStringContainsString('aloha_worker_task_failing{task="mail"} 0', $response->body);
        self::assertStringContainsString('aloha_worker_task_failing{task="queue"} 1', $response->body);
    }

    public function testMetricsEndpointRequiresTheBearerToken(): void
    {
        $server = new TestServer(['metrics_token' => 'scrape-secret-123']);

        $denied = $server->send('GET', '/metrics');
        self::assertSame(401, $denied->status);

        $wrong = $server->send('GET', '/metrics', ['headers' => ['Authorization' => 'Bearer nope']]);
        self::assertSame(401, $wrong->status);

        $allowed = $server->send('GET', '/metrics', ['headers' => ['Authorization' => 'Bearer scrape-secret-123']]);
        self::assertSame(200, $allowed->status);
        self::assertStringContainsString('aloha_http_requests_total', $allowed->body);
    }
}
