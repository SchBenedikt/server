<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Federation;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;
use AlohaSocial\Core\Domain\Atproto\AtprotoDeleter;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublishFailedException;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublisher;
use AlohaSocial\Core\Domain\Atproto\Pds\NativePdsService;
use AlohaSocial\Core\Domain\Atproto\Pds\PdsError;
use AlohaSocial\Core\Domain\Atproto\Pds\PdsRepository;
use AlohaSocial\Core\Protocol\Atproto\AtprotoPostRecord;

/**
 * The native repository's write port.
 *
 * No HTTP call is involved when this instance hosts the PDS: the same
 * `NativePdsService` that serves XRPC creates the signed commit directly. The
 * core's publish service still owns record creation, idempotency in
 * `social_atproto_publication`, and job retries.
 */
final class NativePdsPublisher implements AtprotoPublisher, AtprotoDeleter
{
    public function __construct(
        private readonly NativePdsService $pds,
        private readonly PdsRepository $identities,
    ) {
    }

    public function supports(string $did): bool
    {
        return $this->identities->identityByDid($did) !== null;
    }

    public function publish(
        AtprotoAccount $account,
        string $rkey,
        array $record,
        string $collection = AtprotoPostRecord::COLLECTION,
    ): string {
        $identity = $this->identities->identityByDid($account->did);
        if ($identity === null) {
            throw new AtprotoPublishFailedException(403, 'The repository is not hosted on this instance.');
        }

        try {
            $written = $this->pds->putRecord($identity->localUserId, [
                'repo' => $account->did,
                'collection' => $collection,
                'rkey' => $rkey,
                'record' => $record,
                'validate' => true,
            ]);
        } catch (PdsError $exception) {
            throw new AtprotoPublishFailedException($exception->status, $exception->getMessage());
        }

        $uri = $written['uri'] ?? null;
        if (!is_string($uri) || !str_starts_with($uri, 'at://')) {
            throw new AtprotoPublishFailedException(502, 'The native PDS returned no record URI.');
        }

        return $uri;
    }

    public function delete(
        AtprotoAccount $account,
        string $rkey,
        string $collection = AtprotoPostRecord::COLLECTION,
    ): void {
        $identity = $this->identities->identityByDid($account->did);
        if ($identity === null) {
            throw new AtprotoPublishFailedException(403, 'The repository is not hosted on this instance.');
        }

        try {
            $this->pds->deleteRecord($identity->localUserId, [
                'repo' => $account->did,
                'collection' => $collection,
                'rkey' => $rkey,
            ]);
        } catch (PdsError $exception) {
            // A missing record already has the desired end state.
            if ($exception->status === 404) {
                return;
            }

            throw new AtprotoPublishFailedException($exception->status, $exception->getMessage());
        }
    }
}
