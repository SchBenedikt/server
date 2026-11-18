<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Federation;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;
use AlohaSocial\Core\Domain\Atproto\AtprotoDeleter;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublisher;
use AlohaSocial\Core\Protocol\Atproto\AtprotoPostRecord;

/**
 * Chooses the repository that owns the account's DID.
 *
 * A native identity is written through the in-process PDS service; every other
 * DID keeps using the authenticated XRPC publisher, so a connected Bluesky
 * account continues to work unchanged.
 */
final class CompositeAtprotoPublisher implements AtprotoPublisher, AtprotoDeleter
{
    public function __construct(
        private readonly NativePdsPublisher $native,
        private readonly AtprotoPublisher $external,
    ) {
    }

    public function publish(
        AtprotoAccount $account,
        string $rkey,
        array $record,
        string $collection = AtprotoPostRecord::COLLECTION,
    ): string {
        if ($this->native->supports($account->did)) {
            return $this->native->publish($account, $rkey, $record, $collection);
        }

        return $this->external->publish($account, $rkey, $record, $collection);
    }

    public function delete(
        AtprotoAccount $account,
        string $rkey,
        string $collection = AtprotoPostRecord::COLLECTION,
    ): void {
        if ($this->native->supports($account->did)) {
            $this->native->delete($account, $rkey, $collection);

            return;
        }

        if (!$this->external instanceof AtprotoDeleter) {
            throw new \AlohaSocial\Core\Domain\Atproto\AtprotoPublishFailedException(
                501,
                'The external PDS does not support record deletion.',
            );
        }

        $this->external->delete($account, $rkey, $collection);
    }
}
