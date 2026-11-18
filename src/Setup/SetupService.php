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
use AlohaSocial\Server\Config\HostConfig;
use AlohaSocial\Server\Support\EnvFile;

use function bin2hex;
use function filter_var;
use function in_array;
use function is_array;
use function is_string;
use function json_encode;
use function mb_strlen;
use function parse_url;
use function preg_match;
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
 * The terminal reset creates a one-time token, so a public but empty instance
 * cannot be claimed by whoever loads it first. Setup only changes settings
 * that must be decided before accounts and posts exist: the public origin,
 * the instance identity and the first administrator.
 */
final class SetupService
{
    private readonly SetupToken $token;

    public function __construct(
        private readonly HostConfig $config,
        private readonly Connection $db,
        private readonly AccountRepository $accounts,
        private readonly PasswordHasher $hasher,
        private readonly Clock $clock,
    ) {
        $this->token = new SetupToken($config->varDirectory . '/setup.token');
    }

    public function enabled(): bool
    {
        return $this->token->exists() && $this->accounts->count() === 0;
    }

    public function isFresh(): bool
    {
        return $this->accounts->count() === 0;
    }

    public function token(): ?string
    {
        return $this->token->read();
    }

    public function verifyToken(string $candidate): bool
    {
        return $this->token->verify($candidate);
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
        $token = $this->text($input, 'token');
        if (!$this->token->verify($token)) {
            throw new SetupException(
                'This setup link is not valid. Open the one printed by the reset command.',
                ['token' => 'The setup token is invalid or has expired.'],
            );
        }

        if ($this->accounts->count() > 0) {
            throw new SetupException('This instance already has accounts. Web setup is closed.');
        }

        $url = rtrim($this->text($input, 'url'), '/');
        $name = $this->text($input, 'name');
        $description = $this->text($input, 'description');
        $contact = $this->text($input, 'contact');
        $handle = strtolower($this->text($input, 'handle'));
        $email = strtolower($this->text($input, 'email'));
        $displayName = $this->text($input, 'display_name');
        $password = $this->text($input, 'password');
        $confirmation = $this->text($input, 'password_confirm');

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
        if (mb_strlen($password) < 8) {
            $fields['password'] = 'The password needs at least 8 characters.';
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
        EnvFile::set($this->config->envFile, $settings);

        // HostConfig reads the env file through getenv(). Refresh this process
        // as well, so the current request sees the new origin immediately.
        // Mark the keys as file-backed; the next request clears and reloads
        // them, which is what makes setup changes visible to long-running
        // FPM workers without a restart.
        foreach ($settings as $key => $value) {
            putenv($key . '=' . $value);
        }
        HostConfig::markLoadedEnvironment($settings);

        $account = $this->accounts->create(
            $handle,
            $email,
            $this->hasher->hash($password),
            Account::ROLE_ADMIN,
            $displayName === '' ? $handle : $displayName,
        );

        $now = Time::format($this->clock->now());
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
                'created_at' => $now,
            ],
        );

        $this->token->clear();

        return ['url' => $url, 'handle' => $account->handle];
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
}
