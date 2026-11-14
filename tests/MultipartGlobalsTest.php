<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Http\MultipartFormData;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Server\Http\GlobalsRequestFactory;
use PHPUnit\Framework\TestCase;

final class MultipartGlobalsTest extends TestCase
{
    public function testRebuiltBodyParsesWithTheCoreMultipartParser(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'aloha-upload-');
        self::assertIsString($tmp);
        $bytes = "\x89PNG\r\n\x1a\n-fake-image-bytes-\x00\x01";
        file_put_contents($tmp, $bytes);

        try {
            $rebuilt = GlobalsRequestFactory::multipartBody(
                ['alt_text' => 'A pixel'],
                [
                    'file' => [
                        'name' => 'pixel.png',
                        'type' => 'image/png',
                        'tmp_name' => $tmp,
                        'error' => UPLOAD_ERR_OK,
                        'size' => strlen($bytes),
                    ],
                ],
            );
        } finally {
            @unlink($tmp);
        }

        self::assertNotNull($rebuilt);

        $request = new Request(
            'POST',
            '/api/v1/media',
            [],
            ['content-type' => $rebuilt['contentType']],
            $rebuilt['body'],
        );

        $form = MultipartFormData::fromRequest($request);

        self::assertSame('A pixel', $form->field('alt_text'));
        $file = $form->file('file');
        self::assertNotNull($file);
        self::assertSame('pixel.png', $file->filename);
        self::assertSame('image/png', $file->contentType);
        self::assertSame($bytes, $file->contents);
    }

    public function testNoGlobalsMeansNoRebuild(): void
    {
        self::assertNull(GlobalsRequestFactory::multipartBody([], []));
    }
}
