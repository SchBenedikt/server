<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Storage;

use Aws\Exception\AwsException;
use Aws\S3\S3ClientInterface;
use AlohaSocial\Core\Platform\Storage\S3CompatibleClient;
use AlohaSocial\Core\Platform\Storage\S3ObjectPage;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\StreamWrapper;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

use function fclose;
use function in_array;
use function is_int;
use function is_resource;
use function preg_match;

/**
 * AWS SDK implementation of Core's host-injected S3 port.
 *
 * This adapter never sets object ACLs. Reads check the object size before
 * transfer and use a bounded byte range as a second guard against a key being
 * replaced between HEAD and GET.
 */
final class AwsS3CompatibleClient implements S3CompatibleClient
{
    public function __construct(
        private readonly S3ClientInterface $client,
    ) {
    }

    public function putObject(
        string $bucket,
        string $key,
        mixed $body,
        int $contentLength,
        ?string $serverSideEncryption,
        ?string $kmsKeyId,
    ): void {
        $request = [
            'Bucket' => $bucket,
            'Key' => $key,
            'Body' => new NonClosingStream(new Stream($body)),
            'ContentLength' => $contentLength,
        ];
        if ($serverSideEncryption !== null) {
            $request['ServerSideEncryption'] = $serverSideEncryption;
        }
        if ($kmsKeyId !== null) {
            $request['SSEKMSKeyId'] = $kmsKeyId;
        }

        $this->client->putObject($request);
    }

    public function getObject(string $bucket, string $key, int $maxBytes): mixed
    {
        $head = $this->head($bucket, $key);
        if ($head === null) {
            return null;
        }
        if ($head['size'] > $maxBytes) {
            throw new RuntimeException('The S3 object is larger than the configured read limit.');
        }

        $request = ['Bucket' => $bucket, 'Key' => $key];
        if ($head['etag'] !== null) {
            $request['IfMatch'] = $head['etag'];
        }
        if ($head['size'] > 0) {
            // The inclusive range allows one excess byte to prove that the
            // object grew after HEAD; that byte is rejected before returning.
            $request['Range'] = 'bytes=0-' . $maxBytes;
        }

        $result = $this->client->getObject($request);
        $length = $result->get('ContentLength');
        if (is_int($length) && $length > $maxBytes) {
            $this->closeBody($result->get('Body'));
            throw new RuntimeException('The S3 object is larger than the configured read limit.');
        }

        $contentRange = $result->get('ContentRange');
        if (is_string($contentRange)
            && preg_match('#^bytes \d+-\d+/(\d+)$#', $contentRange, $matches) === 1
            && (int) $matches[1] > $maxBytes
        ) {
            $this->closeBody($result->get('Body'));
            throw new RuntimeException('The S3 object is larger than the configured read limit.');
        }

        $body = $result->get('Body');
        if (!$body instanceof StreamInterface) {
            throw new RuntimeException('The S3 client returned an unreadable object body.');
        }

        return StreamWrapper::getResource($body);
    }

    public function headObject(string $bucket, string $key): ?int
    {
        return $this->head($bucket, $key)['size'] ?? null;
    }

    public function deleteObject(string $bucket, string $key): void
    {
        $this->client->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
    }

    public function listObjects(
        string $bucket,
        string $prefix,
        ?string $continuationToken,
        int $maxKeys,
    ): S3ObjectPage {
        $request = ['Bucket' => $bucket, 'Prefix' => $prefix, 'MaxKeys' => $maxKeys];
        if ($continuationToken !== null) {
            $request['ContinuationToken'] = $continuationToken;
        }

        $result = $this->client->listObjectsV2($request);
        $keys = [];
        foreach ($result->get('Contents') ?? [] as $object) {
            if (is_array($object) && is_string($object['Key'] ?? null)) {
                $keys[] = $object['Key'];
            }
        }

        $next = $result->get('NextContinuationToken');
        if ($result->get('IsTruncated') && (!is_string($next) || $next === '')) {
            throw new RuntimeException('The S3 listing omitted its continuation token.');
        }

        return new S3ObjectPage($keys, is_string($next) && $next !== '' ? $next : null);
    }

    /** @return array{size: int, etag: string|null}|null */
    private function head(string $bucket, string $key): ?array
    {
        try {
            $result = $this->client->headObject(['Bucket' => $bucket, 'Key' => $key]);
        } catch (AwsException $exception) {
            if ($this->isMissingObject($exception)) {
                return null;
            }
            throw $exception;
        }

        $size = $result->get('ContentLength');
        if (!is_int($size) || $size < 0) {
            throw new RuntimeException('The S3 client returned an invalid object size.');
        }

        $etag = $result->get('ETag');

        return ['size' => $size, 'etag' => is_string($etag) && $etag !== '' ? $etag : null];
    }

    private function isMissingObject(AwsException $exception): bool
    {
        return in_array($exception->getAwsErrorCode(), ['NoSuchKey', 'NotFound'], true)
            || ($exception->getStatusCode() === 404 && $exception->getAwsErrorCode() === null);
    }

    private function closeBody(mixed $body): void
    {
        if ($body instanceof StreamInterface) {
            $body->close();
        } elseif (is_resource($body)) {
            fclose($body);
        }
    }
}

/** AWS SDK requests must not take ownership of Core's upload stream. */
final class NonClosingStream implements StreamInterface
{
    use StreamDecoratorTrait;

    public function __destruct()
    {
        // StreamDecoratorTrait wraps a Guzzle Stream whose destructor closes
        // its resource. Detach it here so the caller remains the sole owner.
        $this->stream->detach();
    }

    public function close(): void
    {
        // Core owns and closes the original resource after the store returns.
    }

    public function detach(): mixed
    {
        // Keep the caller's PHP stream attached and under its ownership.
        return null;
    }
}
