<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Bootstrap;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Atproto\Pds\NativePdsService;
use AlohaSocial\Core\Domain\Atproto\Pds\PdsRepository;
use AlohaSocial\Core\Domain\Post\PostRevisionRepository;
use AlohaSocial\Core\Http\Router;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Service\Federation\RemotePostAccess;
use AlohaSocial\Server\Accounts\AccountErasureCleanup;
use AlohaSocial\Server\Accounts\AccountDirectory;
use AlohaSocial\Server\Accounts\AccountRepository;
use AlohaSocial\Server\Accounts\EmailChangeService;
use AlohaSocial\Server\Accounts\EmailVerificationService;
use AlohaSocial\Server\Accounts\RegistrationService;
use AlohaSocial\Server\Admin\AdminService;
use AlohaSocial\Server\Auth\LoginThrottle;
use AlohaSocial\Server\Auth\MfaService;
use AlohaSocial\Server\Auth\PasswordHasher;
use AlohaSocial\Server\Auth\PasswordResetService;
use AlohaSocial\Server\Auth\SessionManager;
use AlohaSocial\Server\Community\Announcements;
use AlohaSocial\Server\Community\Archives;
use AlohaSocial\Server\Community\Collections;
use AlohaSocial\Server\Community\Drafts;
use AlohaSocial\Server\Community\Exports;
use AlohaSocial\Server\Community\FeaturedTags;
use AlohaSocial\Server\Community\FederatedTimeline;
use AlohaSocial\Server\Community\FollowRequests;
use AlohaSocial\Server\Community\ForYouFeed;
use AlohaSocial\Server\Community\InstancePages;
use AlohaSocial\Server\Community\Imports;
use AlohaSocial\Server\Community\KokuaBoard;
use AlohaSocial\Server\Community\LiveUpdates;
use AlohaSocial\Server\Community\Memories;
use AlohaSocial\Server\Community\PinnedPosts;
use AlohaSocial\Server\Community\Preferences;
use AlohaSocial\Server\Community\StarterPacks;
use AlohaSocial\Server\Community\Stories;
use AlohaSocial\Server\Community\Trends;
use AlohaSocial\Server\Push\PushSender;
use AlohaSocial\Server\Push\PushService;
use AlohaSocial\Server\Config\HostConfig;
use AlohaSocial\Server\Federation\Atproto\AtprotoBackoff;
use AlohaSocial\Server\Federation\Atproto\AtprotoReconciler;
use AlohaSocial\Server\Federation\BlueskyAccounts;
use AlohaSocial\Server\Federation\AtprotoOAuthService;
use AlohaSocial\Server\Federation\RemoteActorProfiles;
use AlohaSocial\Server\Federation\RemoteDirectory;
use AlohaSocial\Server\Federation\RemoteEngagement;
use AlohaSocial\Server\Federation\RemoteEngagementRefresher;
use AlohaSocial\Server\Federation\RemoteReactions;
use AlohaSocial\Server\Federation\RemoteReplies;
use AlohaSocial\Server\Http\RateLimiter;
use AlohaSocial\Server\Http\View;
use AlohaSocial\Server\Interactions\PostInteractions;
use AlohaSocial\Server\Interactions\AtprotoRemoteInteractions;
use AlohaSocial\Server\Mail\Mailer;
use AlohaSocial\Server\Mail\MailOutbox;
use AlohaSocial\Server\Setup\FederationPreflight;
use AlohaSocial\Server\Setup\SetupService;
use AlohaSocial\Server\Support\Metrics;

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
        public AccountErasureCleanup $accountErasureCleanup,
        public AccountDirectory $directory,
        public RegistrationService $registration,
        public EmailVerificationService $emailVerification,
        public EmailChangeService $emailChange,
        public SessionManager $sessions,
        public MfaService $mfa,
        public LoginThrottle $throttle,
        public PasswordHasher $passwordHasher,
        public PasswordResetService $passwordReset,
        public Mailer $mailer,
        public MailOutbox $mailOutbox,
        public View $view,
        public Clock $clock,
        public PostInteractions $interactions,
        public RemoteDirectory $remoteDirectory,
        public RemotePostAccess $remotePostAccess,
        public RemoteEngagement $remoteEngagement,
        public RemoteEngagementRefresher $remoteEngagementRefresher,
        public RemoteReactions $remoteReactions,
        public RemoteReplies $remoteReplies,
        public RemoteActorProfiles $remoteActorProfiles,
        public FederatedTimeline $federatedTimeline,
        public LiveUpdates $liveUpdates,
        public AdminService $admin,
        public InstancePages $instancePages,
        public PushService $pushSubscriptions,
        public PushSender $pushSender,
        public Announcements $announcements,
        public FeaturedTags $featuredTags,
        public Trends $trends,
        public Memories $memories,
        public Stories $stories,
        public Collections $collections,
        public StarterPacks $starterPacks,
        public Drafts $drafts,
        public Archives $archives,
        public ForYouFeed $forYou,
        public Preferences $preferences,
        public Exports $exports,
        public Imports $imports,
        public FollowRequests $followRequests,
        public PinnedPosts $pinnedPosts,
        public KokuaBoard $kokua,
        public SetupService $setup,
        public BlueskyAccounts $blueskyAccounts,
        public FederationPreflight $preflight,
        public AtprotoOAuthService $atprotoOAuth,
        public PostRevisionRepository $postRevisions,
        public Metrics $metrics,
        public ?NativePdsService $pds,
        public PdsRepository $pdsRepository,
        public ?AtprotoReconciler $atprotoReconciler = null,
        public ?AtprotoBackoff $atprotoBackoff = null,
        public ?AtprotoRemoteInteractions $atprotoRemoteInteractions = null,
    ) {
    }
}
