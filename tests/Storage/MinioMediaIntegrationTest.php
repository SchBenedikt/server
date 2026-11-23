<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests\Storage;

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Server\Config\HostConfig;
use AlohaSocial\Server\Storage\StorageFactory;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

/**
 * Opt-in live MinIO/S3 request-path checks. Configure a dedicated disposable
 * bucket with ALOHASOCIAL_S3_TEST_* variables before running this class.
 */
final class MinioMediaIntegrationTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function testS3MediaUploadServesOnlyToAuthorizedViewers(): void
    {
        $required = ['ENDPOINT', 'BUCKET', 'ACCESS_KEY', 'SECRET_KEY'];
        $credentials = [];
        foreach ($required as $name) {
            $value = getenv('ALOHASOCIAL_S3_TEST_' . $name);
            if (!is_string($value) || $value === '') {
                self::markTestSkipped('Set ALOHASOCIAL_S3_TEST_* to run the live MinIO media integration.');
            }
            $credentials[$name] = $value;
        }

        $prefix = 'aloha-integration-' . bin2hex(random_bytes(8));
        $overrides = [
            'debug' => true,
            'storage_driver' => 's3',
            's3_endpoint' => $credentials['ENDPOINT'],
            's3_region' => getenv('ALOHASOCIAL_S3_TEST_REGION') ?: 'us-east-1',
            's3_bucket' => $credentials['BUCKET'],
            's3_prefix' => $prefix,
            's3_access_key' => $credentials['ACCESS_KEY'],
            's3_secret_key' => $credentials['SECRET_KEY'],
            's3_path_style' => true,
            's3_server_side_encryption' => null,
        ];
        $config = HostConfig::load(dirname(__DIR__, 2), $overrides);
        $store = StorageFactory::s3($config)->blobStore;
        $server = new TestServer($overrides, s3Client: StorageFactory::s3Client($config));

        try {
            $server->createAccount('alice', 'alice@example.test', TestServer::PASSWORD, true);
            $server->createAccount('bob');
            $alice = $server->signIn('alice');
            $bob = $server->signIn('bob');
            $publicMedia = $this->upload($server, $alice['cookies'], $alice['csrf']);
            $publicPost = $server->runtime->app->posts->create(new CreatePost(
                'alice', 'S3 public media', Visibility::Public, attachmentIds: [$publicMedia],
            ));
            $this->assertStatus($server, $publicMedia, [], 200);

            $unlistedMedia = $this->upload($server, $alice['cookies'], $alice['csrf']);
            $server->runtime->app->posts->create(new CreatePost(
                'alice', 'S3 unlisted media', Visibility::Unlisted, attachmentIds: [$unlistedMedia],
            ));
            $this->assertStatus($server, $unlistedMedia, [], 200);

            $followersMedia = $this->upload($server, $alice['cookies'], $alice['csrf']);
            $server->runtime->app->posts->create(new CreatePost(
                'alice', 'S3 followers media', Visibility::Followers, attachmentIds: [$followersMedia],
            ));
            $this->assertStatus($server, $followersMedia, [], 404);
            $this->assertStatus($server, $followersMedia, $bob['cookies'], 404);
            $server->runtime->database->execute(
                'INSERT INTO social_follower (id, local_user_id, actor_id, inbox_url, followed_at, is_local, state)'
                . " VALUES ('s3-follower', 'alice', 'http://localhost:3000/@bob', 'http://localhost:3000/inbox', '2026-11-23T00:00:00Z', 1, 'accepted')",
            );
            $this->assertStatus($server, $followersMedia, $bob['cookies'], 200);

            $directMedia = $this->upload($server, $alice['cookies'], $alice['csrf']);
            $server->runtime->app->posts->create(new CreatePost(
                'alice', 'S3 direct media', Visibility::Direct,
                attachmentIds: [$directMedia], recipientIds: ['bob'],
            ));
            $this->assertStatus($server, $directMedia, [], 404);
            $this->assertStatus($server, $directMedia, $bob['cookies'], 200);

            $deletedMedia = $this->upload($server, $alice['cookies'], $alice['csrf']);
            $deletedPost = $server->runtime->app->posts->create(new CreatePost(
                'alice', 'S3 deleted media', Visibility::Public, attachmentIds: [$deletedMedia],
            ));
            $deleted = $server->send('DELETE', '/api/v1/host/admin/posts/' . $deletedPost->id, [
                'cookies' => $alice['cookies'],
                'headers' => ['X-CSRF-Token' => $alice['csrf']],
            ]);
            self::assertSame(204, $deleted->status, $deleted->body);
            $this->assertStatus($server, $deletedMedia, [], 404);
            $this->assertStatus($server, $deletedMedia, $alice['cookies'], 200);

            $served = $server->send('GET', '/api/v1/media/' . $publicMedia);
            self::assertSame('image/png', $served->header('Content-Type'));
            self::assertSame('A single pixel', $server->runtime->app->media?->find($publicMedia)?->altText);
            self::assertNotSame('', $publicPost->id);
        } finally {
            foreach ($store->list() as $path) {
                $store->delete($path);
            }
        }
    }

    /** @param array<string, string> $cookies */
    private function upload(TestServer $server, array $cookies, string $csrf): string
    {
        $png = base64_decode(self::PNG, true);
        self::assertIsString($png);
        $boundary = '----AlohaMinioIntegration';
        $body = '--' . $boundary . "\r\n"
            . "Content-Disposition: form-data; name=\"alt_text\"\r\n\r\n"
            . "A single pixel\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"pixel.png\"\r\n"
            . "Content-Type: image/png\r\n\r\n" . $png . "\r\n"
            . '--' . $boundary . "--\r\n";
        $response = $server->send('POST', '/api/v1/media', [
            'cookies' => $cookies,
            'headers' => [
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                'X-CSRF-Token' => $csrf,
            ],
            'body' => $body,
        ]);
        self::assertSame(201, $response->status, $response->body);
        $payload = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);

        return $payload['attachment']['id'];
    }

    /** @param array<string, string> $cookies */
    private function assertStatus(TestServer $server, string $id, array $cookies, int $expected): void
    {
        self::assertSame($expected, $server->send('GET', '/api/v1/media/' . $id, ['cookies' => $cookies])->status);
    }
}
