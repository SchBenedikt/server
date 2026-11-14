<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests\Support;

use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Server\Accounts\Account;
use AlohaSocial\Server\Auth\PasswordHasher;
use AlohaSocial\Server\Bootstrap\ApplicationFactory;
use AlohaSocial\Server\Bootstrap\Runtime;
use AlohaSocial\Server\Config\HostConfig;
use AlohaSocial\Server\Db\PdoFactory;
use AlohaSocial\Server\Http\FrontController;
use AlohaSocial\Server\Migrations\MigrationRunner;
use AlohaSocial\Server\Migrations\Migrations;
use RuntimeException;

/**
 * A complete server on a throwaway SQLite database.
 *
 * It boots the real `Application::forHost()` assembly and drives the real
 * front controller, so the tests exercise the same code path a request does,
 * not a mock of it.
 */
final class TestServer
{
    public const PASSWORD = 'correct-horse-battery-staple';

    public const COOKIE = 'alohasocial_session';

    public readonly HostConfig $config;

    public readonly Runtime $runtime;

    private readonly string $directory;

    /**
     * @param array<string, mixed> $overrides
     */
    public function __construct(array $overrides = [])
    {
        $root = dirname(__DIR__, 2);
        $this->directory = sys_get_temp_dir() . '/alohasocial-server-' . bin2hex(random_bytes(8));

        if (!mkdir($this->directory, 0o770, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Cannot create the test directory.');
        }

        $this->config = HostConfig::load($root, array_merge([
            'instance_url' => 'http://localhost:3000',
            'instance_name' => 'Aloha Test',
            'instance_description' => 'A test instance.',
            'contact_email' => 'admin@example.test',
            'database_dsn' => 'sqlite:' . $this->directory . '/server.sqlite',
            'database_user' => null,
            'database_password' => null,
            'var_directory' => $this->directory,
            'blob_directory' => $this->directory . '/blobs',
            'secret_key_file' => $this->directory . '/secret.key',
            'secret_key' => null,
            'debug' => true,
            'secure_cookies' => false,
            'session_cookie_name' => self::COOKIE,
            'session_lifetime' => 604800,
            'remote_media' => false,
            'mastodon_api' => false,
            'mastodon_prefix' => '/api/mastodon/v1',
        ], $overrides));

        $database = PdoFactory::connect($this->config);
        (new MigrationRunner($database, new SystemClock(), Migrations::all()))->migrate();

        $this->runtime = (new ApplicationFactory($this->config, $root))->boot();
    }

    public function createAccount(
        string $handle = 'alice',
        ?string $email = null,
        string $password = self::PASSWORD,
        bool $admin = false,
    ): Account {
        return $this->runtime->accounts->create(
            $handle,
            $email ?? $handle . '@example.test',
            (new PasswordHasher())->hash($password),
            $admin ? Account::ROLE_ADMIN : Account::ROLE_USER,
        );
    }

    /**
     * @param array<string, mixed> $options cookies, headers, body, query, ip
     */
    public function send(string $method, string $path, array $options = []): Response
    {
        $ip = isset($options['ip']) ? (string) $options['ip'] : '127.0.0.1';

        $request = new Request(
            method: $method,
            path: $path,
            query: $options['query'] ?? [],
            headers: $options['headers'] ?? [],
            body: $options['body'] ?? '',
            remoteAddress: $ip,
        );

        return (new FrontController($this->runtime))->handle(
            $request,
            $options['cookies'] ?? [],
            'PHPUnit',
            $ip,
        );
    }

    /**
     * @param array<string, string> $cookies
     */
    public function form(string $path, array $fields, array $cookies = []): Response
    {
        return $this->send('POST', $path, [
            'cookies' => $cookies,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => http_build_query($fields),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $cookies
     */
    public function json(string $method, string $path, array $payload, array $cookies = [], ?string $csrf = null): Response
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($csrf !== null) {
            $headers['X-CSRF-Token'] = $csrf;
        }

        return $this->send($method, $path, [
            'cookies' => $cookies,
            'headers' => $headers,
            'body' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function cookiesFrom(Response $response): array
    {
        $header = $response->header('Set-Cookie');
        if ($header === null || $header === '') {
            return [];
        }

        $pair = explode(';', $header, 2)[0];
        $parts = explode('=', $pair, 2);
        if (count($parts) !== 2 || $parts[1] === '') {
            return [];
        }

        return [$parts[0] => $parts[1]];
    }

    public function csrfFrom(string $html): ?string
    {
        if (preg_match('/name="_csrf" value="([^"]+)"/', $html, $match) === 1) {
            return html_entity_decode($match[1], ENT_QUOTES);
        }

        return null;
    }

    /**
     * @return array{response: Response, cookies: array<string, string>}
     */
    public function login(string $handle = 'alice', string $password = self::PASSWORD): array
    {
        $form = $this->send('GET', '/login');
        $cookies = $this->cookiesFrom($form);
        $csrf = $this->csrfFrom($form->body);

        if ($csrf === null) {
            throw new RuntimeException('The login form did not contain a CSRF token.');
        }

        $response = $this->form('/login', [
            '_csrf' => $csrf,
            'handle' => $handle,
            'password' => $password,
        ], $cookies);

        return ['response' => $response, 'cookies' => $this->cookiesFrom($response)];
    }

    /**
     * @return array{cookies: array<string, string>, csrf: string, home: Response}
     */
    public function signIn(string $handle = 'alice', string $password = self::PASSWORD): array
    {
        $login = $this->login($handle, $password);
        $cookies = $login['cookies'];
        $home = $this->send('GET', '/', ['cookies' => $cookies]);
        $csrf = $this->csrfFrom($home->body);

        if ($csrf === null) {
            throw new RuntimeException('The signed-in home page did not contain a CSRF token.');
        }

        return ['cookies' => $cookies, 'csrf' => $csrf, 'home' => $home];
    }

    public function __destruct()
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            self::removeDirectory($this->directory);
        }
    }

    private static function removeDirectory(string $directory): void
    {
        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;
            if (is_dir($path)) {
                self::removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
