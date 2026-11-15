<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Bootstrap;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Http\Router;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Server\Accounts\AccountDirectory;
use AlohaSocial\Server\Accounts\AccountRepository;
use AlohaSocial\Server\Auth\LoginThrottle;
use AlohaSocial\Server\Auth\PasswordHasher;
use AlohaSocial\Server\Auth\PasswordResetService;
use AlohaSocial\Server\Auth\SessionManager;
use AlohaSocial\Server\Config\HostConfig;
use AlohaSocial\Server\Federation\RemoteDirectory;
use AlohaSocial\Server\Http\RateLimiter;
use AlohaSocial\Server\Http\View;
use AlohaSocial\Server\Interactions\PostInteractions;
use AlohaSocial\Server\Mail\Mailer;

/**
 * Everything one request or worker tick needs, already wired.
 *
 * The core assembly is `Application::forHost()`; the rest is host state
 * (accounts, sessions, rate limits, view). No global state, no service locator.
 */
final readonly class Runtime
{
    public function __construct(
        public HostConfig $config,
        public PdoConnection $database,
        public Application $app,
        public Router $router,
        public RateLimiter $rateLimiter,
        public AccountRepository $accounts,
        public AccountDirectory $directory,
        public SessionManager $sessions,
        public LoginThrottle $throttle,
        public PasswordHasher $passwordHasher,
        public PasswordResetService $passwordReset,
        public Mailer $mailer,
        public View $view,
        public Clock $clock,
        public PostInteractions $interactions,
        public RemoteDirectory $remoteDirectory,
    ) {
    }
}
