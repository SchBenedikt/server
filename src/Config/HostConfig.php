<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Config;

use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Config\Config;
use AlohaSocial\Server\Support\CoreVersion;
use RuntimeException;

/**
 * The host half of the configuration.
 *
 * The core reads `social/*` keys through its Config port; everything a
 * library cannot know (database, cookie policy, secrets, storage, debug)
 * stays here. The standalone server owns these values, the core never sees
 * them.
 *
 * Precedence is defaults < config/server.php < environment (and `.env`).
 */
final readonly class HostConfig
{
    public function __construct(
        public string $instanceUrl,
        public string $instanceName,
        public string $instanceDescription,
        public string $contactEmail,
        public string $databaseDsn,
        public ?string $databaseUser,
        public ?string $databasePassword,
        public string $varDirectory,
        public string $blobDirectory,
        public string $secretKeyFile,
        public ?string $secretKey,
        public bool $debug,
        public bool $secureCookies,
        public string $sessionCookieName,
        public int $sessionLifetimeSeconds,
        public bool $remoteMedia,
        public bool $registrations,
        public bool $registrationApproval,
        public string $coreChannel,
        public string $coreVersion,
        public bool $mastodonApi,
        public string $mastodonPrefix,
        public bool $atprotoPublish,
        public bool $atprotoPds,
        public string $atprotoPdsUrl,
        public string $atprotoPdsHandleDomain,
        public string $atprotoPlcUrl,
        public string $discoveryInstance,
        public string $mailTransport,
        public string $mailFrom,
        public string $mailLogDirectory,
        public ?string $smtpHost,
        public int $smtpPort,
        public ?string $smtpUser,
        public ?string $smtpPassword,
        public string $smtpEncryption,
        public string $envFile,
    ) {
    }

    /**
     * @param array<string, mixed> $overrides test-only overrides; highest precedence
     */
    public static function load(string $root, array $overrides = []): self
    {
        $file = [];
        $path = $root . '/config/server.php';
        if (is_file($path)) {
            $loaded = require $path;
            if (!is_array($loaded)) {
                throw new RuntimeException('config/server.php must return an array.');
            }
            /** @var array<string, mixed> $loaded */
            $file = $loaded;
        }

        // Drop values this process published for an earlier request. They
        // came from a file, not from the operator's real environment, and the
        // file may have changed (web setup, deployment, an edited .env).
        foreach (self::loadedEnvironment() as $key => $value) {
            if (getenv($key) === $value) {
                putenv($key);
            }
        }
        self::loadedEnvironment(clear: true);

        // Snapshot the remaining real process environment. It keeps the
        // highest precedence and must not be overwritten by any dotenv file.
        $realEnv = getenv();
        $realEnv = is_array($realEnv) ? $realEnv : [];

        $rootEnv = $root . '/.env';
        self::loadDotEnv($rootEnv, $realEnv, false);

        // Setup writes to the configured env file. In Docker that file lives in
        // the persistent `var` volume, so it survives container recreation; it
        // deliberately overrides the checkout's `.env` defaults.
        $configuredEnv = $overrides['env_file'] ?? getenv('ALOHASOCIAL_ENV_FILE');
        $envFile = is_string($configuredEnv) && trim($configuredEnv) !== ''
            ? self::resolvePath(trim($configuredEnv), $root)
            : $rootEnv;
        if ($envFile !== $rootEnv) {
            // Compose forwards the host `.env` as real process variables. The
            // setup-owned file must still win there, otherwise a localhost
            // default from the host file would silently override the domain a
            // visitor configured in the browser.
            self::loadDotEnv($envFile, [], true);
        }

        $pick = static function (string $key, string $env, mixed $default) use ($file, $overrides): mixed {
            if (array_key_exists($key, $overrides)) {
                return $overrides[$key];
            }

            $value = getenv($env);
            if ($value !== false && $value !== '') {
                return $value;
            }

            if (array_key_exists($key, $file)) {
                return $file[$key];
            }

            return $default;
        };

        $bool = static function (mixed $value): bool {
            if (is_bool($value)) {
                return $value;
            }

            return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
        };

        $instanceUrl = rtrim(trim((string) $pick('instance_url', 'ALOHASOCIAL_INSTANCE_URL', 'http://localhost:3000')), '/');
        if (preg_match('#^https?://[^/]+#', $instanceUrl) !== 1) {
            throw new RuntimeException('The instance URL must be an absolute http(s) URL, got: ' . $instanceUrl);
        }

        // Relative paths resolve against the project root, not the process
        // working directory: PHP-FPM starts in public/, the CLI in the root.
        $varDirectory = self::resolvePath((string) $pick('var_directory', 'ALOHASOCIAL_VAR_DIRECTORY', $root . '/var'), $root);
        $secureDefault = str_starts_with(strtolower($instanceUrl), 'https://');
        $secure = $secureDefault;
        $secureValue = $pick('secure_cookies', 'ALOHASOCIAL_SECURE_COOKIES', null);
        if ($secureValue !== null) {
            $secure = $bool($secureValue);
        }

        $lifetime = (int) $pick('session_lifetime', 'ALOHASOCIAL_SESSION_LIFETIME', 604800);
        if ($lifetime < 60) {
            throw new RuntimeException('The session lifetime must be at least 60 seconds.');
        }

        $secret = $pick('secret_key', 'ALOHASOCIAL_SECRET_KEY', null);

        $mailTransport = strtolower(trim((string) $pick('mail_transport', 'ALOHASOCIAL_MAIL_TRANSPORT', 'log')));
        if (!in_array($mailTransport, ['log', 'smtp'], true)) {
            throw new RuntimeException('mail_transport must be log or smtp, got: ' . $mailTransport);
        }

        $smtpEncryption = strtolower(trim((string) $pick('smtp_encryption', 'ALOHASOCIAL_SMTP_ENCRYPTION', 'starttls')));
        if (!in_array($smtpEncryption, ['none', 'starttls', 'tls'], true)) {
            throw new RuntimeException('smtp_encryption must be none, starttls or tls, got: ' . $smtpEncryption);
        }

        $smtpHost = self::nullable($pick('smtp_host', 'ALOHASOCIAL_SMTP_HOST', null));
        if ($mailTransport === 'smtp' && $smtpHost === null) {
            throw new RuntimeException('mail_transport smtp needs ALOHASOCIAL_SMTP_HOST.');
        }

        $mailFrom = trim((string) $pick(
            'mail_from',
            'ALOHASOCIAL_MAIL_FROM',
            'no-reply@' . (parse_url($instanceUrl, PHP_URL_HOST) ?: 'localhost'),
        ));
        // A local development instance has a host like "localhost" or an IP,
        // which FILTER_VALIDATE_EMAIL rejects. Check the shape instead; a real
        // SMTP server still validates the envelope it accepts.
        if ($mailFrom === '' || preg_match('/^[^@\s]+@[^@\s]+$/', $mailFrom) !== 1 || str_contains($mailFrom, "\n") || str_contains($mailFrom, "\r")) {
            throw new RuntimeException('mail_from must be an email address, got: ' . $mailFrom);
        }

        return new self(
            instanceUrl: $instanceUrl,
            instanceName: trim((string) $pick('instance_name', 'ALOHASOCIAL_INSTANCE_NAME', 'Aloha Social')),
            instanceDescription: trim((string) $pick('instance_description', 'ALOHASOCIAL_INSTANCE_DESCRIPTION', '')),
            contactEmail: trim((string) $pick('contact_email', 'ALOHASOCIAL_CONTACT_EMAIL', '')),
            databaseDsn: self::resolveSqliteDsn((string) $pick('database_dsn', 'ALOHASOCIAL_DB_DSN', 'sqlite:' . $varDirectory . '/server.sqlite'), $root),
            databaseUser: self::nullable($pick('database_user', 'ALOHASOCIAL_DB_USER', null)),
            databasePassword: self::nullable($pick('database_password', 'ALOHASOCIAL_DB_PASSWORD', null)),
            varDirectory: $varDirectory,
            blobDirectory: self::resolvePath((string) $pick('blob_directory', 'ALOHASOCIAL_BLOB_DIRECTORY', $varDirectory . '/blobs'), $root),
            secretKeyFile: self::resolvePath((string) $pick('secret_key_file', 'ALOHASOCIAL_SECRET_KEY_FILE', $varDirectory . '/secret.key'), $root),
            secretKey: self::nullable($secret),
            debug: $bool($pick('debug', 'ALOHASOCIAL_DEBUG', false)),
            secureCookies: $secure,
            sessionCookieName: (string) $pick('session_cookie_name', 'ALOHASOCIAL_SESSION_COOKIE_NAME', 'alohasocial_session'),
            sessionLifetimeSeconds: $lifetime,
            remoteMedia: $bool($pick('remote_media', 'ALOHASOCIAL_REMOTE_MEDIA', true)),
            registrations: $bool($pick('registrations', 'ALOHASOCIAL_REGISTRATIONS', false)),
            registrationApproval: $bool($pick('registration_approval', 'ALOHASOCIAL_REGISTRATION_APPROVAL', true)),
            coreChannel: self::normalizeCoreChannel((string) $pick('core_channel', 'ALOHASOCIAL_CORE_CHANNEL', 'main')),
            coreVersion: trim((string) $pick('core_version', 'ALOHASOCIAL_CORE_VERSION', '')),
            mastodonApi: $bool($pick('mastodon_api', 'ALOHASOCIAL_MASTODON_API', true)),
            mastodonPrefix: (string) $pick('mastodon_prefix', 'ALOHASOCIAL_MASTODON_PREFIX', '/api/mastodon/v1'),
            atprotoPublish: $bool($pick('atproto_publish', 'ALOHASOCIAL_ATPROTO_PUBLISH', true)),
            atprotoPds: $bool($pick('atproto_pds', 'ALOHASOCIAL_ATPROTO_PDS', false)),
            atprotoPdsUrl: rtrim(trim((string) $pick('atproto_pds_url', 'ALOHASOCIAL_ATPROTO_PDS_URL', $instanceUrl)), '/'),
            atprotoPdsHandleDomain: self::normalizeHandleDomain((string) $pick(
                'atproto_pds_handle_domain',
                'ALOHASOCIAL_ATPROTO_PDS_HANDLE_DOMAIN',
                (string) (parse_url($instanceUrl, PHP_URL_HOST) ?: ''),
            )),
            atprotoPlcUrl: rtrim(trim((string) $pick('atproto_plc_url', 'ALOHASOCIAL_ATPROTO_PLC_URL', 'https://plc.directory')), '/'),
            discoveryInstance: self::normalizeDiscoveryHost((string) $pick('discovery_instance', 'ALOHASOCIAL_DISCOVERY_INSTANCE', 'mastodon.social')),
            mailTransport: $mailTransport,
            mailFrom: $mailFrom,
            mailLogDirectory: self::resolvePath((string) $pick('mail_log_directory', 'ALOHASOCIAL_MAIL_LOG_DIR', $varDirectory . '/mail'), $root),
            smtpHost: $smtpHost,
            smtpPort: (int) $pick('smtp_port', 'ALOHASOCIAL_SMTP_PORT', 587),
            smtpUser: self::nullable($pick('smtp_user', 'ALOHASOCIAL_SMTP_USER', null)),
            smtpPassword: self::nullable($pick('smtp_password', 'ALOHASOCIAL_SMTP_PASSWORD', null)),
            smtpEncryption: $smtpEncryption,
            envFile: self::resolvePath((string) $pick('env_file', 'ALOHASOCIAL_ENV_FILE', $root . '/.env'), $root),
        );
    }

    /**
     * The configuration the core reads. Everything else belongs to the host.
     */
    public function coreConfig(): Config
    {
        $social = [
            'instance.url' => $this->instanceUrl,
            'instance.name' => $this->instanceName,
            'instance.version' => CoreVersion::current(),
            'federation.enabled' => 'true',
            'instance.registrations' => $this->registrations ? 'true' : 'false',
            'instance.approval_required' => $this->registrationApproval ? 'true' : 'false',
            'media.remote_fetch' => $this->remoteMedia ? 'true' : 'false',
            'mastodon.api' => $this->mastodonApi ? 'true' : 'false',
            'mastodon.prefix' => $this->mastodonPrefix,
            'atproto.publish' => $this->atprotoPublish ? 'true' : 'false',
            'atproto.pds.enabled' => $this->atprotoPds ? 'true' : 'false',
            'atproto.pds.public_url' => $this->atprotoPdsUrl,
            'atproto.pds.handle_domain' => $this->atprotoPdsHandleDomain,
        ];

        if ($this->atprotoPds && $this->atprotoPdsHandleDomain === '') {
            throw new RuntimeException('atproto_pds_handle_domain is required when the native PDS is enabled.');
        }

        if ($this->atprotoPds
            && parse_url($this->atprotoPdsUrl, PHP_URL_SCHEME) !== 'https'
            && !self::isLoopbackUrl($this->atprotoPdsUrl)
        ) {
            throw new RuntimeException('atproto_pds_url must be an https origin when the native PDS is enabled.');
        }

        if ($this->atprotoPds && parse_url($this->atprotoPlcUrl, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('atproto_plc_url must be https when the native PDS is enabled.');
        }

        if ($this->instanceDescription !== '') {
            $social['instance.description'] = $this->instanceDescription;
        }

        if ($this->contactEmail !== '') {
            $social['instance.contact_email'] = $this->contactEmail;
        }

        return new ArrayConfig(['social' => $social]);
    }

    public function host(): string
    {
        $host = parse_url($this->instanceUrl, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'localhost';
    }

    /**
     * The discovery host used for federated keyword and hashtag search.
     *
     * A plain host (`mastodon.social`), an `http(s)://` URL and an empty value
     * (disable remote discovery) are all accepted. The search is a public,
     * unauthenticated read; the operator can point it at a different
     * Mastodon-compatible instance or turn it off.
     */
    public static function normalizeCoreChannel(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['main', 'release'], true) ? $value : 'main';
    }

    private static function isLoopbackUrl(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));

        return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }

    public static function normalizeHandleDomain(string $value): string
    {
        $value = strtolower(trim($value));

        return preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $value) === 1 ? $value : '';
    }

    public static function normalizeDiscoveryHost(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        if (str_contains($value, '://')) {
            $host = parse_url($value, PHP_URL_HOST);
            $value = is_string($host) ? $host : '';
        }

        return preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $value) === 1 ? $value : '';
    }

    /**
     * Turns a relative path into a root-relative one. Absolute paths (POSIX or
     * Windows) are returned unchanged.
     */
    private static function resolvePath(string $path, string $root): string
    {
        $path = rtrim(trim($path), '/');
        if ($path === '') {
            return rtrim($root, '/');
        }

        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $path;
        }

        return rtrim($root, '/') . '/' . $path;
    }

    /**
     * `sqlite:var/server.sqlite` must not depend on the process working
     * directory either.
     */
    private static function resolveSqliteDsn(string $dsn, string $root): string
    {
        if (!str_starts_with($dsn, 'sqlite:')) {
            return $dsn;
        }

        $path = substr($dsn, 7);
        if ($path === '' || $path === ':memory:' || str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $dsn;
        }

        return 'sqlite:' . rtrim($root, '/') . '/' . $path;
    }

    private static function nullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * A minimal `.env` reader: KEY=VALUE lines, comments ignored, real
     * environment variables win. `$override` lets the setup-owned env file
     * take precedence over the checkout's `.env`, while `$protected` keeps
     * genuine process variables above both. No dependency, no magic.
     *
     * @param array<string, string> $protected
     */
    private static function loadDotEnv(string $file, array $protected, bool $override): void
    {
        if (!is_file($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '' || array_key_exists($key, $protected)) {
                continue;
            }

            if (!$override && getenv($key) !== false) {
                continue;
            }

            $value = trim($value);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[strlen($value) - 1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            putenv($key . '=' . $value);
            self::loadedEnvironment($key, $value);
        }
    }

    /**
     * Keys this process loaded from a dotenv file. A static local keeps the
     * state without making the immutable config object hold mutable state.
     *
     * @return array<string, string>
     */
    private static function loadedEnvironment(?string $track = null, ?string $value = null, bool $clear = false): array
    {
        static $loaded = [];

        if ($clear) {
            $loaded = [];
        }

        if ($track !== null && $value !== null) {
            $loaded[$track] = $value;
        }

        return $loaded;
    }

    /**
     * Marks values written by the caller as file-backed, so the next load
     * clears and re-reads them instead of treating them as real environment.
     *
     * @param array<string, string> $values
     */
    public static function markLoadedEnvironment(array $values): void
    {
        foreach ($values as $key => $value) {
            self::loadedEnvironment($key, $value);
        }
    }
}
