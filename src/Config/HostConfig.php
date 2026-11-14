<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Config;

use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Config\Config;
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
        public bool $mastodonApi,
        public string $mastodonPrefix,
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

        self::loadDotEnv($root . '/.env');

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

        $varDirectory = rtrim((string) $pick('var_directory', 'ALOHASOCIAL_VAR_DIRECTORY', $root . '/var'), '/');
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

        return new self(
            instanceUrl: $instanceUrl,
            instanceName: trim((string) $pick('instance_name', 'ALOHASOCIAL_INSTANCE_NAME', 'Aloha Social')),
            instanceDescription: trim((string) $pick('instance_description', 'ALOHASOCIAL_INSTANCE_DESCRIPTION', '')),
            contactEmail: trim((string) $pick('contact_email', 'ALOHASOCIAL_CONTACT_EMAIL', '')),
            databaseDsn: (string) $pick('database_dsn', 'ALOHASOCIAL_DB_DSN', 'sqlite:' . $varDirectory . '/server.sqlite'),
            databaseUser: self::nullable($pick('database_user', 'ALOHASOCIAL_DB_USER', null)),
            databasePassword: self::nullable($pick('database_password', 'ALOHASOCIAL_DB_PASSWORD', null)),
            varDirectory: $varDirectory,
            blobDirectory: rtrim((string) $pick('blob_directory', 'ALOHASOCIAL_BLOB_DIRECTORY', $varDirectory . '/blobs'), '/'),
            secretKeyFile: (string) $pick('secret_key_file', 'ALOHASOCIAL_SECRET_KEY_FILE', $varDirectory . '/secret.key'),
            secretKey: self::nullable($secret),
            debug: $bool($pick('debug', 'ALOHASOCIAL_DEBUG', false)),
            secureCookies: $secure,
            sessionCookieName: (string) $pick('session_cookie_name', 'ALOHASOCIAL_SESSION_COOKIE_NAME', 'alohasocial_session'),
            sessionLifetimeSeconds: $lifetime,
            remoteMedia: $bool($pick('remote_media', 'ALOHASOCIAL_REMOTE_MEDIA', true)),
            mastodonApi: $bool($pick('mastodon_api', 'ALOHASOCIAL_MASTODON_API', false)),
            mastodonPrefix: (string) $pick('mastodon_prefix', 'ALOHASOCIAL_MASTODON_PREFIX', '/api/mastodon/v1'),
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
            'federation.enabled' => 'true',
            'instance.registrations' => 'false',
            'instance.approval_required' => 'true',
            'media.remote_fetch' => $this->remoteMedia ? 'true' : 'false',
            'mastodon.api' => $this->mastodonApi ? 'true' : 'false',
            'mastodon.prefix' => $this->mastodonPrefix,
        ];

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
     * environment variables win. No dependency, no magic.
     */
    private static function loadDotEnv(string $file): void
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
            if ($key === '' || getenv($key) !== false) {
                continue;
            }

            $value = trim($value);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[strlen($value) - 1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            putenv($key . '=' . $value);
        }
    }
}
