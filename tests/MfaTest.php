<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Auth\Totp;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class MfaTest extends TestCase
{
    public function testTotpMatchesRfc6238Vectors(): void
    {
        // RFC 6238 appendix B seed "12345678901234567890" as base32.
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        self::assertSame('287082', Totp::code($secret, 59));
        self::assertSame('081804', Totp::code($secret, 1111111109));
        self::assertTrue(Totp::verify($secret, '287082', 59));
        self::assertTrue(Totp::verify($secret, '287082', 59 + 30)); // one step of drift
        self::assertFalse(Totp::verify($secret, '287082', 59 + 120)); // beyond the window
        self::assertFalse(Totp::verify($secret, 'abcdef', 59));
    }

    /**
     * Enrolls TOTP for the account and returns [secret, recovery codes].
     *
     * @return array{0: string, 1: list<string>}
     */
    private function enroll(TestServer $server, array $auth): array
    {
        $begin = $server->json('POST', '/api/v1/host/mfa/totp/begin', [], $auth['cookies'], $auth['csrf']);
        self::assertSame(200, $begin->status, $begin->body);
        $secret = json_decode($begin->body, true)['secret'];
        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);

        $code = Totp::code($secret);
        $confirm = $server->json('POST', '/api/v1/host/mfa/totp/confirm', ['code' => $code], $auth['cookies'], $auth['csrf']);
        self::assertSame(200, $confirm->status, $confirm->body);
        $codes = json_decode($confirm->body, true)['recovery_codes'];
        self::assertCount(8, $codes);

        return [$secret, $codes];
    }

    /**
     * Runs the password step of login and returns the MFA challenge page.
     *
     * @return array{cookies: array<string, string>, token: string, csrf: string}
     */
    private function passwordStep(TestServer $server): array
    {
        $form = $server->send('GET', '/login');
        $cookies = $server->cookiesFrom($form);
        $csrf = $server->csrfFrom($form->body);
        self::assertNotNull($csrf);

        $response = $server->form('/login', [
            '_csrf' => $csrf,
            'handle' => 'alice',
            'password' => TestServer::PASSWORD,
        ], $cookies);

        self::assertSame(200, $response->status, $response->body);
        self::assertStringContainsString('Two-factor sign-in', $response->body);
        self::assertStringNotContainsString('name="password"', $response->body);
        self::assertSame(1, preg_match('/name="token" value="([0-9a-f]{64})"/', $response->body, $match));
        $cookies = array_merge($cookies, $server->cookiesFrom($response));
        $mfaCsrf = $server->csrfFrom($response->body);
        self::assertNotNull($mfaCsrf);

        return ['cookies' => $cookies, 'token' => $match[1], 'csrf' => $mfaCsrf];
    }

    private function mfaSubmit(TestServer $server, array $challenge, string $code)
    {
        return $server->form('/login/mfa', [
            '_csrf' => $challenge['csrf'],
            'token' => $challenge['token'],
            'code' => $code,
        ], $challenge['cookies']);
    }

    public function testEnrollmentIssuesRecoveryCodesAndEnables(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');
        $auth = $server->signIn('alice');

        [$secret, $codes] = $this->enroll($server, $auth);

        $status = $server->send('GET', '/api/v1/host/mfa', ['cookies' => $auth['cookies']]);
        self::assertSame(200, $status->status);
        $mfa = json_decode($status->body, true)['mfa'];
        self::assertTrue($mfa['enabled']);
        self::assertFalse($mfa['pending']);

        // The secret never appears in status output.
        self::assertStringNotContainsString($secret, $status->body);
    }

    public function testConfirmRejectsAWrongCode(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');
        $auth = $server->signIn('alice');

        $begin = $server->json('POST', '/api/v1/host/mfa/totp/begin', [], $auth['cookies'], $auth['csrf']);
        $confirm = $server->json('POST', '/api/v1/host/mfa/totp/confirm', ['code' => '000000'], $auth['cookies'], $auth['csrf']);
        self::assertSame(422, $confirm->status, $confirm->body);

        $status = $server->send('GET', '/api/v1/host/mfa', ['cookies' => $auth['cookies']]);
        self::assertTrue(json_decode($status->body, true)['mfa']['pending']);
        self::assertFalse(json_decode($status->body, true)['mfa']['enabled']);
    }

    public function testBeginIsRefusedWhileEnabled(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');
        $auth = $server->signIn('alice');
        $this->enroll($server, $auth);

        $again = $server->json('POST', '/api/v1/host/mfa/totp/begin', [], $auth['cookies'], $auth['csrf']);
        self::assertSame(409, $again->status, $again->body);
    }

    public function testPasswordAloneDoesNotSignIn(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');
        $auth = $server->signIn('alice');
        $this->enroll($server, $auth);

        $challenge = $this->passwordStep($server);

        // The challenge cookies are still anonymous: no account session.
        $sessions = $server->send('GET', '/api/v1/host/sessions', ['cookies' => $challenge['cookies']]);
        self::assertSame(401, $sessions->status);
    }

    public function testTotpCodeCompletesLogin(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');
        $auth = $server->signIn('alice');
        [$secret] = $this->enroll($server, $auth);

        $challenge = $this->passwordStep($server);
        $response = $this->mfaSubmit($server, $challenge, Totp::code($secret));

        self::assertSame(303, $response->status, $response->body);
        $cookies = array_merge($challenge['cookies'], $server->cookiesFrom($response));

        $status = $server->send('GET', '/api/v1/host/mfa', ['cookies' => $cookies]);
        self::assertSame(200, $status->status);
        self::assertTrue(json_decode($status->body, true)['mfa']['enabled']);
    }

    public function testRecoveryCodeWorksExactlyOnce(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');
        $auth = $server->signIn('alice');
        [, $codes] = $this->enroll($server, $auth);

        $challenge = $this->passwordStep($server);
        $response = $this->mfaSubmit($server, $challenge, $codes[0]);
        self::assertSame(303, $response->status, $response->body);

        // The same code cannot be replayed on a fresh challenge.
        $challenge2 = $this->passwordStep($server);
        $replay = $this->mfaSubmit($server, $challenge2, $codes[0]);
        self::assertSame(200, $replay->status);
        self::assertStringContainsString('incorrect', $replay->body);
    }

    public function testANewPasswordStepSupersedesTheOutstandingChallenge(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');
        $auth = $server->signIn('alice');
        [$secret] = $this->enroll($server, $auth);

        $first = $this->passwordStep($server);
        $second = $this->passwordStep($server);

        // The stale challenge is dead even with the right code; attempts
        // cannot be multiplied by minting fresh tokens.
        $stale = $this->mfaSubmit($server, $first, Totp::code($secret));
        self::assertSame(200, $stale->status);
        self::assertStringContainsString('incorrect', $stale->body);

        $fresh = $this->mfaSubmit($server, $second, Totp::code($secret));
        self::assertSame(303, $fresh->status, $fresh->body);
    }

    public function testMfaSubmissionsAreRateLimitedPerAddress(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');
        $auth = $server->signIn('alice');
        $this->enroll($server, $auth);

        $challenge = $this->passwordStep($server);
        for ($i = 0; $i < 20; $i++) {
            $this->mfaSubmit($server, $challenge, '000000');
        }

        $limited = $this->mfaSubmit($server, $challenge, '000000');
        self::assertSame(429, $limited->status, $limited->body);
    }

    public function testPendingChallengeDiesAfterMaxAttempts(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');
        $auth = $server->signIn('alice');
        [$secret] = $this->enroll($server, $auth);

        $challenge = $this->passwordStep($server);
        for ($i = 0; $i < 5; $i++) {
            $this->mfaSubmit($server, $challenge, '000000');
        }

        // Even the right code fails once the challenge is burned.
        $final = $this->mfaSubmit($server, $challenge, Totp::code($secret));
        self::assertSame(200, $final->status);
        self::assertStringContainsString('incorrect', $final->body);
    }

    public function testDisableRequiresAValidCode(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');
        $auth = $server->signIn('alice');
        [$secret] = $this->enroll($server, $auth);

        $wrong = $server->json('POST', '/api/v1/host/mfa/totp/disable', ['code' => '000000'], $auth['cookies'], $auth['csrf']);
        self::assertSame(403, $wrong->status, $wrong->body);

        $right = $server->json('POST', '/api/v1/host/mfa/totp/disable', ['code' => Totp::code($secret)], $auth['cookies'], $auth['csrf']);
        self::assertSame(200, $right->status, $right->body);

        $status = $server->send('GET', '/api/v1/host/mfa', ['cookies' => $auth['cookies']]);
        self::assertFalse(json_decode($status->body, true)['mfa']['enabled']);

        // Password alone signs in again.
        $login = $server->login('alice');
        self::assertSame(303, $login['response']->status);
    }

    public function testMfaEndpointsRequireSignIn(): void
    {
        $server = new TestServer();

        foreach (['GET', 'POST'] as $i => $method) {
            $path = $i === 0 ? '/api/v1/host/mfa' : '/api/v1/host/mfa/totp/begin';
            $response = $server->send($method, $path);
            self::assertSame(401, $response->status, $path);
        }
    }
}
