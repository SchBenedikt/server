<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Bootstrap;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\PdoActivityLog;
use AlohaSocial\Core\Jobs\PdoJobQueue;
use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Core\Platform\Http\CurlHttpClient;
use AlohaSocial\Core\Platform\Network\DnsHostResolver;
use AlohaSocial\Core\Platform\Storage\LocalBlobStore;
use AlohaSocial\Core\Protocol\ActivityPub\HttpActorResolver;
use AlohaSocial\Core\Security\OpenSslSecretBox;
use AlohaSocial\Core\Security\SecretBox;
use AlohaSocial\Core\Support\RandomIdGenerator;
use AlohaSocial\Server\Accounts\AccountDirectory;
use AlohaSocial\Server\Accounts\AccountRepository;
use AlohaSocial\Server\Auth\LoginThrottle;
use AlohaSocial\Server\Auth\PasswordHasher;
use AlohaSocial\Server\Auth\SessionManager;
use AlohaSocial\Server\Auth\SessionRepository;
use AlohaSocial\Server\Config\HostConfig;
use AlohaSocial\Server\Db\PdoFactory;
use AlohaSocial\Server\Http\View;
use AlohaSocial\Server\Migrations\MigrationRunner;
use AlohaSocial\Server\Migrations\Migrations;
use AlohaSocial\Server\Support\ErrorLogLogger;
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
        $this->assertSchemaInstalled($database);

        // 4. Instance keys, sealed at rest
        $signingKey = (new KeyStore($database, $this->secretBox(), $clock))->loadOrCreate();

        // 5. HTTP client and DNS policy (shared by federation, media, threads)
        $httpClient = new CurlHttpClient();
        $hostResolver = new DnsHostResolver();

        // 6.-8. Repositories, queue/log and the core assembly
        $accounts = new AccountRepository($database, $clock);
        $directory = new AccountDirectory($accounts);
        $queue = new PdoJobQueue($database, $clock, $ids);
        $activityLog = new PdoActivityLog($database);

        $blobDirectory = $this->config->blobDirectory;
        if (!is_dir($blobDirectory) && !mkdir($blobDirectory, 0o770, true) && !is_dir($blobDirectory)) {
            throw new RuntimeException('Cannot create the blob directory: ' . $blobDirectory);
        }

        $app = Application::forHost(
            config: $this->config->coreConfig(),
            database: $database,
            httpClient: $httpClient,
            signingKey: $signingKey,
            localActors: $directory,
            queue: $queue,
            activityLog: $activityLog,
            actorResolver: new HttpActorResolver($httpClient),
            clock: $clock,
            logger: new ErrorLogLogger(),
            debug: $this->config->debug,
            blobStore: new LocalBlobStore($blobDirectory),
            hostResolver: $hostResolver,
        );

        return new Runtime(
            config: $this->config,
            database: $database,
            app: $app,
            accounts: $accounts,
            directory: $directory,
            sessions: new SessionManager(new SessionRepository($database), $accounts, $this->config, $clock),
            throttle: new LoginThrottle($database, $clock),
            passwordHasher: new PasswordHasher(),
            view: new View($this->root . '/resources/views'),
            clock: $clock,
        );
    }

    public function root(): string
    {
        return $this->root;
    }

    private function assertSchemaInstalled(\AlohaSocial\Core\Platform\Db\Connection $database): void
    {
        $runner = new MigrationRunner($database, new SystemClock(), Migrations::all());
        if (!$runner->isInstalled()) {
            throw new RuntimeException('The database schema is not installed. Run: php bin/migrate.php');
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
