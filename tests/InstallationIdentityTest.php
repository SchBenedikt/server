<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Server\Bootstrap\InstallationIdentity;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InstallationIdentityTest extends TestCase
{
    public function testBootIdentityIsStableAcrossRestarts(): void
    {
        $server = new TestServer();
        $path = $server->runtime->config->varDirectory . '/installation.json';
        $row = $this->row($server);

        self::assertFileExists($path);
        self::assertSame(0o600, fileperms($path) & 0o777);
        self::assertSame($row['installation_id'], $this->fileId($path));

        $this->identity($server)->assertCurrent();
        self::assertSame($row['installation_id'], $this->row($server)['installation_id']);
    }

    public function testEmptyInstanceMayMoveItsOrigin(): void
    {
        $server = new TestServer();
        $before = $this->row($server);

        $this->identity($server, 'https://social.example')->assertCurrent();
        $after = $this->row($server);

        self::assertSame('https://social.example', $after['instance_url']);
        self::assertSame($before['installation_id'], $after['installation_id']);
    }

    public function testPopulatedInstanceRefusesAnOriginChange(): void
    {
        $server = new TestServer();
        $server->createAccount();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Changing the public origin on a populated instance');
        $this->identity($server, 'https://other.example')->assertCurrent();
    }

    public function testPopulatedInstanceRefusesAReplacedSigningKey(): void
    {
        $server = new TestServer();
        $server->createAccount();
        $other = RsaKeyPair::generate()->publicKeyPem();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('signing key does not match this installation identity');
        $this->identity($server, null, $other)->assertCurrent();
    }

    public function testDataDirectoryAndDatabaseMustCarryTheSameIdentity(): void
    {
        $server = new TestServer();
        $path = $server->runtime->config->varDirectory . '/installation.json';
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $data['installation_id'] = str_repeat('b', 32);
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR) . "\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('data directory and database belong to different installations');
        $this->identity($server)->assertCurrent();
    }

    public function testMissingIdentityFileIsRecoveredFromTheDatabase(): void
    {
        $server = new TestServer();
        $path = $server->runtime->config->varDirectory . '/installation.json';
        $id = $this->row($server)['installation_id'];
        unlink($path);

        $this->identity($server)->assertCurrent();

        self::assertFileExists($path);
        self::assertSame($id, $this->fileId($path));
        self::assertSame(0o600, fileperms($path) & 0o777);
    }

    public function testPopulatedInstanceRefusesAMissingIdentityFile(): void
    {
        $server = new TestServer();
        $server->createAccount();
        $path = $server->runtime->config->varDirectory . '/installation.json';
        unlink($path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('populated installation is missing var/installation.json');
        $this->identity($server)->assertCurrent();
    }

    public function testMissingDatabaseRowIsRecoveredFromTheIdentityFile(): void
    {
        $server = new TestServer();
        $id = $this->fileId($server->runtime->config->varDirectory . '/installation.json');
        $server->runtime->database->execute('DELETE FROM installation_identity');

        $this->identity($server)->assertCurrent();

        self::assertSame($id, $this->row($server)['installation_id']);
    }

    /**
     * @return array{installation_id: string, instance_url: string, key_fingerprint: string}
     */
    private function row(TestServer $server): array
    {
        $row = $server->runtime->database->fetchOne(
            'SELECT installation_id, instance_url, key_fingerprint FROM installation_identity WHERE id = :id',
            ['id' => InstallationIdentity::ROW_ID],
        );
        self::assertNotNull($row);

        return [
            'installation_id' => (string) $row['installation_id'],
            'instance_url' => (string) $row['instance_url'],
            'key_fingerprint' => (string) $row['key_fingerprint'],
        ];
    }

    private function identity(TestServer $server, ?string $origin = null, ?string $publicKey = null): InstallationIdentity
    {
        return new InstallationIdentity(
            $server->runtime->database,
            $server->runtime->config->varDirectory,
            $origin ?? $server->runtime->config->instanceUrl,
            $publicKey ?? (string) $server->runtime->database->fetchValue(
                'SELECT public_pem FROM instance_key WHERE id = :id',
                ['id' => 'instance'],
            ),
            $server->runtime->clock,
        );
    }

    private function fileId(string $path): string
    {
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return (string) $data['installation_id'];
    }
}
