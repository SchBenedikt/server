<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Bootstrap;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Security\SecretBox;
use AlohaSocial\Core\Support\Time;

/**
 * Loads or creates the instance RSA key pair.
 *
 * The private key is sealed with the host's `SecretBox` before it touches the
 * database, and the key is generated once: rotating it changes the actor key
 * id peers have cached, so it must stay a deliberate operation.
 */
final class KeyStore
{
    private const ID = 'instance';

    public function __construct(
        private readonly Connection $db,
        private readonly SecretBox $box,
        private readonly Clock $clock,
    ) {
    }

    public function loadOrCreate(): RsaKeyPair
    {
        $row = $this->db->fetchOne(
            'SELECT public_pem, private_pem FROM instance_key WHERE id = :id',
            ['id' => self::ID],
        );

        if ($row !== null) {
            return RsaKeyPair::fromPems(
                $this->box->open((string) $row['private_pem']),
                (string) $row['public_pem'],
            );
        }

        $pair = RsaKeyPair::generate();

        $this->db->execute(
            'INSERT INTO instance_key (id, algorithm, public_pem, private_pem, created_at)'
            . ' VALUES (:id, :algorithm, :public_pem, :private_pem, :created_at)',
            [
                'id' => self::ID,
                'algorithm' => 'rsa-sha256',
                'public_pem' => $pair->publicKeyPem(),
                'private_pem' => $this->box->seal($pair->privateKeyPem()),
                'created_at' => Time::format($this->clock->now()),
            ],
        );

        return $pair;
    }
}
