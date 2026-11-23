<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Storage;

use AlohaSocial\Core\Platform\Storage\BlobStore;
use RuntimeException;

use function count;
use function hash;
use function hash_equals;
use function strlen;

/** Copies media between stores without deleting the source. */
final class BlobStoreMigrator
{
    /**
     * @return array{objects: int, bytes: int, verified: bool}
     */
    public function copy(BlobStore $source, BlobStore $destination, bool $verify = true): array
    {
        $paths = $source->list();
        $bytesCopied = 0;

        foreach ($paths as $path) {
            $contents = $source->read($path);
            $destination->put($path, $contents);
            if ($verify) {
                $written = $destination->read($path);
                if (!hash_equals(hash('sha256', $contents), hash('sha256', $written))) {
                    throw new RuntimeException('Verification failed for media object ' . $path . '.');
                }
            }
            $bytesCopied += strlen($contents);
        }

        return ['objects' => count($paths), 'bytes' => $bytesCopied, 'verified' => $verify];
    }
}
