<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Server\Accounts\Account;
use AlohaSocial\Server\Accounts\AccountRepository;
use AlohaSocial\Server\Auth\PasswordHasher;
use AlohaSocial\Server\Db\PdoFactory;
use AlohaSocial\Server\Migrations\MigrationRunner;
use AlohaSocial\Server\Migrations\Migrations;
use AlohaSocial\Server\Setup\SetupException;
use AlohaSocial\Server\Setup\SetupJournal;
use AlohaSocial\Server\Setup\SetupService;
use AlohaSocial\Server\Setup\SetupToken;
use AlohaSocial\Server\Support\EnvFile;
use AlohaSocial\Server\Support\InstallationReset;
use AlohaSocial\Server\Tests\Support\FailingConnection;
use AlohaSocial\Server\Tests\Support\TestServer;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Throwable;

final class SetupTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach ([
            'ALOHASOCIAL_INSTANCE_URL',
            'ALOHASOCIAL_INSTANCE_NAME',
            'ALOHASOCIAL_INSTANCE_DESCRIPTION',
            'ALOHASOCIAL_CONTACT_EMAIL',
            'ALOHASOCIAL_SECURE_COOKIES',
        ] as $key) {
            putenv($key);
        }
    }

    public function testSetupRequiresTheOneTimeToken(): void
    {
        $server = new TestServer();
        $setupToken = new SetupToken($server->config->varDirectory . '/setup.token');
        $token = $setupToken->generate();
        self::assertSame(0o600, fileperms($server->config->varDirectory . '/setup.token') & 0o777);

        self::assertSame('serialized', $setupToken->synchronized(static fn (): string => 'serialized'));

        $missing = $server->send('GET', '/setup');
        self::assertSame(403, $missing->status);
        self::assertStringContainsString('Setup is locked', $missing->body);

        $wrong = $server->send('GET', '/setup', ['query' => ['token' => 'not-the-token']]);
        self::assertSame(403, $wrong->status);

        $valid = $server->send('GET', '/setup', ['query' => ['token' => $token]]);
        self::assertSame(200, $valid->status);
        self::assertStringContainsString('Set up your instance', $valid->body);
        self::assertStringContainsString('name="token"', $valid->body);
    }

    public function testSetupWritesTheOriginAndCreatesTheFirstAdmin(): void
    {
        $server = new TestServer();
        $setupToken = new SetupToken($server->config->varDirectory . '/setup.token');
        $token = $setupToken->generate();

        $page = $server->send('GET', '/setup', ['query' => ['token' => $token]]);
        $cookies = $server->cookiesFrom($page);
        $csrf = $server->csrfFrom($page->body);
        self::assertNotNull($csrf);

        $response = $server->form('/setup', [
            '_csrf' => $csrf,
            'token' => $token,
            'url' => 'https://social.example',
            'name' => 'Kokua Community',
            'description' => 'Neighbours helping neighbours.',
            'contact' => 'admin@social.example',
            'handle' => 'admin',
            'email' => 'admin@social.example',
            'display_name' => 'Administrator',
            'password' => 'a-long-enough-password',
            'password_confirm' => 'a-long-enough-password',
        ], $cookies);

        self::assertSame(303, $response->status);
        self::assertSame('https://social.example/login?setup=1', $response->header('Location'));
        self::assertFalse($setupToken->exists());

        $account = $server->runtime->accounts->findByHandle('admin');
        self::assertNotNull($account);
        self::assertSame(Account::ROLE_ADMIN, $account->role);
        self::assertSame('Administrator', $account->name());

        $env = (string) file_get_contents($server->config->envFile);
        self::assertStringContainsString('ALOHASOCIAL_INSTANCE_URL=https://social.example', $env);
        self::assertStringContainsString('ALOHASOCIAL_INSTANCE_NAME=', $env);
        self::assertStringContainsString('Kokua Community', $env);
        self::assertStringContainsString('ALOHASOCIAL_SECURE_COOKIES=true', $env);

        $closed = $server->send('GET', '/setup', ['query' => ['token' => $token]]);
        self::assertSame(404, $closed->status);
    }

    public function testSetupRejectsInvalidInputWithoutCreatingAnAccount(): void
    {
        $server = new TestServer();
        $token = (new SetupToken($server->config->varDirectory . '/setup.token'))->generate();
        $page = $server->send('GET', '/setup', ['query' => ['token' => $token]]);
        $cookies = $server->cookiesFrom($page);
        $csrf = $server->csrfFrom($page->body);

        $response = $server->form('/setup', [
            '_csrf' => $csrf,
            'token' => $token,
            'url' => 'https://social.example/path',
            'name' => '',
            'handle' => 'A',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirm' => 'different',
        ], $cookies);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('Public URL', $response->body);
        self::assertNull($server->runtime->accounts->findByHandle('a'));
        self::assertTrue((new SetupToken($server->config->varDirectory . '/setup.token'))->exists());
    }

    public function testSetupTokenExpiresAndLegacyOneLineTokensStillWork(): void
    {
        $server = new TestServer();
        $path = $server->config->varDirectory . '/setup.token';
        $clock = new FrozenClock(new DateTimeImmutable('2026-11-27T10:00:00Z'));
        $token = new SetupToken($path, $clock);

        $value = $token->generate(60);
        self::assertSame('2026-11-27T10:01:00Z', Time::format($token->expiresAt()));
        self::assertTrue($token->exists());
        self::assertTrue($token->verify($value));

        $clock->advance(59);
        self::assertTrue($token->verify($value));

        $clock->advance(1);
        self::assertFalse($token->exists());
        self::assertFalse($token->verify($value));

        $legacy = str_repeat('a', 64);
        file_put_contents($path, $legacy . "\n");
        self::assertTrue($token->exists());
        self::assertNull($token->expiresAt());
        self::assertTrue($token->verify($legacy));
    }

    public function testSetupRollsBackEnvironmentAndAccountWhenTheAuditWriteFails(): void
    {
        $server = new TestServer();
        $tokenPath = $server->config->varDirectory . '/setup.token';
        $token = (new SetupToken($tokenPath))->generate();
        $before = "ALOHASOCIAL_INSTANCE_NAME=Before setup\nKEEP_ME=yes\n";
        EnvFile::replace($server->config->envFile, $before);

        $failing = new FailingConnection($server->runtime->database, 'INSERT INTO admin_audit');
        $setup = new SetupService(
            $server->config,
            $failing,
            new AccountRepository($failing, $server->runtime->clock),
            new PasswordHasher(),
            $server->runtime->clock,
        );

        try {
            $setup->install($this->setupInput($token));
            self::fail('The injected audit failure did not abort setup.');
        } catch (Throwable $exception) {
            self::assertStringContainsString('Injected database failure', $exception->getMessage());
        }

        self::assertSame(
            0,
            (int) $server->runtime->database->fetchValue('SELECT COUNT(*) FROM account'),
        );
        self::assertSame($before, file_get_contents($server->config->envFile));
        self::assertTrue((new SetupToken($tokenPath))->exists());
        self::assertFileDoesNotExist($server->config->varDirectory . '/setup.journal');

        $result = $server->runtime->setup->install($this->setupInput($token));
        self::assertSame('admin', $result['handle']);
        self::assertSame(
            1,
            (int) $server->runtime->database->fetchValue('SELECT COUNT(*) FROM account'),
        );
        self::assertFalse((new SetupToken($tokenPath))->exists());
        self::assertFileDoesNotExist($server->config->varDirectory . '/setup.journal');
    }

    public function testInterruptedSetupRestoresThePreviousEnvironmentAndKeepsTheToken(): void
    {
        $server = new TestServer();
        $path = $server->config->varDirectory . '/setup.token';
        $token = (new SetupToken($path))->generate();
        $before = "ALOHASOCIAL_INSTANCE_NAME=Before setup\n";
        EnvFile::replace($server->config->envFile, $before);

        $journal = new SetupJournal($server->config->varDirectory . '/setup.journal');
        $journal->write([
            'created_at' => Time::format($server->runtime->clock->now()),
            'env_file' => $server->config->envFile,
            'env_before' => $before,
            'settings' => ['ALOHASOCIAL_INSTANCE_NAME' => 'Interrupted setup'],
            'handle' => 'admin',
        ]);
        EnvFile::set($server->config->envFile, ['ALOHASOCIAL_INSTANCE_NAME' => 'Interrupted setup']);

        $server->runtime->setup->reconcile();

        self::assertSame($before, file_get_contents($server->config->envFile));
        self::assertTrue((new SetupToken($path))->verify($token));
        self::assertFileDoesNotExist($server->config->varDirectory . '/setup.journal');
        self::assertSame(
            0,
            (int) $server->runtime->database->fetchValue('SELECT COUNT(*) FROM account'),
        );
    }

    public function testACommittedSetupIsNotRepeatedAndTheStaleTokenIsCleared(): void
    {
        $server = new TestServer();
        $path = $server->config->varDirectory . '/setup.token';
        $token = (new SetupToken($path))->generate();
        $server->runtime->setup->install($this->setupInput($token));

        $staleToken = (new SetupToken($path))->generate();
        $journal = new SetupJournal($server->config->varDirectory . '/setup.journal');
        $journal->write([
            'created_at' => Time::format($server->runtime->clock->now()),
            'env_file' => $server->config->envFile,
            'env_before' => "ALOHASOCIAL_INSTANCE_NAME=Before setup\n",
            'settings' => ['ALOHASOCIAL_INSTANCE_NAME' => 'Stale setup'],
            'handle' => 'admin',
        ]);

        $server->runtime->setup->reconcile();

        self::assertSame(
            1,
            (int) $server->runtime->database->fetchValue('SELECT COUNT(*) FROM account'),
        );
        self::assertFalse((new SetupToken($path))->exists());
        self::assertFileDoesNotExist($server->config->varDirectory . '/setup.journal');
        self::assertStringContainsString(
            'ALOHASOCIAL_INSTANCE_URL=https://social.example',
            (string) file_get_contents($server->config->envFile),
        );
        self::assertFalse((new SetupToken($path))->verify($staleToken));
    }

    public function testSetupRefusesExistingContentEvenWithoutAnyAccount(): void
    {
        $server = new TestServer();
        $token = (new SetupToken($server->config->varDirectory . '/setup.token'))->generate();
        $now = Time::format($server->runtime->clock->now());
        $server->runtime->database->execute(
            'INSERT INTO announcement (id, author_id, content, active, created_at, updated_at, expires_at)'
            . ' VALUES (:id, :author, :content, 1, :created, :updated, NULL)',
            [
                'id' => 'old-announcement',
                'author' => 'deleted-account',
                'content' => 'Still here.',
                'created' => $now,
                'updated' => $now,
            ],
        );

        self::assertFalse($server->runtime->setup->enabled());
        self::assertFalse($server->runtime->setup->isFresh());

        try {
            $server->runtime->setup->install($this->setupInput($token));
            self::fail('Setup accepted an instance that already has content.');
        } catch (SetupException $exception) {
            self::assertStringContainsString('already has accounts or content', $exception->getMessage());
        }

        self::assertSame(
            0,
            (int) $server->runtime->database->fetchValue('SELECT COUNT(*) FROM account'),
        );
        self::assertTrue((new SetupToken($server->config->varDirectory . '/setup.token'))->verify($token));
    }

    public function testIssuingASetupTokenNeverDeletesExistingData(): void
    {
        $server = new TestServer();
        $path = $server->config->varDirectory . '/setup.token';
        $token = $server->runtime->setup->issueToken(120);
        self::assertTrue((new SetupToken($path))->verify($token));

        $server->createAccount('alice');

        try {
            $server->runtime->setup->issueToken(120);
            self::fail('A token was issued for an installed instance.');
        } catch (SetupException $exception) {
            self::assertStringContainsString('already has accounts or content', $exception->getMessage());
        }

        self::assertNotNull($server->runtime->accounts->findByHandle('alice'));
        self::assertSame(
            1,
            (int) $server->runtime->database->fetchValue('SELECT COUNT(*) FROM account'),
        );
        self::assertTrue((new SetupToken($path))->verify($token));
    }

    public function testSetupReportsProcessEnvironmentConflicts(): void
    {
        putenv('ALOHASOCIAL_INSTANCE_NAME=Compose instance');
        $server = new TestServer();
        $token = (new SetupToken($server->config->varDirectory . '/setup.token'))->generate();

        $page = $server->send('GET', '/setup', ['query' => ['token' => $token]]);

        self::assertSame(200, $page->status);
        self::assertStringContainsString('Effective origin', $page->body);
        self::assertStringContainsString('ALOHASOCIAL_INSTANCE_NAME', $page->body);
        self::assertStringContainsString('takes precedence', $page->body);
        self::assertContains('ALOHASOCIAL_INSTANCE_NAME', $server->config->externallyManagedSetupKeys);
    }

    public function testTwoSimultaneousSetupRequestsCreateExactlyOneAdmin(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the two-process test.');
        }

        $server = new TestServer();
        $server->preserveFiles();
        $token = (new SetupToken($server->config->varDirectory . '/setup.token'))->generate();
        $input = $this->setupInput($token);

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($sockets);
        [$parentSocket, $childSocket] = $sockets;
        stream_set_timeout($parentSocket, 30);
        stream_set_timeout($childSocket, 30);

        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid, 'The test process could not be forked.');

        if ($pid === 0) {
            fclose($parentSocket);

            try {
                $clock = new SystemClock();
                $database = PdoFactory::connect($server->config);
                $setup = new SetupService(
                    $server->config,
                    $database,
                    new AccountRepository($database, $clock),
                    new PasswordHasher(),
                    $clock,
                );
                $setup->install($input);
                $payload = ['outcome' => 'success'];
            } catch (SetupException) {
                $payload = ['outcome' => 'refused'];
            } catch (Throwable $exception) {
                $payload = ['outcome' => 'error', 'error' => $exception::class . ': ' . $exception->getMessage()];
            }

            fwrite($childSocket, json_encode($payload, JSON_THROW_ON_ERROR));
            fclose($childSocket);
            exit(0);
        }

        fclose($childSocket);

        try {
            $server->runtime->setup->install($input);
            $parentOutcome = 'success';
        } catch (SetupException) {
            $parentOutcome = 'refused';
        }

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
        self::assertSame(['refused', 'success'], $outcomes);

        self::assertSame(
            1,
            (int) $server->runtime->database->fetchValue('SELECT COUNT(*) FROM account'),
        );
        self::assertSame(
            1,
            (int) $server->runtime->database->fetchValue(
                "SELECT COUNT(*) FROM admin_audit WHERE action = 'instance.setup'",
            ),
        );
        self::assertFalse((new SetupToken($server->config->varDirectory . '/setup.token'))->exists());
        self::assertFileDoesNotExist($server->config->varDirectory . '/setup.journal');
    }

    public function testResetDropsAllTablesAndCanRebuildTheEmptySchema(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');

        $reset = new InstallationReset($server->runtime->database);
        $dropped = $reset->dropAllTables();
        self::assertContains('account', $dropped);
        self::assertContains('social_post', $dropped);

        $runner = new MigrationRunner($server->runtime->database, new SystemClock(), Migrations::all());
        $runner->migrate();

        self::assertSame(
            0,
            (int) $server->runtime->database->fetchValue('SELECT COUNT(*) FROM account'),
        );
        self::assertSame(
            1,
            (int) $server->runtime->database->fetchValue(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'account'",
            ),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function setupInput(string $token, array $overrides = []): array
    {
        return array_merge([
            'token' => $token,
            'url' => 'https://social.example',
            'name' => 'Kokua Community',
            'description' => 'Neighbours helping neighbours.',
            'contact' => 'admin@social.example',
            'handle' => 'admin',
            'email' => 'admin@social.example',
            'display_name' => 'Administrator',
            'password' => 'a-long-enough-password',
            'password_confirm' => 'a-long-enough-password',
        ], $overrides);
    }
}
