<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Server\Accounts\AccountRepository;
use AlohaSocial\Server\Auth\PasswordHasher;
use AlohaSocial\Server\Auth\PasswordResetException;
use AlohaSocial\Server\Auth\PasswordResetService;
use AlohaSocial\Server\Db\PdoFactory;
use AlohaSocial\Server\Tests\Support\BarrierConnection;
use AlohaSocial\Server\Tests\Support\FailingConnection;
use AlohaSocial\Server\Tests\Support\NullMailer;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;
use Throwable;

final class PasswordResetTest extends TestCase
{
    public function testEmailedLinkChangesThePasswordAndRevokesOldSessions(): void
    {
        $server = new TestServer();
        $server->createAccount();
        $signedIn = $server->signIn();

        $form = $server->send('GET', '/forgot-password');
        $cookies = $server->cookiesFrom($form);
        $csrf = $server->csrfFrom($form->body);
        self::assertNotNull($csrf);

        $sent = $server->form('/forgot-password', [
            '_csrf' => $csrf,
            'identifier' => 'alice',
        ], $cookies);

        self::assertSame(200, $sent->status);
        self::assertStringContainsString('email', $sent->body);

        $mails = $this->mails($server);
        self::assertCount(1, $mails);

        $message = (string) file_get_contents($mails[0]);
        self::assertSame(1, preg_match('/token=([A-Za-z0-9_-]+)/', $message, $match));
        $token = $match[1];
        self::assertStringContainsString('@alice', $message);

        $resetPage = $server->send('GET', '/reset-password', [
            'query' => ['token' => $token],
            'cookies' => $cookies,
        ]);
        self::assertSame(200, $resetPage->status);

        $resetCsrf = $server->csrfFrom($resetPage->body);
        self::assertNotNull($resetCsrf);

        $reset = $server->form('/reset-password', [
            '_csrf' => $resetCsrf,
            'token' => $token,
            'password' => 'brand-new-password',
            'password_confirm' => 'brand-new-password',
        ], $cookies);

        self::assertSame(303, $reset->status);
        self::assertSame('/login?reset=1', $reset->header('Location'));

        $oldSession = $server->send('GET', '/', ['cookies' => $signedIn['cookies']]);
        self::assertSame(303, $oldSession->status);
        self::assertSame('/login', $oldSession->header('Location'));

        self::assertSame(303, $server->login('alice', 'brand-new-password')['response']->status);
        self::assertSame(401, $server->login('alice', TestServer::PASSWORD)['response']->status);

        $reused = $server->send('GET', '/reset-password', [
            'query' => ['token' => $token],
            'cookies' => $cookies,
        ]);
        self::assertSame(400, $reused->status);
    }

    public function testUnknownIdentifierRevealsNothingAndSendsNoMail(): void
    {
        $server = new TestServer();

        $form = $server->send('GET', '/forgot-password');
        $cookies = $server->cookiesFrom($form);
        $csrf = $server->csrfFrom($form->body);
        self::assertNotNull($csrf);

        $response = $server->form('/forgot-password', [
            '_csrf' => $csrf,
            'identifier' => 'nobody@example.test',
        ], $cookies);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('If an account', $response->body);
        self::assertCount(0, $this->mails($server));
    }

    public function testExpiredTokenIsRefusedWithoutTouchingThePassword(): void
    {
        $server = new TestServer();
        $server->createAccount();

        $token = 'expired-token-for-the-test';
        $server->runtime->database->execute(
            'INSERT INTO password_reset (token_hash, account_id, created_at, expires_at, used_at)'
            . ' VALUES (:hash, :account, :created, :expires, NULL)',
            [
                'hash' => hash('sha256', $token),
                'account' => 'alice',
                'created' => gmdate('Y-m-d\TH:i:s\Z', time() - 7200),
                'expires' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600),
            ],
        );

        $form = $server->send('GET', '/forgot-password');
        $cookies = $server->cookiesFrom($form);
        $csrf = $server->csrfFrom($form->body);
        self::assertNotNull($csrf);

        $expiredPage = $server->send('GET', '/reset-password', [
            'query' => ['token' => $token],
            'cookies' => $cookies,
        ]);
        self::assertSame(400, $expiredPage->status);

        $response = $server->form('/reset-password', [
            '_csrf' => $csrf,
            'token' => $token,
            'password' => 'should-not-be-set',
            'password_confirm' => 'should-not-be-set',
        ], $cookies);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('expired', $response->body);
        self::assertSame(303, $server->login('alice', TestServer::PASSWORD)['response']->status);
    }

    public function testRepeatedRequestsReuseOneValidTokenPerAccount(): void
    {
        $server = new TestServer();
        $server->createAccount();

        $form = $server->send('GET', '/forgot-password');
        $cookies = $server->cookiesFrom($form);
        $csrf = $server->csrfFrom($form->body);
        self::assertNotNull($csrf);

        foreach ([1, 2] as $attempt) {
            $response = $server->form('/forgot-password', [
                '_csrf' => $csrf,
                'identifier' => 'alice',
            ], $cookies);
            self::assertSame(200, $response->status);
        }

        $mails = $this->mails($server);
        self::assertCount(2, $mails);

        $tokens = [];
        foreach ($mails as $mail) {
            self::assertSame(1, preg_match('/token=([A-Za-z0-9_-]+)/', (string) file_get_contents($mail), $match));
            $tokens[] = $match[1];
        }

        self::assertNotSame($tokens[0], $tokens[1]);

        // Only the newest link works; the older row was invalidated.
        $older = $server->send('GET', '/reset-password', [
            'query' => ['token' => $tokens[0]],
            'cookies' => $cookies,
        ]);
        $newer = $server->send('GET', '/reset-password', [
            'query' => ['token' => $tokens[1]],
            'cookies' => $cookies,
        ]);

        // Mail files of the same second have no guaranteed order, so assert
        // that exactly one of the two links survived the second request.
        self::assertSame(1, count(array_filter([$older->status, $newer->status], static fn (int $status): bool => $status === 200)));
        self::assertSame(1, count(array_filter([$older->status, $newer->status], static fn (int $status): bool => $status === 400)));
    }

    public function testConcurrentSubmissionsConsumeTheTokenExactlyOnce(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the two-process test.');
        }

        $server = new TestServer();
        $server->createAccount();

        $token = 'concurrent-reset-token';
        $this->insertToken($server, $token);

        [$parentSocket, $childSocket] = $this->socketPair();
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid, 'The test process could not be forked.');

        if ($pid === 0) {
            $server->preserveFiles();
            fclose($parentSocket);

            try {
                $connection = new BarrierConnection(PdoFactory::connect($server->config), $childSocket);
                $outcome = $this->attemptReset(
                    $this->service($connection, $server),
                    $token,
                    'child-password-long',
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
        $connection = new BarrierConnection(PdoFactory::connect($server->config), $parentSocket);
        $parentOutcome = $this->attemptReset(
            $this->service($connection, $server),
            $token,
            'parent-password-long',
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

        $winner = $parentOutcome === 'success' ? 'parent-password-long' : 'child-password-long';
        $loser = $parentOutcome === 'success' ? 'child-password-long' : 'parent-password-long';
        $hash = (string) $server->runtime->database->fetchValue(
            'SELECT password_hash FROM account WHERE id = :id',
            ['id' => 'alice'],
        );

        self::assertTrue(password_verify($winner, $hash));
        self::assertFalse(password_verify($loser, $hash));
        self::assertFalse(password_verify(TestServer::PASSWORD, $hash));
        self::assertSame(0, (int) $server->runtime->database->fetchValue('SELECT COUNT(*) FROM password_reset'));
        self::assertSame(1, (int) $server->runtime->database->fetchValue(
            "SELECT COUNT(*) FROM admin_audit WHERE action = 'account.password_reset'",
        ));
        $details = (string) $server->runtime->database->fetchValue(
            "SELECT details FROM admin_audit WHERE action = 'account.password_reset'",
        );
        self::assertSame('{"method":"emailed_token"}', $details);
        self::assertStringNotContainsString($token, $details);
        self::assertStringNotContainsString($winner, $details);
    }

    public function testFailureAfterClaimRollsBackAndTheTokenCanBeRetried(): void
    {
        $server = new TestServer();
        $server->createAccount();

        $token = 'rollback-reset-token';
        $this->insertToken($server, $token);

        $failing = new FailingConnection(
            $server->runtime->database,
            'UPDATE password_reset SET used_at',
        );

        try {
            $this->service($failing, $server)->reset($token, 'never-committed-password');
            self::fail('The injected failure should have escaped the transaction.');
        } catch (Throwable $exception) {
            self::assertStringContainsString('Injected database failure', $exception->getMessage());
        }

        self::assertTrue($failing->failed);
        self::assertNull($this->tokenUsedAt($server, $token));
        self::assertSame(0, (int) $server->runtime->database->fetchValue(
            "SELECT COUNT(*) FROM admin_audit WHERE action = 'account.password_reset'",
        ));
        self::assertSame(303, $server->login('alice', TestServer::PASSWORD)['response']->status);

        $this->service($server->runtime->database, $server)->reset($token, 'retried-password');
        self::assertSame(303, $server->login('alice', 'retried-password')['response']->status);
        self::assertSame(0, (int) $server->runtime->database->fetchValue('SELECT COUNT(*) FROM password_reset'));
    }

    public function testDisabledAccountCannotUseAValidTokenAndCanRetryAfterEnable(): void
    {
        $server = new TestServer();
        $server->createAccount();
        $token = $this->requestToken($server);

        $server->runtime->accounts->disable('alice');
        $service = $this->service($server->runtime->database, $server);

        self::assertSame('invalid_token', $this->attemptReset($service, $token, 'disabled-account-password'));
        self::assertNull($this->tokenUsedAt($server, $token));
        self::assertFalse($service->isValid($token));
        self::assertNotSame(303, $server->login('alice', TestServer::PASSWORD)['response']->status);

        $server->runtime->accounts->enable('alice');
        self::assertSame('success', $this->attemptReset($service, $token, 'enabled-again-password'));
        self::assertSame(303, $server->login('alice', 'enabled-again-password')['response']->status);
    }

    public function testTokenIssuedForAnOlderCredentialIsRejected(): void
    {
        $server = new TestServer();
        $server->createAccount();
        $token = $this->requestToken($server);

        // An administrator resets the password without going through email.
        // The outstanding link must not undo that newer credential.
        $server->runtime->accounts->updatePasswordHash(
            'alice',
            (new PasswordHasher())->hash('administrator-set-password'),
        );

        $service = $this->service($server->runtime->database, $server);
        self::assertFalse($service->isValid($token));
        self::assertSame('invalid_token', $this->attemptReset($service, $token, 'stale-token-password'));
        self::assertNull($this->tokenUsedAt($server, $token));
        self::assertSame(303, $server->login('alice', 'administrator-set-password')['response']->status);
        self::assertNotSame(303, $server->login('alice', 'stale-token-password')['response']->status);
    }

    public function testRotatedTokenIsDeniedWhileTheNewestLinkStillWorks(): void
    {
        $server = new TestServer();
        $server->createAccount();
        $first = $this->requestToken($server);
        $second = $this->requestToken($server, [$first]);
        $service = $this->service($server->runtime->database, $server);

        $valid = $service->isValid($first) ? $first : $second;
        $rotated = $valid === $first ? $second : $first;

        self::assertNotSame($valid, $rotated);
        self::assertSame('invalid_token', $this->attemptReset($service, $rotated, 'rotated-password'));
        self::assertSame('success', $this->attemptReset($service, $valid, 'newest-link-password'));
        self::assertSame(303, $server->login('alice', 'newest-link-password')['response']->status);
    }

    public function testConcurrentIssuanceLeavesExactlyOneLiveToken(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the two-process test.');
        }

        $server = new TestServer();
        $server->createAccount();

        [$parentSocket, $childSocket] = $this->socketPair();
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid, 'The test process could not be forked.');

        if ($pid === 0) {
            $server->preserveFiles();
            fclose($parentSocket);

            try {
                $connection = new BarrierConnection(PdoFactory::connect($server->config), $childSocket);
                $issued = $this->service($connection, $server)->request('alice');
                fwrite($childSocket, json_encode(['issued' => $issued], JSON_THROW_ON_ERROR));
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
        $connection = new BarrierConnection(PdoFactory::connect($server->config), $parentSocket);
        $parentIssued = $this->service($connection, $server)->request('alice');

        $childPayload = (string) stream_get_contents($parentSocket);
        fclose($parentSocket);
        pcntl_waitpid($pid, $status);
        self::assertTrue(pcntl_wifexited($status), 'The child process did not exit normally: ' . $childPayload);
        self::assertSame(0, pcntl_wexitstatus($status), 'The child process failed: ' . $childPayload);

        /** @var array{issued?: bool, error?: string} $child */
        $child = json_decode($childPayload, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('error', $child, $childPayload);
        self::assertTrue($parentIssued);
        self::assertTrue($child['issued'] ?? false);
        self::assertSame(1, (int) $server->runtime->database->fetchValue('SELECT COUNT(*) FROM password_reset'));
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

    private function service(Connection $database, TestServer $server): PasswordResetService
    {
        $clock = new SystemClock();

        return new PasswordResetService(
            $database,
            new AccountRepository($database, $clock),
            new PasswordHasher(),
            new NullMailer(),
            $clock,
            $server->config->instanceUrl,
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

    private function insertToken(TestServer $server, string $token): void
    {
        $account = $server->runtime->accounts->find('alice');
        self::assertNotNull($account);
        $now = $server->runtime->clock->now();

        $server->runtime->database->execute(
            'INSERT INTO password_reset'
            . ' (token_hash, account_id, created_at, expires_at, used_at, credential_version)'
            . ' VALUES (:hash, :account, :created, :expires, NULL, :credential_version)',
            [
                'hash' => hash('sha256', $token),
                'account' => $account->id,
                'created' => \AlohaSocial\Core\Support\Time::format($now),
                'expires' => \AlohaSocial\Core\Support\Time::format($now->modify('+1 hour')),
                'credential_version' => $account->credentialVersion,
            ],
        );
    }

    /**
     * @param list<string> $exclude
     */
    private function requestToken(TestServer $server, array $exclude = []): string
    {
        self::assertTrue($server->runtime->passwordReset->request('alice'));
        $mails = $this->mails($server);
        self::assertNotEmpty($mails);

        foreach ($mails as $mail) {
            $message = (string) file_get_contents($mail);
            self::assertSame(1, preg_match('/token=([A-Za-z0-9_-]+)/', $message, $match));
            if (!in_array($match[1], $exclude, true)) {
                return $match[1];
            }
        }

        self::fail('No new reset token was mailed.');
    }

    private function tokenUsedAt(TestServer $server, string $token): ?string
    {
        $value = $server->runtime->database->fetchValue(
            'SELECT used_at FROM password_reset WHERE token_hash = :hash',
            ['hash' => hash('sha256', $token)],
        );

        return $value === null ? null : (string) $value;
    }

    /**
     * @return list<string>
     */
    private function mails(TestServer $server): array
    {
        $files = glob($server->config->mailLogDirectory . '/*.eml');

        return is_array($files) ? array_values($files) : [];
    }
}
