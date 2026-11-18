<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Service\UpdatePost;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Server\Community\CommunityException;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

/**
 * The host-owned community surfaces: announcements, featured tags, trends,
 * memories, stories, collections and post revisions.
 *
 * The service tests run against the real runtime and SQLite database from
 * TestServer; the HTTP tests exercise the session, CSRF and authorisation
 * boundary in front of them.
 */
final class CommunityTest extends TestCase
{
    private TestServer $server;

    private string $alice;

    private string $bob;

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

        $this->alice = 'alice';
        $this->bob = 'bob';
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

    private function post(string $authorId, string $content, Visibility $visibility = Visibility::Public): string
    {
        return $this->server->runtime->app->posts
            ->create(new CreatePost($authorId, $content, $visibility))
            ->id;
    }

    public function testAnnouncementsAreAdminOnlyAndExpire(): void
    {
        $anonymous = $this->server->json('POST', '/api/v1/host/announcements', ['content' => 'Hello']);
        self::assertSame(403, $anonymous->status);

        $nonAdmin = $this->server->json(
            'POST',
            '/api/v1/host/announcements',
            ['content' => 'Hello'],
            $this->bobCookies,
            $this->bobCsrf,
        );
        self::assertSame(403, $nonAdmin->status);
        self::assertStringContainsString('admin_required', $nonAdmin->body);

        $created = $this->server->json(
            'POST',
            '/api/v1/host/announcements',
            ['content' => '  Wartungsarbeiten heute Abend.  '],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame(201, $created->status);
        $announcement = $this->decoded($created)['announcement'];
        self::assertSame('Wartungsarbeiten heute Abend.', $announcement['content']);
        self::assertTrue($announcement['active']);

        $public = $this->server->send('GET', '/api/v1/host/announcements');
        self::assertSame(200, $public->status);
        self::assertCount(1, $this->decoded($public)['announcements']);

        $all = $this->server->send('GET', '/api/v1/host/announcements', [
            'query' => ['all' => '1'],
        ]);
        self::assertSame(403, $all->status);
        $all = $this->server->send('GET', '/api/v1/host/announcements', [
            'query' => ['all' => '1'],
            'cookies' => $this->aliceCookies,
        ]);
        self::assertSame(200, $all->status);
        self::assertCount(1, $this->decoded($all)['announcements']);

        $past = $this->server->json(
            'POST',
            '/api/v1/host/announcements',
            ['content' => 'Too late', 'expires_at' => '2020-01-01T00:00:00Z'],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame(422, $past->status);
        self::assertStringContainsString('expires_at', $past->body);

        $empty = $this->server->json(
            'POST',
            '/api/v1/host/announcements',
            ['content' => '   '],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame(422, $empty->status);

        $hidden = $this->server->json(
            'PATCH',
            '/api/v1/host/announcements/' . $announcement['id'],
            ['active' => false],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame(200, $hidden->status);
        self::assertFalse($this->decoded($hidden)['announcement']['active']);

        $public = $this->server->send('GET', '/api/v1/host/announcements');
        self::assertCount(0, $this->decoded($public)['announcements']);

        $deleted = $this->server->json(
            'DELETE',
            '/api/v1/host/announcements/' . $announcement['id'],
            [],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame(200, $deleted->status);
        self::assertNull($this->server->runtime->announcements->find($announcement['id']));
    }

    public function testFeaturedTagsAreNormalisedAndKeepTheirOrder(): void
    {
        $replaced = $this->server->json('PUT', '/api/v1/host/featured-tags', [
            'tags' => ['#Aloha', 'aloha', ' PHP ', 'php', 'with space', ''],
        ], $this->aliceCookies, $this->aliceCsrf);

        self::assertSame(200, $replaced->status);
        $tags = $this->decoded($replaced)['tags'];
        self::assertSame(['aloha', 'php', 'withspace'], array_column($tags, 'tag'));
        self::assertSame([0, 1, 2], array_column($tags, 'position'));

        $profile = $this->server->send(
            'GET',
            '/api/v1/host/featured-tags',
            ['query' => ['account' => 'alice']],
        );
        self::assertSame(200, $profile->status);
        self::assertSame(['aloha', 'php', 'withspace'], array_column($this->decoded($profile)['tags'], 'tag'));

        $many = [];
        for ($i = 0; $i < 15; $i++) {
            $many[] = 'tag' . $i;
        }
        $capped = $this->server->json(
            'PUT',
            '/api/v1/host/featured-tags',
            ['tags' => $many],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame(200, $capped->status);
        self::assertCount(10, $this->decoded($capped)['tags']);

        $cleared = $this->server->json(
            'PUT',
            '/api/v1/host/featured-tags',
            ['tags' => []],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame(200, $cleared->status);
        self::assertSame([], $this->decoded($cleared)['tags']);
    }

    public function testTrendsAggregateRecentPublicHashtagsAndLinks(): void
    {
        $this->post($this->alice, 'Hello #Aloha and #Fediverse https://example.test/one');
        $this->post($this->bob, 'Another #aloha https://example.test/one');
        $this->post($this->bob, 'Private #aloha', Visibility::Followers);

        $response = $this->server->send('GET', '/api/v1/host/trends', [
            'query' => ['days' => '7', 'limit' => '10'],
        ]);
        self::assertSame(200, $response->status);
        $body = $this->decoded($response);

        self::assertGreaterThanOrEqual(1, count($body['tags']));
        $aloha = array_values(array_filter(
            $body['tags'],
            static fn (array $tag): bool => $tag['tag'] === 'aloha',
        ))[0] ?? null;
        self::assertNotNull($aloha);
        self::assertSame(2, $aloha['posts']);
        self::assertSame(2, $aloha['accounts']);

        self::assertSame('example.test', $body['links'][0]['host']);
        self::assertSame(2, $body['links'][0]['posts']);
    }

    public function testMemoriesGroupEarlierYearsOfTheSameDay(): void
    {
        $old = $this->post($this->alice, 'One year ago');
        $older = $this->post($this->alice, 'Two years ago');
        $current = $this->post($this->alice, 'Today');

        $now = $this->server->runtime->clock->now();
        $this->server->runtime->database->execute(
            'UPDATE social_post SET published_at = :at WHERE id = :id',
            ['at' => $now->modify('-1 year')->format('Y-m-d\TH:i:s\Z'), 'id' => $old],
        );
        $this->server->runtime->database->execute(
            'UPDATE social_post SET published_at = :at WHERE id = :id',
            ['at' => $now->modify('-2 years')->format('Y-m-d\TH:i:s\Z'), 'id' => $older],
        );

        $memories = $this->server->runtime->memories->forAccount($this->alice);

        self::assertSame($now->format('m-d'), $memories['date']);
        self::assertSame(2, $memories['total']);
        self::assertSame(
            [(int) $now->format('Y') - 1, (int) $now->format('Y') - 2],
            array_column($memories['years'], 'year'),
        );
        self::assertSame($old, $memories['years'][0]['posts'][0]['id']);

        $other = $this->server->runtime->memories->forAccount($this->bob);
        self::assertSame(0, $other['total']);
        self::assertNotContains($current, array_merge(...array_map(
            static fn (array $year): array => array_column($year['posts'], 'id'),
            $memories['years'],
        )));
    }

    public function testStoriesExpireAndBelongToTheirAuthor(): void
    {
        $story = $this->server->runtime->stories->create($this->alice, 'Sunset walk', 'sunset');
        self::assertSame('sunset', $story['background']);
        self::assertSame($this->alice, $story['account_id']);

        $active = $this->server->runtime->stories->active();
        self::assertCount(1, $active['stories']);
        self::assertSame($this->alice, $active['groups'][0]['account_id']);

        try {
            $this->server->runtime->stories->delete($story['id'], $this->bob);
            self::fail('Another account must not delete the story.');
        } catch (CommunityException $exception) {
            self::assertSame(403, $exception->status);
        }

        $this->server->runtime->database->execute(
            'UPDATE story SET expires_at = :at WHERE id = :id',
            ['at' => Time::format($this->server->runtime->clock->now()->modify('-1 minute')), 'id' => $story['id']],
        );

        self::assertCount(0, $this->server->runtime->stories->active()['stories']);
        self::assertCount(0, $this->server->runtime->stories->forAccount($this->alice));

        $this->server->runtime->stories->delete($story['id'], $this->alice);
        self::assertNull($this->server->runtime->stories->find($story['id']));

        try {
            $this->server->runtime->stories->create($this->alice, '');
            self::fail('An empty story must be refused.');
        } catch (CommunityException $exception) {
            self::assertSame(422, $exception->status);
            self::assertArrayHasKey('content', $exception->fields);
        }
    }

    public function testCollectionsAreOrderedPrivateToTheirOwnerAndReferencePosts(): void
    {
        $first = $this->post($this->bob, 'First post');
        $second = $this->post($this->bob, 'Second post');

        $created = $this->server->json('POST', '/api/v1/host/collections', [
            'title' => '  Reading list ',
            'description' => 'Good reads',
            'is_public' => true,
        ], $this->aliceCookies, $this->aliceCsrf);
        self::assertSame(201, $created->status);
        $collection = $this->decoded($created)['collection'];
        self::assertSame('Reading list', $collection['title']);
        self::assertTrue($collection['is_public']);

        $added = $this->server->json(
            'POST',
            '/api/v1/host/collections/' . $collection['id'] . '/items',
            ['post_id' => $first],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame(200, $added->status);
        self::assertSame([$first], $this->decoded($added)['collection']['post_ids']);

        $again = $this->server->json(
            'POST',
            '/api/v1/host/collections/' . $collection['id'] . '/items',
            ['post_id' => $first],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame([$first], $this->decoded($again)['collection']['post_ids']);

        $addedSecond = $this->server->json(
            'POST',
            '/api/v1/host/collections/' . $collection['id'] . '/items',
            ['post_id' => $second],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame([$first, $second], $this->decoded($addedSecond)['collection']['post_ids']);

        $public = $this->server->send('GET', '/api/v1/host/collection', [
            'query' => ['id' => $collection['id']],
        ]);
        self::assertSame(200, $public->status);
        self::assertSame([$first, $second], $this->decoded($public)['collection']['post_ids']);

        $private = $this->server->runtime->collections->create($this->alice, 'Private notes', '', false);
        $hidden = $this->server->send('GET', '/api/v1/host/collection', [
            'query' => ['id' => $private['id']],
        ]);
        self::assertSame(404, $hidden->status);

        $forbidden = $this->server->json(
            'PATCH',
            '/api/v1/host/collections/' . $collection['id'],
            ['title' => 'Hijacked'],
            $this->bobCookies,
            $this->bobCsrf,
        );
        self::assertSame(403, $forbidden->status);

        $removed = $this->server->json(
            'DELETE',
            '/api/v1/host/collections/' . $collection['id'] . '/items/' . $first,
            [],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame([$second], $this->decoded($removed)['collection']['post_ids']);

        $deleted = $this->server->json(
            'DELETE',
            '/api/v1/host/collections/' . $collection['id'],
            [],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame(200, $deleted->status);
        self::assertNull($this->server->runtime->collections->find($collection['id'], $this->alice));
    }

    public function testPublicCollectionsHidePostsTheViewerCannotRead(): void
    {
        // Alice follows Bob, so she may read his followers-only post and add it
        // to a collection she later makes public.
        $this->server->runtime->database->execute(
            'INSERT INTO social_follower (id, local_user_id, actor_id, inbox_url, followed_at, is_local, state)'
            . " VALUES ('f-local-alice', 'bob', 'http://localhost:3000/@alice', 'http://localhost:3000/inbox', '2026-11-16T00:00:00Z', 1, 'accepted')",
        );
        $restricted = $this->post($this->bob, 'Followers only', Visibility::Followers);

        $collection = $this->server->runtime->collections->create($this->alice, 'Later public', '', false);
        $added = $this->server->runtime->collections->addItem($collection['id'], $this->alice, $restricted);
        self::assertSame([$restricted], $added['post_ids']);

        $this->server->runtime->collections->update($collection['id'], $this->alice, null, null, true);

        $anonymous = $this->server->send('GET', '/api/v1/host/collection', [
            'query' => ['id' => $collection['id']],
        ]);
        self::assertSame(200, $anonymous->status);
        $anonymousCollection = $this->decoded($anonymous)['collection'];
        self::assertSame([], $anonymousCollection['post_ids']);
        self::assertSame(0, $anonymousCollection['item_count']);

        $owner = $this->server->send('GET', '/api/v1/host/collection', [
            'query' => ['id' => $collection['id']],
            'cookies' => $this->aliceCookies,
        ]);
        self::assertSame([$restricted], $this->decoded($owner)['collection']['post_ids']);

        // Once the follow is gone, even the signed-in follower no longer sees
        // the id in the public collection.
        $this->server->runtime->database->execute(
            "DELETE FROM social_follower WHERE id = 'f-local-alice'",
        );
        $formerFollower = $this->server->send('GET', '/api/v1/host/collection', [
            'query' => ['id' => $collection['id']],
            'cookies' => $this->aliceCookies,
        ]);
        self::assertSame([], $this->decoded($formerFollower)['collection']['post_ids']);
    }

    public function testPublicCollectionsRejectNonPublicPostsAtInsertion(): void
    {
        $restricted = $this->post($this->bob, 'Followers only', Visibility::Followers);
        $collection = $this->server->runtime->collections->create($this->alice, 'Public');

        $this->expectException(CommunityException::class);
        $this->server->runtime->collections->addItem($collection['id'], $this->alice, $restricted);
    }

    public function testDirectMessagesCannotBeCollected(): void
    {
        $direct = $this->server->runtime->app->posts
            ->create(new CreatePost($this->bob, 'Secret', Visibility::Direct, recipientIds: [$this->alice]))
            ->id;
        $collection = $this->server->runtime->collections->create($this->bob, 'Mine');

        try {
            $this->server->runtime->collections->addItem($collection['id'], $this->bob, $direct);
            self::fail('A direct message must not be added to a collection.');
        } catch (CommunityException $exception) {
            self::assertSame(422, $exception->status);
        }
    }

    public function testPostRevisionsListSupersededVersions(): void
    {
        $postId = $this->post($this->alice, 'Version one');

        $this->server->runtime->app->posts->edit($this->alice, $postId, new UpdatePost(content: 'Version two'));
        $this->server->runtime->app->posts->edit($this->alice, $postId, new UpdatePost(content: 'Version three'));

        $response = $this->server->send(
            'GET',
            '/api/v1/host/post-revisions',
            ['query' => ['id' => $postId], 'cookies' => $this->aliceCookies],
        );
        self::assertSame(200, $response->status);
        $body = $this->decoded($response);

        self::assertSame(3, $body['current_revision']);
        self::assertSame('Version three', $body['current']['content']);
        self::assertSame([1, 2], array_column($body['revisions'], 'revision'));
        self::assertSame('Version one', $body['revisions'][0]['content']);
        self::assertSame('Version two', $body['revisions'][1]['content']);

        $hidden = $this->post($this->bob, 'Bob secret', Visibility::Followers);
        $missing = $this->server->send(
            'GET',
            '/api/v1/host/post-revisions',
            ['query' => ['id' => $hidden], 'cookies' => $this->aliceCookies],
        );
        self::assertSame(404, $missing->status);
    }

    public function testCommunityMutationsRequireCsrf(): void
    {
        $response = $this->server->json('POST', '/api/v1/host/stories', [
            'content' => 'No token',
        ], $this->aliceCookies);

        self::assertSame(403, $response->status);
        self::assertStringContainsString('csrf_token_invalid', $response->body);
    }
}
