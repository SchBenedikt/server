<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Auth;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Server\Accounts\Account;
use AlohaSocial\Server\Accounts\AccountRepository;
use AlohaSocial\Server\Config\HostConfig;

/**
 * The session lifecycle.
 *
 * The session id rotates on login: the anonymous session that carried the
 * login CSRF token is deleted and a new row is created. Cookies are `HttpOnly`
 * with `SameSite=Lax`; the cookie value is random and stored only as a hash.
 */
final class SessionManager
{
    public const ANONYMOUS_LIFETIME_SECONDS = 3600;

    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly AccountRepository $accounts,
        private readonly HostConfig $config,
        private readonly Clock $clock,
    ) {
    }

    public function resolve(?string $rawCookie): ?AuthenticatedSession
    {
        if ($rawCookie === null || $rawCookie === '') {
            return null;
        }

        $session = $this->sessions->find(self::hash($rawCookie));
        if ($session === null) {
            return null;
        }

        $now = Time::format($this->clock->now());
        if ($session->isExpired($now)) {
            $this->sessions->delete($session->idHash);

            return null;
        }

        $account = $session->accountId === null ? null : $this->accounts->find($session->accountId);
        if ($session->accountId !== null && ($account === null || $account->isDisabled())) {
            $this->sessions->delete($session->idHash);

            return null;
        }

        return new AuthenticatedSession($session, $account);
    }

    /**
     * Creates a session, replacing the previous one when a cookie is supplied.
     */
    public function issue(?Account $account, ?string $previousCookie = null, ?string $userAgent = null, ?string $ip = null): SessionIssue
    {
        $now = $this->clock->now();
        $this->sessions->deleteExpired(Time::format($now));

        if ($previousCookie !== null && $previousCookie !== '') {
            $this->sessions->delete(self::hash($previousCookie));
        }

        $lifetime = $account === null ? self::ANONYMOUS_LIFETIME_SECONDS : $this->config->sessionLifetimeSeconds;
        $expiresAt = $now->modify('+' . $lifetime . ' seconds');
        $rawId = bin2hex(random_bytes(32));

        $session = new SessionRecord(
            idHash: self::hash($rawId),
            accountId: $account?->id,
            csrfToken: bin2hex(random_bytes(32)),
            createdAt: Time::format($now),
            expiresAt: Time::format($expiresAt),
        );

        $this->sessions->create(
            $session,
            $userAgent,
            $ip === null ? null : hash('sha256', $ip),
        );

        return new SessionIssue($rawId, $session, $expiresAt->getTimestamp());
    }

    public function logout(?string $rawCookie): void
    {
        if ($rawCookie === null || $rawCookie === '') {
            return;
        }

        $this->sessions->delete(self::hash($rawCookie));
    }

    /**
     * Invalidates every signed-in session of an account.
     *
     * Used after a password reset and when an administrator disables an
     * account, so an already issued cookie cannot outlive the decision.
     */
    public function revokeAccount(string $accountId): int
    {
        return $this->sessions->deleteForAccount($accountId);
    }

    /**
     * Live sessions of an account for the security settings page. Rows carry
     * the stored id hash (never a usable cookie) plus device metadata.
     *
     * @return list<array{id_hash: string, created_at: string, expires_at: string, user_agent: ?string, ip_hash: ?string}>
     */
    public function listForAccount(string $accountId): array
    {
        return $this->sessions->listForAccount($accountId, Time::format($this->clock->now()));
    }

    /**
     * Revokes one of the account's own sessions by its stored id hash.
     */
    public function revokeSession(string $accountId, string $idHash): bool
    {
        return $this->sessions->deleteForAccountSession($accountId, $idHash);
    }

    /**
     * Revokes every session of the account except the given one — the
     * caller's current session survives.
     */
    public function revokeOtherSessions(string $accountId, string $keepIdHash): int
    {
        return $this->sessions->deleteOthersForAccount($accountId, $keepIdHash);
    }

    public function csrfMatches(?AuthenticatedSession $session, string $token): bool
    {
        if ($session === null || $token === '') {
            return false;
        }

        return hash_equals($session->session->csrfToken, $token);
    }

    public static function hash(string $rawCookie): string
    {
        return hash('sha256', $rawCookie);
    }
}
