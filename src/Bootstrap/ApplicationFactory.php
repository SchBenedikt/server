<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Bootstrap;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Atproto\Pds\NativePdsService;
use AlohaSocial\Core\Domain\Atproto\Pds\PdoPdsRepository;
use AlohaSocial\Core\Domain\Atproto\PdoAtprotoFollowRepository;
use AlohaSocial\Core\Domain\Atproto\PdoAtprotoInboundRepository;
use AlohaSocial\Core\Domain\Federation\PdoBlockRepository;
use AlohaSocial\Core\Protocol\Atproto\PlcDirectoryClient;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoPublisher;
use AlohaSocial\Core\Domain\Federation\PdoActivityLog;
use AlohaSocial\Core\Domain\Moderation\PdoModerationActionRepository;
use AlohaSocial\Core\Domain\Moderation\StoredModerationPolicy;
use AlohaSocial\Core\Domain\Notification\PdoNotificationRepository;
use AlohaSocial\Core\Domain\Post\PdoPostRevisionRepository;
use AlohaSocial\Core\Http\Router;
use AlohaSocial\Core\Jobs\PdoJobQueue;
use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Core\Platform\Http\BoundedHttpClient;
use AlohaSocial\Core\Platform\Http\CurlHttpClient;
use AlohaSocial\Core\Platform\Http\HttpClient;
use AlohaSocial\Core\Platform\Network\DnsHostResolver;
use AlohaSocial\Core\Platform\Network\HostResolver;
use AlohaSocial\Core\Platform\Storage\S3CompatibleClient;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Protocol\ActivityPub\HttpActorResolver;
use AlohaSocial\Core\Security\OpenSslSecretBox;
use AlohaSocial\Core\Security\SecretBox;
use AlohaSocial\Core\Service\Federation\FederationTargetGuard;
use AlohaSocial\Core\Service\NotificationService;
use AlohaSocial\Core\Support\RandomIdGenerator;
use AlohaSocial\Server\Federation\Atproto\AtprotoBackoff;
use AlohaSocial\Server\Federation\Atproto\AtprotoReconciler;
use AlohaSocial\Server\Federation\Atproto\JetstreamEventSource;
use AlohaSocial\Server\Federation\ActivityPubRequestSigner;
use AlohaSocial\Server\Federation\Atproto\RemoteInteractionTargetPolicy;
use AlohaSocial\Server\Federation\BlueskyAccounts;
use AlohaSocial\Server\Federation\AtprotoOAuthService;
use AlohaSocial\Server\Federation\CompositeAtprotoAccounts;
use AlohaSocial\Server\Federation\CompositeAtprotoPublisher;
use AlohaSocial\Server\Federation\FederationHttpDiagnostics;
use AlohaSocial\Server\Federation\NativePdsAccountResolver;
use AlohaSocial\Server\Federation\NativePdsPublisher;
use AlohaSocial\Server\Federation\RemoteActorProfiles;
use AlohaSocial\Server\Federation\RemoteDirectory;
use AlohaSocial\Server\Federation\RemoteEngagement;
use AlohaSocial\Server\Federation\RemoteEngagementRefresher;
use AlohaSocial\Server\Federation\RemotePostImporter;
use AlohaSocial\Server\Federation\RemoteReactions;
use AlohaSocial\Server\Federation\RemoteReplies;
use AlohaSocial\Server\Federation\Mastodon\PdoEventBroker;
use AlohaSocial\Server\Federation\RemoteMentionResolver;
use AlohaSocial\Server\Accounts\AccountErasureCleanup;
use AlohaSocial\Server\Accounts\AccountDirectory;
use AlohaSocial\Server\Accounts\AccountProfileProvider;
use AlohaSocial\Server\Accounts\AccountRepository;
use AlohaSocial\Server\Accounts\RegistrationService;
use AlohaSocial\Server\Admin\AdminService;
use AlohaSocial\Server\Auth\LoginThrottle;
use AlohaSocial\Server\Community\Announcements;
use AlohaSocial\Server\Community\Archives;
use AlohaSocial\Server\Community\Collections;
use AlohaSocial\Server\Community\Drafts;
use AlohaSocial\Server\Community\Exports;
use AlohaSocial\Server\Community\FeaturedTags;
use AlohaSocial\Server\Community\FederatedTimeline;
use AlohaSocial\Server\Community\FollowRequests;
use AlohaSocial\Server\Community\ForYouFeed;
use AlohaSocial\Server\Community\KokuaBoard;
use AlohaSocial\Server\Community\LiveUpdates;
use AlohaSocial\Server\Community\Memories;
use AlohaSocial\Server\Community\PinnedPosts;
use AlohaSocial\Server\Community\Preferences;
use AlohaSocial\Server\Community\SocialGraph;
use AlohaSocial\Server\Community\StarterPacks;
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
use AlohaSocial\Server\Storage\StorageFactory;
use AlohaSocial\Server\Interactions\PostInteractions;
use AlohaSocial\Server\Interactions\AtprotoRemoteInteractions;
use AlohaSocial\Server\Mail\LogMailer;
use AlohaSocial\Server\Mail\SmtpMailer;
use AlohaSocial\Server\Migrations\CoreSchemaFingerprint;
use AlohaSocial\Server\Migrations\MigrationRunner;
use AlohaSocial\Server\Migrations\Migrations;
use AlohaSocial\Server\Setup\SetupService;
use AlohaSocial\Server\Support\RuntimeLogger;
use AlohaSocial\Server\Support\SecretKeyFile;
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
        private readonly ?HttpClient $httpClient = null,
        private readonly ?HostResolver $hostResolver = null,
        private readonly ?S3CompatibleClient $s3ClientOverride = null,
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
        $httpClient = $this->httpClient ?? new CurlHttpClient();
        if ($httpClient instanceof BoundedHttpClient) {
            // Delivery failures otherwise reach the queue as a bare status.
            $httpClient = new FederationHttpDiagnostics($httpClient, $logger);
        }
        $hostResolver = $this->hostResolver ?? new DnsHostResolver();
        $targetGuard = new FederationTargetGuard(
            $hostResolver,
            new StoredModerationPolicy(new PdoModerationActionRepository($database)),
        );
        // Authorized-fetch peers refuse unsigned actor and object reads. One
        // local actor signs those GETs; every local actor shares the instance
        // key, so any existing account can author the request. The provider is
        // resolved per request: accounts may be created after this boot.
        $requestSigner = new ActivityPubRequestSigner(
            $signingKey,
            new ActivityFactory($this->config->coreConfig()),
            $clock,
            static function () use ($database): ?string {
                $id = $database->fetchValue(
                    'SELECT id FROM account WHERE disabled_at IS NULL ORDER BY created_at ASC, id ASC LIMIT 1',
                );

                return is_string($id) && trim($id) !== '' ? $id : null;
            },
        );
        $remoteDirectory = new RemoteDirectory(
            $httpClient,
            $targetGuard,
            $this->config->discoveryInstance,
            $requestSigner,
        );

        // 6.-8. Repositories, queue/log and the core assembly
        $accounts = new AccountRepository($database, $clock);
        $directory = new AccountDirectory($accounts);
        $queue = new PdoJobQueue($database, $clock, $ids);
        $activityLog = new PdoActivityLog($database);

        $storage = StorageFactory::create($this->config, $this->s3ClientOverride);
        $blobStore = $storage->blobStore;

        $blueskyAccounts = new BlueskyAccounts($database, $httpClient, $this->secretBox(), $clock, $targetGuard);
        $pdsRepository = new PdoPdsRepository($database);
        $pdsDirectory = $this->config->atprotoPds
            ? new PlcDirectoryClient($httpClient, $this->config->atprotoPlcUrl)
            : null;
        $nativePds = $this->config->atprotoPds
            ? new NativePdsService(
                $this->config->coreConfig(),
                $pdsRepository,
                $this->secretBox(),
                $clock,
                $blobStore,
                $pdsDirectory,
            )
            : null;
        $atprotoAccounts = $nativePds === null
            ? $blueskyAccounts
            : new CompositeAtprotoAccounts(
                new NativePdsAccountResolver($this->config, $pdsRepository, $database),
                $blueskyAccounts,
            );
        $externalAtprotoPublisher = new XrpcAtprotoPublisher($httpClient);
        $atprotoPublisher = $nativePds === null
            ? $externalAtprotoPublisher
            : new CompositeAtprotoPublisher(
                new NativePdsPublisher($nativePds, $pdsRepository),
                $externalAtprotoPublisher,
            );
        $preferences = new Preferences($database, $clock);
        $socialGraph = new SocialGraph($database, $this->config->instanceUrl);
        $remoteActorProfiles = new RemoteActorProfiles($database, $remoteDirectory, $clock);

        // Inbound AT Protocol content arrives through a host-owned Jetstream
        // relay. The core keeps the durable store and the cursor; this object
        // only owns the WebSocket transport and the instance-side filter.
        $atprotoJetstream = $this->config->atprotoJetstreamUrl === ''
            ? null
            : new JetstreamEventSource($this->config->atprotoJetstreamUrl, $database, $clock);
        $atprotoBackoff = $atprotoJetstream === null
            ? null
            : new AtprotoBackoff($this->config->varDirectory . '/atproto-sync.json', $clock);
        $atprotoFollowRepository = new PdoAtprotoFollowRepository($database);
        $atprotoInboundRepository = new PdoAtprotoInboundRepository($database);
        $atprotoTargetPolicy = new RemoteInteractionTargetPolicy(
            $atprotoInboundRepository,
            new PdoBlockRepository($database),
            $atprotoJetstream?->name() ?? JetstreamEventSource::NAME,
        );
        $mastodonEventBroker = new PdoEventBroker($database, $clock);

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
            blobStore: $blobStore,
            hostResolver: $hostResolver,
            atprotoAccounts: $atprotoAccounts,
            atprotoPublisher: $atprotoPublisher,
            atprotoFollowRepository: $atprotoFollowRepository,
            atprotoInbound: $atprotoInboundRepository,
            atprotoRemoteTargetPolicy: $atprotoTargetPolicy,
            localProfiles: new AccountProfileProvider($accounts, $preferences),
            pdsRepository: $pdsRepository,
            pdsSecrets: $this->secretBox(),
            pdsDirectory: $pdsDirectory,
            atprotoEventSource: $atprotoJetstream,
            eventBroker: $mastodonEventBroker,
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
            $this->config->instanceUrl,
            $app->atprotoInteractions,
            $preferences,
        );

        return new Runtime(
            config: $this->config,
            database: $database,
            app: $app,
            router: new Router($app->routes),
            rateLimiter: new RateLimiter($database, $clock),
            accounts: $accounts,
            accountErasureCleanup: new AccountErasureCleanup(
                $database,
                new ActivityFactory($this->config->coreConfig()),
                $clock,
            ),
            directory: $directory,
            registration: new RegistrationService($this->config, $accounts, $passwordHasher),
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
                $storage->s3Client,
            ),
            announcements: new Announcements($database, $clock),
            featuredTags: new FeaturedTags($database, $clock),
            trends: new Trends($database, $clock),
            memories: new Memories($database, $clock),
            stories: new Stories($database, $clock, $socialGraph),
            collections: new Collections($database, $clock, $app->posts),
            starterPacks: new StarterPacks($database, $clock, $accounts, $remoteDirectory),
            drafts: new Drafts($database, $clock),
            archives: new Archives($database, $clock, $app->posts),
            forYou: new ForYouFeed($database, $clock, null, $socialGraph),
            remoteEngagement: new RemoteEngagement($database, $clock),
            remoteEngagementRefresher: new RemoteEngagementRefresher(
                $database,
                new RemoteEngagement($database, $clock),
                $remoteDirectory,
                $clock,
            ),
            remoteReactions: new RemoteReactions(
                $database,
                $app->reactions,
                new ActivityFactory($this->config->coreConfig()),
                $app->queue,
                $remoteDirectory,
                $clock,
                new RemotePostImporter($database, $remoteDirectory, $clock),
            ),
            remoteReplies: new RemoteReplies(
                $database,
                $app->posts,
                new ActivityFactory($this->config->coreConfig(), $app->media),
                $app->queue,
                $remoteDirectory,
                $clock,
                $app->reactions,
                new RemotePostImporter($database, $remoteDirectory, $clock),
            ),
            remoteActorProfiles: $remoteActorProfiles,
            federatedTimeline: new FederatedTimeline(
                $database,
                new RemoteEngagement($database, $clock),
                $app->media,
                $remoteActorProfiles,
            ),
            liveUpdates: new LiveUpdates($database, $clock, $socialGraph),
            preferences: $preferences,
            exports: new Exports($database, $clock, $preferences, $this->config),
            followRequests: new FollowRequests(
                $database,
                $clock,
                $app->followers,
                new ActivityFactory($this->config->coreConfig()),
                new NotificationService(new PdoNotificationRepository($database), $clock, $logger),
                $app->followRequests,
            ),
            postRevisions: new PdoPostRevisionRepository($database),
            pinnedPosts: new PinnedPosts(
                $database,
                $app->pins ?? throw new RuntimeException('The core pin service is not available.'),
            ),
            kokua: new KokuaBoard($database, $clock),
            setup: new SetupService(
                $this->config,
                $database,
                $accounts,
                $passwordHasher,
                $clock,
            ),
            blueskyAccounts: $blueskyAccounts,
            atprotoOAuth: new AtprotoOAuthService($database, $httpClient, $this->secretBox(), $clock, $targetGuard, $blueskyAccounts, $this->config),
            pds: $app->pds,
            pdsRepository: $pdsRepository,
            atprotoReconciler: $atprotoJetstream === null
                ? null
                : new AtprotoReconciler($database, $clock, $atprotoJetstream->name()),
            atprotoBackoff: $atprotoBackoff,
            atprotoRemoteInteractions: $app->atprotoInteractions === null ? null : new AtprotoRemoteInteractions(
                $database,
                $atprotoInboundRepository,
                $app->atprotoInteractions,
                $atprotoTargetPolicy,
                $atprotoJetstream?->name() ?? JetstreamEventSource::NAME,
            ),
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

        CoreSchemaFingerprint::assertCurrent($database);
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
        $value = SecretKeyFile::readOrCreate($file);

        try {
            return OpenSslSecretBox::fromBase64Key(trim($value));
        } catch (Throwable $exception) {
            throw new RuntimeException('The secret key file is not a valid key: ' . $file, 0, $exception);
        }
    }

}
