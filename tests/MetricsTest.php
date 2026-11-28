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
