<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Storage;

use AlohaSocial\Core\Platform\Storage\BlobStore;
use AlohaSocial\Core\Platform\Storage\S3CompatibleClient;

/** The selected media store and its non-secret diagnostics port. */
final readonly class HostStorage
{
    public function __construct(
        public BlobStore $blobStore,
        public ?S3CompatibleClient $s3Client,
    ) {
    }
}
