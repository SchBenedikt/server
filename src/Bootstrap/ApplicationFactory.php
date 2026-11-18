<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Bootstrap;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\PdoActivityLog;
use AlohaSocial\Core\Domain\Notification\PdoNotificationRepository;
use AlohaSocial\Core\Domain\Post\PdoPostRevisionRepository;
use AlohaSocial\Core\Http\Router;
use AlohaSocial\Core\Jobs\PdoJobQueue;
use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Core\Platform\Http\CurlHttpClient;
use AlohaSocial\Core\Platform\Network\DnsHostResolver;
use AlohaSocial\Core\Platform\Storage\LocalBlobStore;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Protocol\ActivityPub\HttpActorResolver;
use AlohaSocial\Core\Security\OpenSslSecretBox;
use AlohaSocial\Core\Security\SecretBox;
use AlohaSocial\Core\Service\Federation\FederationTargetGuard;
use AlohaSocial\Core\Service\NotificationService;
use AlohaSocial\Core\Support\RandomIdGenerator;
use AlohaSocial\Server\Federation\BlueskyAccounts;
use AlohaSocial\Server\Federation\RemoteDirectory;
use AlohaSocial\Server\Federation\RemoteMentionResolver;
use AlohaSocial\Server\Accounts\AccountDirectory;
use AlohaSocial\Server\Accounts\AccountRepository;
use AlohaSocial\Server\Admin\AdminService;
use AlohaSocial\Server\Auth\LoginThrottle;
use AlohaSocial\Server\Community\Announcements;
use AlohaSocial\Server\Community\Archives;
use AlohaSocial\Server\Community\Collections;
use AlohaSocial\Server\Community\Drafts;
use AlohaSocial\Server\Community\Exports;
use AlohaSocial\Server\Community\FeaturedTags;
use AlohaSocial\Server\Community\FollowRequests;
use AlohaSocial\Server\Community\ForYouFeed;
use AlohaSocial\Server\Community\KokuaBoard;
use AlohaSocial\Server\Community\Memories;
use AlohaSocial\Server\Community\PinnedPosts;
use AlohaSocial\Server\Community\Preferences;
use AlohaSocial\Server\Community\Stories;
use AlohaSocial\Server\Community\Trends;
use AlohaSocial\Server\Auth\PasswordHasher;
use AlohaSocial\Server\Auth\PasswordResetService;
use AlohaSocial\Server\Auth\SessionManager;
use AlohaSocial\Server\Auth\SessionRepository;
use AlohaSocial\Server\Config\HostConfig;
use AlohaSocial\Server\Db\PdoFactory;
use AlohaSocial\Server\Http\RateLimiter;
use AlohaSocial\Server\Http\View;
use AlohaSocial\Server\Interactions\PostInteractions;
use AlohaSocial\Server\Mail\LogMailer;
use AlohaSocial\Server\Mail\SmtpMailer;
use AlohaSocial\Server\Migrations\MigrationRunner;
use AlohaSocial\Server\Migrations\Migrations;
use AlohaSocial\Server\Setup\SetupService;
use AlohaSocial\Server\Support\RuntimeLogger;
use RuntimeException;
use Throwable;

/**
 * The boot sequence from the server blueprint, steps 1-9.
 *
 * Every step fails loudly: a half-working instance that serves 500s on the
 * first request is worse than one that refuses to start. The schema check
 * runs before anything queries a table, so a forgotten migration produces a
 * readable message instead of a PDO exception.
 */
final class ApplicationFactory
{
    public function __construct(
        private readonly HostConfig $config,
        private readonly string $root,
    ) {
    }

    public function boot(): Runtime
    {
        $clock = new SystemClock();
        $ids = new RandomIdGenerator();

        // 2. Database
        $database = PdoFactory::connect($this->config);
        $this->assertSchemaCurrent($database);

        // 4. Instance keys, sealed at rest
        $signingKey = (new KeyStore($database, $this->secretBox(), $clock))->loadOrCreate();

        // 5. HTTP client and DNS policy (shared by federation, media, threads)
        $logger = new RuntimeLogger($database, $clock);
        $httpClient = new CurlHttpClient();
        $hostResolver = new DnsHostResolver();
        $remoteDirectory = new RemoteDirectory(
            $httpClient,
            new FederationTargetGuard($hostResolver),
            $this->config->discoveryInstance,
        );

        // 6.-8. Repositories, queue/log and the core assembly
        $accounts = new AccountRepository($database, $clock);
        $directory = new AccountDirectory($accounts);
        $queue = new PdoJobQueue($database, $clock, $ids);
        $activityLog = new PdoActivityLog($database);

        $blobDirectory = $this->config->blobDirectory;
        if (!is_dir($blobDirectory) && !mkdir($blobDirectory, 0o770, true) && !is_dir($blobDirectory)) {
            throw new RuntimeException('Cannot create the blob directory: ' . $blobDirectory);
        }

        $blueskyAccounts = new BlueskyAccounts($database, $httpClient, $this->secretBox(), $clock);

        $app = Application::forHost(
            config: $this->config->coreConfig(),
            database: $database,
            httpClient: $httpClient,
            signingKey: $signingKey,
            localActors: $directory,
            queue: $queue,
            activityLog: $activityLog,
            actorResolver: new HttpActorResolver($httpClient),
            mentionResolver: new RemoteMentionResolver($remoteDirectory),
            clock: $clock,
            logger: $logger,
            debug: $this->config->debug,
            blobStore: new LocalBlobStore($blobDirectory),
            hostResolver: $hostResolver,
            atprotoAccounts: $blueskyAccounts,
        );

        $mailer = $this->config->mailTransport === 'smtp'
            ? new SmtpMailer(
                (string) $this->config->smtpHost,
                $this->config->smtpPort,
                $this->config->smtpEncryption,
                $this->config->smtpUser,
                $this->config->smtpPassword,
                $this->config->mailFrom,
            )
            : new LogMailer($this->config->mailLogDirectory, $this->config->mailFrom);

        $passwordHasher = new PasswordHasher();
        $sessions = new SessionManager(new SessionRepository($database), $accounts, $this->config, $clock);
        $interactions = new PostInteractions(
            $app->posts,
            $database,
            $clock,
            new NotificationService(new PdoNotificationRepository($database), $clock, $logger),
        );

        return new Runtime(
            config: $this->config,
            database: $database,
            app: $app,
            router: new Router($app->routes),
            rateLimiter: new RateLimiter($database, $clock),
            accounts: $accounts,
            directory: $directory,
            sessions: $sessions,
            throttle: new LoginThrottle($database, $clock),
            passwordHasher: $passwordHasher,
            passwordReset: new PasswordResetService(
                $database,
                $accounts,
                $passwordHasher,
                $mailer,
                $clock,
                $this->config->instanceUrl,
            ),
            mailer: $mailer,
            view: new View($this->root . '/resources/views'),
            clock: $clock,
            interactions: $interactions,
            remoteDirectory: $remoteDirectory,
            admin: new AdminService(
                $database,
                $clock,
                $accounts,
                $passwordHasher,
                $sessions,
                $app,
                $this->config,
            ),
            announcements: new Announcements($database, $clock),
            featuredTags: new FeaturedTags($database, $clock),
            trends: new Trends($database, $clock),
            memories: new Memories($database, $clock),
            stories: new Stories($database, $clock),
            collections: new Collections($database, $clock, $app->posts),
            drafts: new Drafts($database, $clock),
            archives: new Archives($database, $clock, $app->posts),
            forYou: new ForYouFeed($database, $clock),
            preferences: new Preferences($database, $clock),
            exports: new Exports($database, $clock, new Preferences($database, $clock), $this->config),
            followRequests: new FollowRequests(
                $database,
                $clock,
                $app->followers,
                new ActivityFactory($this->config->coreConfig()),
                new NotificationService(new PdoNotificationRepository($database), $clock, $logger),
            ),
            postRevisions: new PdoPostRevisionRepository($database),
            pinnedPosts: new PinnedPosts($database, $clock, $app->posts),
            kokua: new KokuaBoard($database, $clock),
            setup: new SetupService(
                $this->config,
                $database,
                $accounts,
                $passwordHasher,
                $clock,
            ),
            blueskyAccounts: $blueskyAccounts,
        );
    }

    public function root(): string
    {
        return $this->root;
    }

    private function assertSchemaCurrent(\AlohaSocial\Core\Platform\Db\Connection $database): void
    {
        $migrations = Migrations::all();
        $runner = new MigrationRunner($database, new SystemClock(), $migrations);

        if (!$runner->isInstalled()) {
            throw new RuntimeException('The database schema is not installed. Run: php bin/update.php');
        }

        // A new migration that nobody ran would otherwise surface as a 500 on
        // the first request that touches the missing table.
        $applied = $runner->applied();
        $pending = [];
        foreach ($migrations as $migration) {
            if (!isset($applied[$migration->version()])) {
                $pending[] = $migration->version();
            }
        }

        if ($pending !== []) {
            throw new RuntimeException(
                'Pending migrations: ' . implode(', ', $pending) . '. Run: php bin/update.php',
            );
        }
    }

    /**
     * The encryption key lives outside the database and the repository: an
     * environment value or a file next to the other runtime state.
     */
    private function secretBox(): SecretBox
    {
        if ($this->config->secretKey !== null) {
            return OpenSslSecretBox::fromBase64Key($this->config->secretKey);
        }

        $file = $this->config->secretKeyFile;
        if (!is_file($file)) {
            $directory = dirname($file);
            if (!is_dir($directory) && !mkdir($directory, 0o770, true) && !is_dir($directory)) {
                throw new RuntimeException('Cannot create the key directory: ' . $directory);
            }

            $key = OpenSslSecretBox::generateKey();
            if (file_put_contents($file, $key . "\n") === false) {
                throw new RuntimeException('Cannot write the secret key file: ' . $file);
            }

            @chmod($file, 0o600);
        }

        $value = file_get_contents($file);
        if ($value === false || trim($value) === '') {
            throw new RuntimeException('The secret key file is empty: ' . $file);
        }

        try {
            return OpenSslSecretBox::fromBase64Key(trim($value));
        } catch (Throwable $exception) {
            throw new RuntimeException('The secret key file is not a valid key: ' . $file, 0, $exception);
        }
    }
}
