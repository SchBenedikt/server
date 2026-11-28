<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Security\OpenSslSecretBox;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Server\Accounts\AccountRepository;
use AlohaSocial\Server\Auth\PasswordHasher;
use AlohaSocial\Server\Auth\PasswordResetException;
use AlohaSocial\Server\Auth\PasswordResetService;
use AlohaSocial\Server\Mail\MailOutbox;
use AlohaSocial\Server\Migrations\Version202611280014CredentialVersioning;
use AlohaSocial\Server\Tests\Support\BarrierConnection;
use AlohaSocial\Server\Tests\Support\FailingConnection;
use AlohaSocial\Server\Tests\Support\NullMailer;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * The reset invariants on PostgreSQL, which uses row locks instead of SQLite's
 * database write lock. The fixture creates the pre-migration tables and then
 * applies migration 014, so the PostgreSQL DDL is exercised as well.
 */
final class PostgresPasswordResetTest extends TestCase
{
    private ?PDO $admin = null;

    private string $schema;

    private string $dsn;

    private ?string $user;

    private ?string $password;

    private ?PdoConnection $db = null;

    private SystemClock $clock;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('pdo_pgsql is not installed.');
        }

        $dsn = getenv('ALOHASOCIAL_TEST_PG_DSN');
        if (!is_string($dsn) || trim($dsn) === '') {
            self::markTestSkipped('Set ALOHASOCIAL_TEST_PG_DSN to run the PostgreSQL reset tests.');
        }

        $this->dsn = $dsn;
        $user = getenv('ALOHASOCIAL_TEST_PG_USER');
        $password = getenv('ALOHASOCIAL_TEST_PG_PASSWORD');
        $this->user = is_string($user) && $user !== '' ? $user : null;
        $this->password = is_string($password) && $password !== '' ? $password : null;

        $this->admin = $this->adminConnection();
        $this->schema = 'reset_' . bin2hex(random_bytes(6));
        $this->admin->exec('CREATE SCHEMA "' . $this->schema . '"');

        $this->db = $this->connect();
        $this->clock = new SystemClock();

        $this->createPreMigrationSchema();
        (new Version202611280014CredentialVersioning())->up($this->database());
    }

    protected function tearDown(): void
    {
        if (isset($this->admin, $this->schema)) {
            $this->admin->exec('DROP SCHEMA IF EXISTS "' . $this->schema . '" CASCADE');
        }
    }

    public function testConcurrentSubmissionsConsumeTheTokenAndRevokeCredentialsOnce(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the two-connection test.');
        }

        $this->createAccount();
        $this->seedCredentials();
        $token = 'postgres-concurrent-token';
        $this->insertToken($token);

        // Never fork a live PostgreSQL handle: the child's PDO destructor
        // would send Terminate on the shared socket and kill the parent's
        // backend. The parent reconnects after the fork.
        $this->db = null;
        $this->admin = null;
        gc_collect_cycles();

        [$parentSocket, $childSocket] = $this->socketPair();
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid, 'The test process could not be forked.');

        if ($pid === 0) {
            fclose($parentSocket);

            try {
                $connection = new BarrierConnection($this->connect(), $childSocket);
                $outcome = $this->attemptReset(
                    $this->service($connection),
                    $token,
                    'postgres-child-password',
                );
                fwrite($childSocket, json_encode(['outcome' => $outcome], JSON_THROW_ON_ERROR));
                fclose($childSocket);
                exit(0);
            } catch (Throwable $exception) {
                fwrite($childSocket, json_encode([
                    'error' => $exception::class . ': ' . $exception->getMessage(),
                ], JSON_THROW_ON_ERROR));
                fclose($childSocket);
                exit(1);
            }
        }

        fclose($childSocket);
        $this->admin = $this->adminConnection();
        $this->db = $this->connect();
        $connection = new BarrierConnection($this->connect(), $parentSocket);
        $parentOutcome = $this->attemptReset(
            $this->service($connection),
            $token,
            'postgres-parent-password',
        );

        $childPayload = (string) stream_get_contents($parentSocket);
        fclose($parentSocket);
        pcntl_waitpid($pid, $status);
        self::assertTrue(pcntl_wifexited($status), 'The child process did not exit normally: ' . $childPayload);
        self::assertSame(0, pcntl_wexitstatus($status), 'The child process failed: ' . $childPayload);

        /** @var array{outcome?: string, error?: string} $child */
        $child = json_decode($childPayload, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('error', $child, $childPayload);

        $outcomes = [$parentOutcome, (string) ($child['outcome'] ?? '')];
        sort($outcomes);
        self::assertSame(['invalid_token', 'success'], $outcomes);

        $winner = $parentOutcome === 'success' ? 'postgres-parent-password' : 'postgres-child-password';
        $loser = $parentOutcome === 'success' ? 'postgres-child-password' : 'postgres-parent-password';
        $hash = (string) $this->database()->fetchValue(
            'SELECT password_hash FROM account WHERE id = :id',
            ['id' => 'alice'],
        );

        self::assertTrue(password_verify($winner, $hash));
        self::assertFalse(password_verify($loser, $hash));
        self::assertSame(2, (int) $this->database()->fetchValue('SELECT credential_version FROM account'));
        self::assertSame(0, (int) $this->database()->fetchValue('SELECT COUNT(*) FROM password_reset'));
        self::assertSame(1, (int) $this->database()->fetchValue(
            "SELECT COUNT(*) FROM admin_audit WHERE action = 'account.password_reset'",
        ));
        $this->assertCredentialsRevoked();
    }

    public function testFailureAfterClaimRollsBackOnPostgresAndCanBeRetried(): void
    {
        $this->createAccount();
        $this->seedCredentials();
        $token = 'postgres-rollback-token';
        $this->insertToken($token);

        $failing = new FailingConnection($this->database(), 'UPDATE password_reset SET used_at');

        try {
            $this->service($failing)->reset($token, 'postgres-uncommitted-password');
            self::fail('The injected failure should have escaped the transaction.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Injected database failure', $exception->getMessage());
        }

        self::assertTrue($failing->failed);
        self::assertNull($this->database()->fetchValue(
            'SELECT used_at FROM password_reset WHERE token_hash = :hash',
            ['hash' => hash('sha256', $token)],
        ));
        $oldHash = (string) $this->database()->fetchValue('SELECT password_hash FROM account WHERE id = :id', ['id' => 'alice']);
        self::assertTrue(password_verify('correct-horse-battery-staple', $oldHash));

        $this->service($this->database())->reset($token, 'postgres-retried-password');
        $newHash = (string) $this->database()->fetchValue('SELECT password_hash FROM account WHERE id = :id', ['id' => 'alice']);
        self::assertTrue(password_verify('postgres-retried-password', $newHash));
        self::assertSame(0, (int) $this->database()->fetchValue('SELECT COUNT(*) FROM password_reset'));
        $this->assertCredentialsRevoked();
    }

    private function database(): PdoConnection
    {
        if ($this->db === null) {
            throw new RuntimeException('The PostgreSQL test connection is not open.');
        }

        return $this->db;
    }

    private function adminConnection(): PDO
    {
        return new PDO($this->dsn, $this->user, $this->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private function connect(): PdoConnection
    {
        $pdo = $this->adminConnection();
        $pdo->exec("SET TIME ZONE 'UTC'");
        $pdo->exec('SET search_path TO "' . $this->schema . '"');

        return new PdoConnection($pdo);
    }

    private function createPreMigrationSchema(): void
    {
        $this->database()->execute(
            'CREATE TABLE account ('
            . ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
            . ' handle VARCHAR(64) NOT NULL UNIQUE,'
            . ' email VARCHAR(255) NOT NULL UNIQUE,'
            . ' password_hash VARCHAR(255) NOT NULL,'
            . ' role VARCHAR(16) NOT NULL,'
            . ' display_name VARCHAR(255) NULL,'
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' disabled_at VARCHAR(20) NULL'
            . ')',
        );
        $this->database()->execute(
            'CREATE TABLE password_reset ('
            . ' token_hash VARCHAR(64) NOT NULL PRIMARY KEY,'
            . ' account_id VARCHAR(64) NOT NULL,'
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' expires_at VARCHAR(20) NOT NULL,'
            . ' used_at VARCHAR(20) NULL'
            . ')',
        );
        $this->database()->execute('CREATE INDEX password_reset_account ON password_reset (account_id)');
        $this->database()->execute('CREATE TABLE session (account_id VARCHAR(64) NULL)');
        $this->database()->execute('CREATE TABLE atproto_session (account_id VARCHAR(64) NULL)');
        $this->database()->execute(
            'CREATE TABLE mastodon_oauth_token (account_id VARCHAR(64) NOT NULL, revoked_at VARCHAR(20) NULL)',
        );
        $this->database()->execute(
            'CREATE TABLE mastodon_oauth_code (account_id VARCHAR(64) NOT NULL, consumed_at VARCHAR(20) NULL)',
        );
        $this->database()->execute(
            'CREATE TABLE admin_audit ('
            . ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
            . ' actor_id VARCHAR(64) NOT NULL,'
            . ' action VARCHAR(64) NOT NULL,'
            . ' target_type VARCHAR(32) NULL,'
            . ' target_id VARCHAR(512) NULL,'
            . ' details TEXT NULL,'
            . ' created_at VARCHAR(20) NOT NULL'
            . ')',
        );
    }

    private function createAccount(): void
    {
        (new AccountRepository($this->database(), $this->clock))->create(
            'alice',
            'alice@example.test',
            (new PasswordHasher())->hash('correct-horse-battery-staple'),
        );
    }

    private function seedCredentials(): void
    {
        $this->database()->execute("INSERT INTO session (account_id) VALUES ('alice')");
        $this->database()->execute("INSERT INTO atproto_session (account_id) VALUES ('alice')");
        $this->database()->execute("INSERT INTO mastodon_oauth_token (account_id, revoked_at) VALUES ('alice', NULL)");
        $this->database()->execute("INSERT INTO mastodon_oauth_code (account_id, consumed_at) VALUES ('alice', NULL)");
    }

    private function insertToken(string $token): void
    {
        $account = (new AccountRepository($this->database(), $this->clock))->find('alice');
        self::assertNotNull($account);
        $now = $this->clock->now();

        $this->database()->execute(
            'INSERT INTO password_reset'
            . ' (token_hash, account_id, created_at, expires_at, used_at, credential_version)'
            . ' VALUES (:hash, :account, :created, :expires, NULL, :credential_version)',
            [
                'hash' => hash('sha256', $token),
                'account' => $account->id,
                'created' => Time::format($now),
                'expires' => Time::format($now->modify('+1 hour')),
                'credential_version' => $account->credentialVersion,
            ],
        );
    }

    private function service(Connection $database): PasswordResetService
    {
        return new PasswordResetService(
            $database,
            new AccountRepository($database, $this->clock),
            new PasswordHasher(),
            new MailOutbox(
                $database,
                new NullMailer(),
                OpenSslSecretBox::fromBase64Key(OpenSslSecretBox::generateKey()),
                $this->clock,
            ),
            $this->clock,
            'https://social.example.test',
        );
    }

    private function attemptReset(PasswordResetService $service, string $token, string $password): string
    {
        try {
            $service->reset($token, $password);

            return 'success';
        } catch (PasswordResetException $exception) {
            return $exception->reason;
        }
    }

    private function assertCredentialsRevoked(): void
    {
        self::assertSame(0, (int) $this->database()->fetchValue('SELECT COUNT(*) FROM session'));
        self::assertSame(0, (int) $this->database()->fetchValue('SELECT COUNT(*) FROM atproto_session'));
        self::assertSame(0, (int) $this->database()->fetchValue(
            'SELECT COUNT(*) FROM mastodon_oauth_token WHERE revoked_at IS NULL',
        ));
        self::assertSame(0, (int) $this->database()->fetchValue(
            'SELECT COUNT(*) FROM mastodon_oauth_code WHERE consumed_at IS NULL',
        ));
    }

    /**
     * @return array{0: resource, 1: resource}
     */
    private function socketPair(): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($sockets);
        stream_set_timeout($sockets[0], 10);
        stream_set_timeout($sockets[1], 10);

        return [$sockets[0], $sockets[1]];
    }
}
