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
 * The Kokua board is first-party community infrastructure, so these tests use
 * the real runtime and front controller: session, CSRF, author rules and the
 * SQLite schema are exercised exactly as in production.
 */
final class KokuaTest extends TestCase
{
    private TestServer $server;

    /** @var array<string, string> */
    private array $aliceCookies;

    private string $aliceCsrf;

    /** @var array<string, string> */
    private array $bobCookies;

    private string $bobCsrf;

    protected function setUp(): void
    {
        $this->server = new TestServer();
        $this->server->createAccount('alice', 'alice@example.test', TestServer::PASSWORD, true);
        $this->server->createAccount('bob', 'bob@example.test');

        $alice = $this->server->signIn('alice');
        $this->aliceCookies = $alice['cookies'];
        $this->aliceCsrf = $alice['csrf'];

        $bob = $this->server->signIn('bob');
        $this->bobCookies = $bob['cookies'];
        $this->bobCsrf = $bob['csrf'];
    }

    /**
     * @return array<string, mixed>
     */
    private function decoded(\AlohaSocial\Core\Http\Response $response): array
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        return $body;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createKokua(array $overrides = []): array
    {
        $response = $this->server->json('POST', '/api/v1/host/kokua', array_merge([
            'kind' => 'request',
            'title' => 'A ride to the train station',
            'details' => 'My bike is broken and the first train leaves at seven.',
            'category' => 'everyday',
            'place' => 'Kaimuki',
            'needed_by' => date('Y-m-d', strtotime('+2 days')),
        ], $overrides), $this->aliceCookies, $this->aliceCsrf);

        self::assertSame(201, $response->status, $response->body);

        return $this->decoded($response)['kokua'];
    }

    public function testRequestsAndOffersArePublicAndFilterable(): void
    {
        $request = $this->createKokua();
        self::assertSame('request', $request['kind']);
        self::assertSame('open', $request['status']);
        self::assertTrue($request['is_owner']);
        self::assertSame(0, $request['help_count']);

        $offer = $this->server->json('POST', '/api/v1/host/kokua', [
            'kind' => 'offer',
            'title' => 'I can repair small appliances',
            'details' => 'Bring a broken toaster or kettle by on Saturday.',
            'category' => 'skills',
            'place' => 'Kaimuki',
        ], $this->bobCookies, $this->bobCsrf);
        self::assertSame(201, $offer->status);
        $offerEntry = $this->decoded($offer)['kokua'];
        self::assertSame('offer', $offerEntry['kind']);

        $requests = $this->server->send('GET', '/api/v1/host/kokua', [
            'query' => ['kind' => 'request'],
        ]);
        self::assertSame(200, $requests->status);
        $page = $this->decoded($requests);
        self::assertSame(1, $page['total']);
        self::assertSame([$request['id']], array_column($page['items'], 'id'));
        self::assertFalse($page['items'][0]['is_owner']);
        self::assertSame(1, $page['counts']['open_requests']);
        self::assertSame(1, $page['counts']['open_offers']);

        $skills = $this->server->send('GET', '/api/v1/host/kokua', [
            'query' => ['category' => 'skills'],
        ]);
        self::assertSame([$offerEntry['id']], array_column($this->decoded($skills)['items'], 'id'));

        $single = $this->server->send('GET', '/api/v1/host/kokua/' . $request['id']);
        self::assertSame(200, $single->status);
        self::assertSame($request['id'], $this->decoded($single)['kokua']['id']);

        $missing = $this->server->send('GET', '/api/v1/host/kokua/does-not-exist');
        self::assertSame(404, $missing->status);
    }

    public function testHelpIsIdempotentAndViewerSpecific(): void
    {
        $request = $this->createKokua();

        $helped = $this->server->json(
            'POST',
            '/api/v1/host/kokua/' . $request['id'] . '/help',
            ['message' => 'I drive past the station at 6:30.'],
            $this->bobCookies,
            $this->bobCsrf,
        );
        self::assertSame(200, $helped->status);
        $entry = $this->decoded($helped)['kokua'];
        self::assertSame(1, $entry['help_count']);
        self::assertTrue($entry['viewer_helping']);
        self::assertSame('bob', $entry['helpers'][0]['account_id']);

        $again = $this->server->json(
            'POST',
            '/api/v1/host/kokua/' . $request['id'] . '/help',
            ['message' => 'I can also take the return trip.'],
            $this->bobCookies,
            $this->bobCsrf,
        );
        $repeated = $this->decoded($again)['kokua'];
        self::assertSame(1, $repeated['help_count']);
        self::assertSame('I can also take the return trip.', $repeated['helpers'][0]['message']);

        $authorView = $this->server->send('GET', '/api/v1/host/kokua/' . $request['id'], [
            'cookies' => $this->aliceCookies,
        ]);
        $authorEntry = $this->decoded($authorView)['kokua'];
        self::assertTrue($authorEntry['is_owner']);
        self::assertFalse($authorEntry['viewer_helping']);
        self::assertSame(1, $authorEntry['help_count']);

        $withdrawn = $this->server->json(
            'DELETE',
            '/api/v1/host/kokua/' . $request['id'] . '/help',
            [],
            $this->bobCookies,
            $this->bobCsrf,
        );
        self::assertSame(0, $this->decoded($withdrawn)['kokua']['help_count']);

        $own = $this->server->json(
            'POST',
            '/api/v1/host/kokua/' . $request['id'] . '/help',
            [],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame(422, $own->status);

        $anonymous = $this->server->send('POST', '/api/v1/host/kokua/' . $request['id'] . '/help');
        self::assertSame(401, $anonymous->status);

        $withoutCsrf = $this->server->json(
            'POST',
            '/api/v1/host/kokua/' . $request['id'] . '/help',
            [],
            $this->bobCookies,
        );
        self::assertSame(403, $withoutCsrf->status);
        self::assertStringContainsString('csrf_token_invalid', $withoutCsrf->body);
    }

    public function testOnlyTheAuthorCanCompleteReopenOrDelete(): void
    {
        $request = $this->createKokua();
        $this->server->json(
            'POST',
            '/api/v1/host/kokua/' . $request['id'] . '/help',
            ['message' => 'I can help.'],
            $this->bobCookies,
            $this->bobCsrf,
        );

        $forbidden = $this->server->json(
            'PATCH',
            '/api/v1/host/kokua/' . $request['id'],
            ['status' => 'done'],
            $this->bobCookies,
            $this->bobCsrf,
        );
        self::assertSame(403, $forbidden->status);

        $done = $this->server->json(
            'PATCH',
            '/api/v1/host/kokua/' . $request['id'],
            ['status' => 'done'],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame(200, $done->status);
        $completed = $this->decoded($done)['kokua'];
        self::assertSame('done', $completed['status']);
        self::assertNotNull($completed['done_at']);
        self::assertSame(1, $completed['help_count']);

        $open = $this->server->send('GET', '/api/v1/host/kokua');
        self::assertSame(0, $this->decoded($open)['total']);

        $finished = $this->server->send('GET', '/api/v1/host/kokua', [
            'query' => ['state' => 'done'],
        ]);
        self::assertSame(1, $this->decoded($finished)['total']);
        self::assertSame(1, $this->decoded($finished)['counts']['done']);

        $helpDone = $this->server->json(
            'POST',
            '/api/v1/host/kokua/' . $request['id'] . '/help',
            [],
            $this->bobCookies,
            $this->bobCsrf,
        );
        self::assertSame(422, $helpDone->status);

        $reopened = $this->server->json(
            'PATCH',
            '/api/v1/host/kokua/' . $request['id'],
            ['status' => 'open'],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame('open', $this->decoded($reopened)['kokua']['status']);
        self::assertNull($this->decoded($reopened)['kokua']['done_at']);

        $deleteForbidden = $this->server->json(
            'DELETE',
            '/api/v1/host/kokua/' . $request['id'],
            [],
            $this->bobCookies,
            $this->bobCsrf,
        );
        self::assertSame(403, $deleteForbidden->status);

        $deleted = $this->server->json(
            'DELETE',
            '/api/v1/host/kokua/' . $request['id'],
            [],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame(200, $deleted->status);
        self::assertNull($this->server->runtime->kokua->find($request['id']));
    }

    public function testValidationRejectsInvalidOrAnonymousCards(): void
    {
        $anonymous = $this->server->json('POST', '/api/v1/host/kokua', [
            'kind' => 'request',
            'title' => 'Anonymous',
            'category' => 'everyday',
        ]);
        self::assertSame(401, $anonymous->status);

        $cases = [
            ['title' => '   ', 'field' => 'title'],
            ['kind' => 'trade', 'field' => 'kind'],
            ['category' => 'magic', 'field' => 'category'],
            ['needed_by' => '2020-01-01', 'field' => 'needed_by'],
            ['details' => str_repeat('x', 1001), 'field' => 'details'],
            ['place' => str_repeat('x', 81), 'field' => 'place'],
        ];

        foreach ($cases as $case) {
            $field = $case['field'];
            unset($case['field']);
            $response = $this->server->json(
                'POST',
                '/api/v1/host/kokua',
                array_merge([
                    'kind' => 'request',
                    'title' => 'Valid title',
                    'details' => 'Valid details',
                    'category' => 'everyday',
                ], $case),
                $this->aliceCookies,
                $this->aliceCsrf,
            );

            self::assertSame(422, $response->status, $field . ': ' . $response->body);
            self::assertStringContainsString($field, $response->body);
        }
    }
}
