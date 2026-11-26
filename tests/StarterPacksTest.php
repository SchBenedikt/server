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
 * Starter packs are a host directory over the normal follow services. The
 * tests cover the lifecycle, the local member resolver and the owner rule.
 */
final class StarterPacksTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $body): array
    {
        return json_decode((string) $body, true, 64, JSON_THROW_ON_ERROR);
    }

    public function testPackLifecycleWithALocalMember(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test');
        $server->createAccount('bob', 'bob@example.test');
        $alice = $server->signIn('alice');

        $created = $server->json(
            'POST',
            '/api/v1/host/starter-packs',
            ['title' => 'Builders', 'description' => 'People who build things'],
            $alice['cookies'],
            $alice['csrf'],
        );
        self::assertSame(201, $created->status, $created->body);
        $pack = $this->decode($created->body)['starter_pack'];
        self::assertSame('Builders', $pack['title']);
        self::assertSame('alice', $pack['owner_handle']);
        self::assertSame(0, $pack['member_count']);
        self::assertSame([], $pack['members']);

        $path = '/api/v1/host/starter-packs/' . rawurlencode($pack['id']) . '/members';
        $added = $server->json('POST', $path, ['member' => 'bob'], $alice['cookies'], $alice['csrf']);
        self::assertSame(201, $added->status, $added->body);
        $addedPack = $this->decode($added->body)['starter_pack'];
        self::assertCount(1, $addedPack['members']);
        self::assertSame('local', $addedPack['members'][0]['network']);
        self::assertSame('bob', $addedPack['members'][0]['member_id']);
        self::assertSame('bob', $addedPack['members'][0]['handle']);

        // The same account is stored once, not once per request.
        $duplicate = $server->json('POST', $path, ['member' => '@bob'], $alice['cookies'], $alice['csrf']);
        self::assertSame(201, $duplicate->status, $duplicate->body);
        self::assertSame(1, $this->decode($duplicate->body)['starter_pack']['member_count']);

        $list = $server->send('GET', '/api/v1/host/starter-packs', ['cookies' => $alice['cookies']]);
        self::assertSame(200, $list->status);
        $listed = $this->decode($list->body);
        self::assertCount(1, $listed['mine']);
        self::assertSame(1, $listed['mine'][0]['member_count']);
        self::assertSame([], $listed['discover']);

        $memberRowId = $this->decode($duplicate->body)['starter_pack']['members'][0]['id'];
        $removed = $server->json(
            'DELETE',
            '/api/v1/host/starter-packs/' . rawurlencode($pack['id']) . '/members/' . rawurlencode($memberRowId),
            [],
            $alice['cookies'],
            $alice['csrf'],
        );
        self::assertSame(200, $removed->status, $removed->body);
        self::assertSame(0, $this->decode($removed->body)['starter_pack']['member_count']);

        $deleted = $server->json(
            'DELETE',
            '/api/v1/host/starter-packs/' . rawurlencode($pack['id']),
            [],
            $alice['cookies'],
            $alice['csrf'],
        );
        self::assertSame(200, $deleted->status, $deleted->body);
        $missing = $server->send('GET', '/api/v1/host/starter-packs/' . rawurlencode($pack['id']));
        self::assertSame(404, $missing->status);
    }

    public function testOnlyTheOwnerCanChangeAPack(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test');
        $server->createAccount('bob', 'bob@example.test');
        $alice = $server->signIn('alice');
        $bob = $server->signIn('bob');

        $created = $server->json(
            'POST',
            '/api/v1/host/starter-packs',
            ['title' => 'Alice picks'],
            $alice['cookies'],
            $alice['csrf'],
        );
        $pack = $this->decode($created->body)['starter_pack'];
        $path = '/api/v1/host/starter-packs/' . rawurlencode($pack['id']);

        self::assertSame(403, $server->json('PATCH', $path, ['title' => 'Hijacked'], $bob['cookies'], $bob['csrf'])->status);
        self::assertSame(403, $server->json('DELETE', $path, [], $bob['cookies'], $bob['csrf'])->status);
        self::assertSame(403, $server->json('POST', $path . '/members', ['member' => 'bob'], $bob['cookies'], $bob['csrf'])->status);

        $visible = $server->send('GET', $path, ['cookies' => $bob['cookies']]);
        self::assertSame(200, $visible->status);
        self::assertSame('Alice picks', $this->decode($visible->body)['starter_pack']['title']);
    }

    public function testDiscoverShowsAnotherMembersPublicPack(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test');
        $server->createAccount('bob', 'bob@example.test');
        $alice = $server->signIn('alice');
        $bob = $server->signIn('bob');

        self::assertSame(201, $server->json(
            'POST',
            '/api/v1/host/starter-packs',
            ['title' => 'Bob recommends'],
            $bob['cookies'],
            $bob['csrf'],
        )->status);

        $list = $server->send('GET', '/api/v1/host/starter-packs', ['cookies' => $alice['cookies']]);
        $listed = $this->decode($list->body);
        self::assertCount(0, $listed['mine']);
        self::assertCount(1, $listed['discover']);
        self::assertSame('Bob recommends', $listed['discover'][0]['title']);
    }

    public function testExportAndErasureIncludeStarterPacks(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test');
        $server->createAccount('bob', 'bob@example.test');
        $alice = $server->signIn('alice');
        $bob = $server->signIn('bob');

        $alicePack = $this->decode($server->json(
            'POST',
            '/api/v1/host/starter-packs',
            ['title' => 'Alice pack'],
            $alice['cookies'],
            $alice['csrf'],
        )->body)['starter_pack'];
        $server->json(
            'POST',
            '/api/v1/host/starter-packs/' . rawurlencode($alicePack['id']) . '/members',
            ['member' => 'bob'],
            $alice['cookies'],
            $alice['csrf'],
        );
        // Bob lists Alice in his own pack: erasure must drop that membership
        // without touching Bob's pack.
        $bobPack = $this->decode($server->json(
            'POST',
            '/api/v1/host/starter-packs',
            ['title' => 'Bob pack'],
            $bob['cookies'],
            $bob['csrf'],
        )->body)['starter_pack'];
        $server->json(
            'POST',
            '/api/v1/host/starter-packs/' . rawurlencode($bobPack['id']) . '/members',
            ['member' => 'alice'],
            $bob['cookies'],
            $bob['csrf'],
        );

        $export = $server->send('GET', '/api/v1/host/export', ['cookies' => $alice['cookies']]);
        self::assertSame(200, $export->status);
        $exported = $this->decode($export->body);
        self::assertCount(1, $exported['starter_packs']);
        self::assertSame('Alice pack', $exported['starter_packs'][0]['title']);
        self::assertSame('bob', $exported['starter_packs'][0]['members'][0]['member_id']);

        $server->runtime->accountErasureCleanup->erase('alice');
        self::assertSame(0, (int) $server->runtime->database->fetchValue(
            'SELECT COUNT(*) FROM social_starter_pack WHERE title = :title',
            ['title' => 'Alice pack'],
        ));
        self::assertSame(1, (int) $server->runtime->database->fetchValue(
            'SELECT COUNT(*) FROM social_starter_pack WHERE title = :title',
            ['title' => 'Bob pack'],
        ));
        self::assertSame(0, (int) $server->runtime->database->fetchValue(
            'SELECT COUNT(*) FROM social_starter_pack_member WHERE member_id = :member',
            ['member' => 'alice'],
        ));
    }

    public function testPackNeedsATitleAndAVisibleMember(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test');
        $alice = $server->signIn('alice');

        $empty = $server->json(
            'POST',
            '/api/v1/host/starter-packs',
            ['title' => '   '],
            $alice['cookies'],
            $alice['csrf'],
        );
        self::assertSame(422, $empty->status);
        self::assertSame('validation_failed', $this->decode($empty->body)['error']['code']);

        self::assertSame(401, $server->send('GET', '/api/v1/host/starter-packs')->status);
    }
}
