<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Federation;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;
use AlohaSocial\Core\Domain\Atproto\AtprotoAccountResolver;
use AlohaSocial\Core\Domain\Atproto\Pds\PdsRepository;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Server\Config\HostConfig;

/**
 * Presents a native PDS identity in the shape the core's AT Protocol services
 * expect.
 *
 * The core publisher only needs the DID, handle and PDS origin; the native
 * publisher writes through `NativePdsService` directly, so the token is a
 * non-secret marker rather than a credential. A member without a native
 * identity simply falls through to the Bluesky resolver.
 */
final class NativePdsAccountResolver implements AtprotoAccountResolver
{
    public function __construct(
        private readonly HostConfig $config,
        private readonly PdsRepository $identities,
        private readonly Connection $database,
    ) {
    }

    public function resolve(string $localUserId): ?AtprotoAccount
    {
        if (!$this->config->atprotoPds) {
            return null;
        }

        if ($this->database->fetchValue(
            'SELECT 1 FROM account WHERE id = :account AND disabled_at IS NULL',
            ['account' => trim($localUserId)],
        ) === null) {
            return null;
        }

        $identity = $this->identities->identityForUser(trim($localUserId));
        if ($identity === null) {
            return null;
        }

        try {
            return new AtprotoAccount(
                did: $identity->did,
                handle: $identity->handle,
                pdsUrl: $this->config->atprotoPdsUrl,
                accessToken: 'native-pds',
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
