<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests\Storage;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use AlohaSocial\Server\Storage\AwsS3CompatibleClient;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

final class AwsS3CompatibleClientTest extends TestCase
{
    public function testPutStreamsTheBodyWithoutSettingAnObjectAcl(): void
    {
        $commands = [];
        $handler = new MockHandler([
            static function (CommandInterface $command, RequestInterface $request) use (&$commands): Result {
                $commands[] = [
                    'name' => $command->getName(),
                    'args' => $command->toArray(),
                    'headers' => [
                        'acl' => $request->getHeaderLine('x-amz-acl'),
                        'content_length' => $request->getHeaderLine('Content-Length'),
                    ],
                ];
                return new Result(['ETag' => '"stored"']);
            },
        ]);
        $body = fopen('php://temp', 'w+b');
        fwrite($body, 'image-bytes');
        rewind($body);

        try {
            $this->adapter($handler)->putObject('aloha-media', 'media/1', $body, 11, 'aws:kms', 'alias/media');

            self::assertCount(1, $commands);
            self::assertSame('PutObject', $commands[0]['name']);
            self::assertSame('aws:kms', $commands[0]['args']['ServerSideEncryption']);
            self::assertSame('alias/media', $commands[0]['args']['SSEKMSKeyId']);
            self::assertArrayNotHasKey('ACL', $commands[0]['args']);
            self::assertSame('', $commands[0]['headers']['acl']);
            self::assertSame('11', $commands[0]['headers']['content_length']);
            self::assertTrue(is_resource($body), 'The upload caller retains ownership of its stream.');
        } finally {
            if (is_resource($body)) {
                fclose($body);
            }
        }
    }

    public function testGetReturnsAReadableBoundedResourceAndUsesTheHeadEtag(): void
    {
        $commands = [];
        $handler = new MockHandler([
            static function (CommandInterface $command) use (&$commands): Result {
                $commands[] = ['name' => $command->getName(), 'args' => $command->toArray()];
                return new Result(['ContentLength' => 5, 'ETag' => '"stable"']);
            },
            static function (CommandInterface $command) use (&$commands): Result {
                $commands[] = ['name' => $command->getName(), 'args' => $command->toArray()];
                return new Result([
                    'ContentLength' => 5,
                    'ContentRange' => 'bytes 0-4/5',
                    'Body' => Utils::streamFor('hello'),
                ]);
            },
        ]);

        $stream = $this->adapter($handler)->getObject('aloha-media', 'media/1', 5);
        self::assertTrue(is_resource($stream));
        self::assertSame('hello', stream_get_contents($stream));
        fclose($stream);

        self::assertSame(['HeadObject', 'GetObject'], array_column($commands, 'name'));
        self::assertSame('"stable"', $commands[1]['args']['IfMatch']);
        self::assertSame('bytes=0-5', $commands[1]['args']['Range']);
    }

    public function testGetRejectsAnOversizedObjectBeforeDownloadingIt(): void
    {
        $handler = new MockHandler([
            new Result(['ContentLength' => 6, 'ETag' => '"large"']),
        ]);

        try {
            $this->adapter($handler)->getObject('aloha-media', 'media/large', 5);
            self::fail('An object larger than the configured limit must be refused.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('read limit', $exception->getMessage());
        }

        self::assertCount(0, $handler, 'The adapter must reject from HEAD without issuing GET.');
    }

    public function testListPassesPageLimitAndContinuationToken(): void
    {
        $commands = [];
        $handler = new MockHandler([
            static function (CommandInterface $command) use (&$commands): Result {
                $commands[] = $command->toArray();
                return new Result([
                    'Contents' => [['Key' => 'social/media/2'], ['Key' => 'social/media/3']],
                    'IsTruncated' => true,
                    'NextContinuationToken' => 'next-page',
                ]);
            },
        ]);

        $page = $this->adapter($handler)->listObjects('aloha-media', 'social/media/', 'prior-page', 2);

        self::assertSame(['social/media/2', 'social/media/3'], $page->keys);
        self::assertSame('next-page', $page->nextContinuationToken);
        self::assertSame('prior-page', $commands[0]['ContinuationToken']);
        self::assertSame(2, $commands[0]['MaxKeys']);
    }

    private function adapter(MockHandler $handler): AwsS3CompatibleClient
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
            'endpoint' => 'https://s3.example.test',
            'handler' => $handler,
            'retries' => 0,
        ]);

        return new AwsS3CompatibleClient($client);
    }
}
