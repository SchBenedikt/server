<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Setup;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Server\Accounts\Account;
use AlohaSocial\Server\Accounts\AccountRepository;
use AlohaSocial\Server\Auth\PasswordHasher;
use AlohaSocial\Server\Auth\PasswordPolicy;
use AlohaSocial\Server\Config\HostConfig;
use AlohaSocial\Server\Support\EnvFile;
use RuntimeException;
use Throwable;

use function bin2hex;
use function file_get_contents;
use function filter_var;
use function implode;
use function in_array;
use function is_array;
use function is_file;
use function is_string;
use function json_encode;
use function mb_strlen;
use function parse_url;
use function preg_match;
use function putenv;
use function random_bytes;
use function rtrim;
use function str_starts_with;
use function strtolower;
use function trim;

use const FILTER_VALIDATE_EMAIL;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * First-run setup through the browser.
 *
 * A short-lived token is required, so a public but empty instance cannot be
 * claimed by whoever loads it first. Setup only changes settings that must be
 * decided before accounts and posts exist: the public origin, the instance
 * identity and the first administrator.
 *
 * The account, audit row and configuration form one logical operation. The
 * database part runs in a transaction; the environment snapshot lives in a
 * journal so a crash between the `.env` write and the commit can be recovered
 * by the next request. A setup retry never creates a second administrator.
 */
final class SetupService
{
    /**
     * Tables that prove this installation has content even if every account
     * was deleted or disabled. A missing table counts as data: setup fails
     * closed rather than exposing a half-migrated instance.
     */
    private const DATA_TABLES = [
        'social_post',
        'social_remote_post',
        'social_following',
        'social_follower',
        'social_media_collection',
        'social_story',
        'kokua',
        'draft',
        'announcement',
        'social_attachment',
        'social_reaction',
        'social_bookmark',
        'social_favourite',
        'social_boost',
        'social_notification',
        'social_account_list',
        'featured_tag',
        'follow_request',
        'moderation_report',
        'social_report',
    ];

    private readonly SetupToken $token;

    private readonly SetupJournal $journal;

    public function __construct(
        private readonly HostConfig $config,
        private readonly Connection $db,
        private readonly AccountRepository $accounts,
        private readonly PasswordHasher $hasher,
        private readonly Clock $clock,
    ) {
        $this->token = new SetupToken($config->varDirectory . '/setup.token', $clock);
        $this->journal = new SetupJournal($config->varDirectory . '/setup.journal');
    }

    public function enabled(): bool
    {
        $this->reconcile();

        return $this->token->exists() && $this->isFresh();
    }

    public function isFresh(): bool
    {
        return $this->accounts->count() === 0 && !$this->hasInstanceData();
    }

    public function token(): ?string
    {
        return $this->token->read();
    }

    public function tokenExpiresAt(): ?string
    {
        $expiresAt = $this->token->expiresAt();

        return $expiresAt === null ? null : Time::format($expiresAt);
    }

    public function verifyToken(string $candidate): bool
    {
        return $this->token->verify($candidate);
    }

    /**
     * Values that were already set by the operator's process environment.
     *
     * The setup-owned file deliberately wins for these keys, because Compose
     * forwards the host `.env` as process variables and a localhost default
     * must not silently undo the domain chosen in the browser. The form still
     * shows the conflict, so the operator can keep the origin in one place.
     *
     * @return list<string>
     */
    public function environmentWarnings(): array
    {
        if ($this->config->externallyManagedSetupKeys === []) {
            return [];
        }

        return [
            'The process environment also sets '
            . implode(', ', $this->config->externallyManagedSetupKeys)
            . '. The setup file ' . $this->config->envFile . ' takes precedence and replaces these values for the application.',
        ];
    }

    /**
     * Create a fresh token for an empty instance without touching any data.
     *
     * This is the normal first-run command. A token is only valid while no
     * account or content exists; an operator of an existing instance must use
     * the destructive reset explicitly.
     *
     * @throws SetupException
     */
    public function issueToken(int $ttlSeconds = SetupToken::DEFAULT_TTL_SECONDS): string
    {
        return $this->token->synchronized(function () use ($ttlSeconds): string {
            $this->reconcileWhileLocked();

            if (!$this->isFresh()) {
                throw new SetupException(
                    'This instance already has accounts or content. Web setup is closed; '
                    . 'use bin/reset.php only if you deliberately want to delete the installation.',
                );
            }

            return $this->token->generate($ttlSeconds);
        });
    }

    /**
     * Repair an interrupted setup.
     *
     * If the database commit happened, the account and content tables prove
     * it: keep the environment and discard the stale token. Otherwise restore
     * the exact previous environment and keep the token for a retry.
     */
    public function reconcile(): void
    {
        if (!$this->journal->exists()) {
            return;
        }

        $this->token->synchronized(fn (): mixed => $this->reconcileWhileLocked());
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{url: string, handle: string}
     *
     * @throws SetupException
     */
    public function install(array $input): array
    {
        return $this->token->synchronized(fn (): array => $this->installWhileLocked($input));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{url: string, handle: string}
     *
     * @throws SetupException
     */
    private function installWhileLocked(array $input): array
    {
        // A previous process may have died after writing the environment.
        // Recover it under the same lock before checking the token.
        $this->reconcileWhileLocked();

        $token = $this->text($input, 'token');
        if (!$this->token->verify($token)) {
            throw new SetupException(
                'This setup link is not valid or has expired. Run php bin/setup-token.php to print a new one.',
                ['token' => 'The setup token is invalid or has expired.'],
            );
        }

        if (!$this->isFresh()) {
            throw new SetupException('This instance already has accounts or content. Web setup is closed.');
        }

        $url = rtrim($this->text($input, 'url'), '/');
        $name = $this->text($input, 'name');
        $description = $this->text($input, 'description');
        $contact = $this->text($input, 'contact');
        $handle = strtolower($this->text($input, 'handle'));
        $email = strtolower($this->text($input, 'email'));
        $displayName = $this->text($input, 'display_name');
        $password = $this->secret($input, 'password');
        $confirmation = $this->secret($input, 'password_confirm');

        $fields = [];
        if (!$this->validOrigin($url)) {
            $fields['url'] = 'Use an origin such as https://social.example, without a path.';
        } elseif (str_starts_with($url, 'http://') && !$this->isLocalHost($url)) {
            $fields['url'] = 'A public instance needs https:// so federation and secure cookies work.';
        }

        if ($name === '' || mb_strlen($name) > 80) {
            $fields['name'] = 'Enter a name between 1 and 80 characters.';
        }
        if (mb_strlen($description) > 500) {
            $fields['description'] = 'At most 500 characters.';
        }
        if ($contact !== '' && filter_var($contact, FILTER_VALIDATE_EMAIL) === false) {
            $fields['contact'] = 'Enter a valid email address or leave it empty.';
        }
        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,29}$/', $handle) !== 1) {
            $fields['handle'] = '2–30 characters: lowercase letters, digits, "_" or "-".';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $fields['email'] = 'Enter a valid email address.';
        }
        if ($displayName !== '' && mb_strlen($displayName) > 80) {
            $fields['display_name'] = 'At most 80 characters.';
        }
        $passwordError = PasswordPolicy::error($password);
        if ($passwordError !== null) {
            $fields['password'] = $passwordError;
        }
        if ($password !== $confirmation) {
            $fields['password_confirm'] = 'The passwords do not match.';
        }

        if ($fields !== []) {
            throw new SetupException('Please check the highlighted fields.', $fields);
        }

        $settings = [
            'ALOHASOCIAL_INSTANCE_URL' => $url,
            'ALOHASOCIAL_INSTANCE_NAME' => $name,
            'ALOHASOCIAL_INSTANCE_DESCRIPTION' => $description,
            'ALOHASOCIAL_CONTACT_EMAIL' => $contact,
            'ALOHASOCIAL_SECURE_COOKIES' => str_starts_with($url, 'https://') ? 'true' : 'false',
        ];
        $now = $this->clock->now();
        $passwordHash = $this->hasher->hash($password);
        $this->journal->write([
            'created_at' => Time::format($now),
            'env_file' => $this->config->envFile,
            'env_before' => $this->readEnvironment(),
            'settings' => $settings,
            'handle' => $handle,
        ]);

        try {
            $account = $this->db->transaction(function () use (
                $settings,
                $handle,
                $email,
                $displayName,
                $passwordHash,
                $url,
                $name,
                $now,
            ): Account {
                $account = $this->accounts->create(
                    $handle,
                    $email,
                    $passwordHash,
                    Account::ROLE_ADMIN,
                    $displayName === '' ? $handle : $displayName,
                );

                // The environment cannot join the transaction. Write it while
                // the transaction is open and after the journal snapshot, so
                // a later failure rolls back and reconcile restores it.
                EnvFile::set($this->config->envFile, $settings);

                $this->db->execute(
                    'INSERT INTO admin_audit (id, actor_id, action, target_type, target_id, details, created_at)'
                    . ' VALUES (:id, :actor, :action, NULL, NULL, :details, :created_at)',
                    [
                        'id' => bin2hex(random_bytes(16)),
                        'actor' => $account->id,
                        'action' => 'instance.setup',
                        'details' => json_encode(
                            ['url' => $url, 'name' => $name],
                            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                        ),
                        'created_at' => Time::format($now),
                    ],
                );

                return $account;
            });
        } catch (Throwable $exception) {
            try {
                $this->reconcileWhileLocked();
            } catch (Throwable $rollbackException) {
                throw new RuntimeException(
                    'Setup failed and the previous configuration could not be restored automatically. '
                    . 'The recovery journal is kept for the next request.',
                    0,
                    $exception,
                );
            }

            throw $exception;
        }

        // The database commit is the point of no return. Refresh this process
        // before dropping the recovery record.
        foreach ($settings as $key => $value) {
            putenv($key . '=' . $value);
        }
        HostConfig::markLoadedEnvironment($settings);
        $this->token->clear();
        $this->journal->clear();

        return ['url' => $url, 'handle' => $account->handle];
    }

    /**
     * The caller already holds the setup lock.
     */
    private function reconcileWhileLocked(): void
    {
        $journal = $this->journal->read();
        if ($journal === null) {
            return;
        }

        if (($journal['env_file'] ?? null) !== $this->config->envFile) {
            throw new RuntimeException(
                'The setup journal belongs to another environment file: ' . (string) ($journal['env_file'] ?? ''),
            );
        }

        if (!$this->hasInstanceData()) {
            // The transaction did not commit. Restore the file and keep the
            // token so the operator can submit the same setup again.
            $before = $journal['env_before'];
            EnvFile::replace($this->config->envFile, is_string($before) ? $before : null);
            $this->journal->clear();

            return;
        }

        // The account/content proves the commit completed. The token is spent;
        // keep the committed environment and do not create another admin.
        $this->token->clear();
        $this->journal->clear();
    }

    private function hasInstanceData(): bool
    {
        if ($this->accounts->count() > 0) {
            return true;
        }

        foreach (self::DATA_TABLES as $table) {
            try {
                $count = $this->db->fetchValue('SELECT COUNT(*) FROM ' . $table);
            } catch (Throwable) {
                return true;
            }

            if ($count !== null && (int) $count > 0) {
                return true;
            }
        }

        return false;
    }

    private function readEnvironment(): ?string
    {
        if (!is_file($this->config->envFile)) {
            return null;
        }

        $contents = file_get_contents($this->config->envFile);
        if ($contents === false) {
            throw new RuntimeException('Cannot read the configuration file: ' . $this->config->envFile);
        }

        return $contents;
    }

    private function validOrigin(string $url): bool
    {
        if (preg_match('/\s/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (!in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            return false;
        }

        return true;
    }

    private function isLocalHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Password secrets are never trimmed: leading and trailing spaces are part
     * of the exact value the user typed at every entry point.
     *
     * @param array<string, mixed> $input
     */
    private function secret(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
