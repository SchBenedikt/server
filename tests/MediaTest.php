<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class MediaTest extends TestCase
{
    private const PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function testUploadServeAndAttachAnImage(): void
    {
        $server = new TestServer();
        $server->createAccount();
        $session = $server->signIn();

        $png = base64_decode(self::PIXEL_PNG, true);
        self::assertIsString($png);

        $upload = $server->send('POST', '/api/v1/media', [
            'cookies' => $session['cookies'],
            'headers' => [
                'Content-Type' => $this->multipartContentType(),
                'X-CSRF-Token' => $session['csrf'],
            ],
            'body' => $this->multipartBody($png),
        ]);

        self::assertSame(201, $upload->status, $upload->body);

        /** @var array{attachment: array{id: string}} $body */
        $body = json_decode($upload->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('attachment', $body);
        $id = $body['attachment']['id'];

        $served = $server->send('GET', '/api/v1/media/' . $id);
        self::assertSame(200, $served->status);
        self::assertSame('image/png', $served->header('Content-Type'));
        self::assertSame($png, $served->body);

        $thumbnail = $server->send('GET', '/api/v1/media/' . $id, ['query' => ['variant' => 'thumbnail']]);
        self::assertSame(200, $thumbnail->status);
        self::assertSame('image/png', $thumbnail->header('Content-Type'));

        $created = $server->json('POST', '/api/v1/posts', [
            'content' => 'A post with an image',
            'visibility' => 'public',
            'attachment_ids' => [$id],
        ], $session['cookies'], $session['csrf']);

        self::assertSame(201, $created->status, $created->body);
        self::assertStringContainsString($id, $created->body);

        /** @var array{post: array{id: string}} $post */
        $post = json_decode($created->body, true, 512, JSON_THROW_ON_ERROR);

        $read = $server->send('GET', '/api/v1/posts/' . $post['post']['id']);
        self::assertSame(200, $read->status);
        self::assertStringContainsString('/api/v1/media/' . $id, $read->body);
    }

    public function testUploadWithoutCsrfIsRejected(): void
    {
        $server = new TestServer();
        $server->createAccount();
        $session = $server->signIn();

        $png = base64_decode(self::PIXEL_PNG, true);
        self::assertIsString($png);

        $upload = $server->send('POST', '/api/v1/media', [
            'cookies' => $session['cookies'],
            'headers' => ['Content-Type' => $this->multipartContentType()],
            'body' => $this->multipartBody($png),
        ]);

        self::assertSame(403, $upload->status);
    }

    private function multipartContentType(): string
    {
        return 'multipart/form-data; boundary=' . self::boundary();
    }

    private static function boundary(): string
    {
        return '----AlohaSocialPHPUnitBoundary';
    }

    private function multipartBody(string $bytes): string
    {
        $boundary = self::boundary();

        return '--' . $boundary . "\r\n"
            . "Content-Disposition: form-data; name=\"alt_text\"\r\n\r\n"
            . "A single pixel\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"pixel.png\"\r\n"
            . "Content-Type: image/png\r\n\r\n"
            . $bytes . "\r\n"
            . '--' . $boundary . "--\r\n";
    }
}
