<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Setup;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Platform\Http\BoundedHttpClient;
use AlohaSocial\Server\Config\HostConfig;
use AlohaSocial\Server\Migrations\MigrationRunner;
use AlohaSocial\Server\Migrations\Migrations;
use Throwable;

use function in_array;
use function is_array;
use function is_dir;
use function is_string;
use function is_writable;
use function json_decode;
use function parse_url;
use function preg_match;
use function rawurlencode;
use function str_ends_with;
use function str_starts_with;
use function stream_socket_client;
use function strtolower;
use function time;
use function trim;

/**
 * Read-only readiness checks for first-run setup and operations.
 *
 * Local checks verify this process can serve (database, schema, worker,
 * mail, storage). External checks fetch the *configured public* instance
 * URL — NodeInfo and WebFinger — so a loopback-only deployment is reported
 * honestly instead of being assumed federated. Every fetch is bounded; a
 * failing check names the remediation instead of throwing.
 */
final class FederationPreflight
{
    private const FETCH_TIMEOUT = 5;
    private const MAX_BYTES = 131072;
    private const WORKER_STALE_SECONDS = 90;

    public function __construct(
        private readonly HostConfig $config,
        private readonly Connection $db,
        private readonly BoundedHttpClient $http,
        private readonly Clock $clock,
        private readonly MigrationRunner $migrations,
    ) {
    }

    /**
     * @return array{checks: list<array{id: string, group: string, status: string, summary: string, detail: ?string, remediation: ?string}>, summary: array{ok: int, warn: int, fail: int, skip: int}}
     */
    public function run(bool $external = true): array
    {
        $checks = [
            $this->checkInstanceUrl(),
            $this->checkDatabase(),
            $this->checkSchema(),
            $this->checkWorker(),
            $this->checkMail(),
            $this->checkStorage(),
        ];

        if (!$external) {
            foreach (['origin', 'nodeinfo', 'webfinger'] as $id) {
                $checks[] = $this->check($id, 'external', 'skip', 'External checks not run', 'Pass external=1 to verify the public origin.', null);
            }
        } else {
            $checks[] = $this->checkPublicOrigin();
            $checks[] = $this->checkNodeInfo();
            $checks[] = $this->checkWebFinger();
        }

        $summary = ['ok' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0];
        foreach ($checks as $check) {
            $status = $check['status'];
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }

        return ['checks' => $checks, 'summary' => $summary];
    }

    /**
     * @return array{id: string, group: string, status: string, summary: string, detail: ?string, remediation: ?string}
     */
    private function check(string $id, string $group, string $status, string $summary, ?string $detail, ?string $remediation): array
    {
        return [
            'id' => $id,
            'group' => $group,
            'status' => $status,
            'summary' => $summary,
            'detail' => $detail,
            'remediation' => $remediation,
        ];
    }

    private function checkInstanceUrl(): array
    {
        $url = $this->config->instanceUrl;
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '' || preg_match('#^https?://#i', $url) !== 1) {
            return $this->check('instance_url', 'local', 'fail', 'Instance URL is not a valid http(s) origin', $url, 'Set ALOHASOCIAL_INSTANCE_URL to the public https origin.');
        }

        $loopback = in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true);
        $https = str_starts_with(strtolower($url), 'https://');
        if (!$https && !$loopback && !$this->config->debug) {
            return $this->check('instance_url', 'local', 'warn', 'Instance URL is plain http', $url, 'Terminate TLS at the reverse proxy and set the https origin.');
        }

        return $this->check('instance_url', 'local', 'ok', 'Instance origin configured', $url, null);
    }

    private function checkDatabase(): array
    {
        try {
            $this->db->fetchValue('SELECT 1');
        } catch (DatabaseException $exception) {
            return $this->check('database', 'local', 'fail', 'Database is not reachable', $exception->getMessage(), 'Check ALOHASOCIAL_DATABASE_* and run php bin/doctor.php.');
        }

        return $this->check('database', 'local', 'ok', 'Database reachable', null, null);
    }

    private function checkSchema(): array
    {
        try {
            if (!$this->migrations->isInstalled()) {
                return $this->check('schema', 'local', 'fail', 'Schema is not installed', null, 'Run php bin/update.php.');
            }
            $pending = 0;
            $applied = $this->migrations->applied();
            foreach (Migrations::all() as $migration) {
                if (!isset($applied[$migration->version()])) {
                    $pending++;
                }
            }
        } catch (Throwable $exception) {
            return $this->check('schema', 'local', 'fail', 'Schema state could not be read', $exception->getMessage(), 'Run php bin/doctor.php for migration details.');
        }

        if ($pending > 0) {
            return $this->check('schema', 'local', 'fail', $pending . ' migration(s) pending', null, 'Run php bin/update.php.');
        }

        return $this->check('schema', 'local', 'ok', 'Schema up to date', null, null);
    }

    private function checkWorker(): array
    {
        try {
            $row = $this->db->fetchOne(
                "SELECT value FROM runtime_state WHERE name = 'worker'",
            );
        } catch (DatabaseException) {
            $row = null;
        }

        if ($row === null) {
            return $this->check('worker', 'local', 'warn', 'Worker has never reported', null, 'Start bin/worker.php (or the container worker service); federation delivery and engagement refresh need it.');
        }

        $age = time() - (int) $row['value'];
        if ($age <= self::WORKER_STALE_SECONDS) {
            return $this->check('worker', 'local', 'ok', 'Worker heartbeat fresh', 'last beat ' . $age . 's ago', null);
        }

        return $this->check('worker', 'local', 'warn', 'Worker heartbeat is stale', 'last beat ' . $age . 's ago', 'Restart bin/worker.php; queued deliveries and mail will not drain while it is down.');
    }

    private function checkMail(): array
    {
        if ($this->config->mailTransport === 'log') {
            return $this->check('mail', 'local', 'warn', 'Mail transport is log', 'Messages are written to ' . $this->config->mailLogDirectory, 'Configure ALOHASOCIAL_MAIL_TRANSPORT=smtp for production delivery.');
        }

        $host = $this->config->smtpHost;
        $port = $this->config->smtpPort;
        if ($host === null || $port <= 0) {
            return $this->check('mail', 'local', 'fail', 'SMTP is not configured', null, 'Set ALOHASOCIAL_SMTP_HOST and ALOHASOCIAL_SMTP_PORT.');
        }

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'tcp://' . $host . ':' . $port,
            $errno,
            $errstr,
            3.0,
        );
        if ($socket === false) {
            return $this->check('mail', 'local', 'fail', 'SMTP server is unreachable', trim($errstr) !== '' ? trim($errstr) : 'connection failed', 'Check ALOHASOCIAL_SMTP_HOST/PORT and network egress.');
        }
        fclose($socket);

        return $this->check('mail', 'local', 'ok', 'SMTP server reachable', $host . ':' . $port, null);
    }

    private function checkStorage(): array
    {
        if ($this->config->storageDriver === 's3') {
            return $this->check('storage', 'local', 'skip', 'S3 storage not probed', 'Object storage reachability is verified by php bin/doctor.php.', null);
        }

        $dir = $this->config->blobDirectory;
        $ok = is_dir($dir) ? is_writable($dir) : (is_dir($this->config->varDirectory) && is_writable($this->config->varDirectory));
        if (!$ok) {
            return $this->check('storage', 'local', 'fail', 'Blob directory is not writable', $dir, 'Fix permissions on the blob/var directory or configure object storage.');
        }

        return $this->check('storage', 'local', 'ok', 'Blob storage writable', $dir, null);
    }

    private function checkPublicOrigin(): array
    {
        $base = $this->config->instanceUrl;
        $host = parse_url($base, PHP_URL_HOST);
        $loopback = is_string($host) && in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true);

        [$status, $body] = $this->get($base . '/', ['Accept' => 'text/html,application/activity+json']);
        if ($status === null) {
            return $this->check('origin', 'external', 'fail', 'Public origin did not answer', $base, 'Check DNS, the reverse proxy and TLS for ' . $base . '.');
        }

        if ($loopback) {
            return $this->check('origin', 'external', 'warn', 'Origin answers on a loopback URL', 'HTTP ' . $status . ' from ' . $base, 'Peers cannot reach a loopback address; set the public https origin once deployed.');
        }

        return $this->check('origin', 'external', 'ok', 'Public origin answers', 'HTTP ' . $status . ' from ' . $base, null);
    }

    private function checkNodeInfo(): array
    {
        $base = $this->config->instanceUrl;

        [$status, $body] = $this->get($base . '/.well-known/nodeinfo', ['Accept' => 'application/json']);
        $href = null;
        if ($status === 200 && is_string($body)) {
            $data = json_decode($body, true);
            $links = is_array($data) && is_array($data['links'] ?? null) ? $data['links'] : [];
            foreach (['2.1', '2.0'] as $version) {
                foreach ($links as $link) {
                    $rel = is_array($link) ? ($link['rel'] ?? null) : null;
                    $candidate = is_array($link) ? ($link['href'] ?? null) : null;
                    if (is_string($rel) && str_ends_with($rel, '/' . $version)
                        && is_string($candidate) && str_starts_with($candidate, 'http')
                    ) {
                        $href = $candidate;
                        break 2;
                    }
                }
            }
        }

        // Fall back to the versioned path when the well-known index is absent.
        if ($href === null) {
            foreach (['/nodeinfo/2.1', '/nodeinfo/2.0'] as $path) {
                [$status, $body] = $this->get($base . $path, ['Accept' => 'application/json']);
                if ($status === 200) {
                    $href = $base . $path;
                    break;
                }
            }
        }

        if ($href === null) {
            return $this->check('nodeinfo', 'external', 'fail', 'NodeInfo is not served', $base, 'Federation peers discover the instance through NodeInfo; check the web server routes /.well-known/nodeinfo.');
        }

        [$status, $body] = $this->get($href, ['Accept' => 'application/json']);
        $software = null;
        if ($status === 200 && is_string($body)) {
            $data = json_decode($body, true);
            $name = is_array($data) && is_array($data['software'] ?? null) ? ($data['software']['name'] ?? null) : null;
            $software = is_string($name) ? $name : null;
        }
        if ($software === null) {
            return $this->check('nodeinfo', 'external', 'warn', 'NodeInfo answered without software metadata', $href, 'Peers use software.name for compatibility decisions; verify the nodeinfo response.');
        }

        return $this->check('nodeinfo', 'external', 'ok', 'NodeInfo published', $software, null);
    }

    private function checkWebFinger(): array
    {
        $base = $this->config->instanceUrl;
        $host = parse_url($base, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return $this->check('webfinger', 'external', 'fail', 'Instance URL has no host', $base, 'Fix ALOHASOCIAL_INSTANCE_URL.');
        }

        // A 404 cannot distinguish "route missing" from "account unknown",
        // so the check verifies a real account's JRD when one exists.
        try {
            $handle = $this->db->fetchValue(
                'SELECT handle FROM account WHERE disabled_at IS NULL ORDER BY created_at ASC, id ASC LIMIT 1',
            );
        } catch (DatabaseException) {
            $handle = null;
        }
        if (!is_string($handle) || $handle === '') {
            return $this->check('webfinger', 'external', 'skip', 'No account to verify against', 'Create the first account, then re-run the preflight.', null);
        }

        $resource = 'acct:' . $handle . '@' . $host;
        [$status, $body] = $this->get(
            $base . '/.well-known/webfinger?resource=' . rawurlencode($resource),
            ['Accept' => 'application/jrd+json, application/json'],
        );

        if ($status === null) {
            return $this->check('webfinger', 'external', 'fail', 'WebFinger endpoint did not answer', $base, 'Check that /.well-known/webfinger reaches the application through the proxy.');
        }
        if ($status === 200 && is_string($body)) {
            $data = json_decode($body, true);
            $subject = is_array($data) ? ($data['subject'] ?? null) : null;
            if (is_string($subject) && $subject !== '') {
                return $this->check('webfinger', 'external', 'ok', 'WebFinger resolves accounts', $subject, null);
            }
        }
        if ($status === 404) {
            return $this->check('webfinger', 'external', 'fail', 'WebFinger returned 404 for a known account', $resource, 'Federation peers resolve actors through WebFinger; check the route and account lookup.');
        }

        return $this->check('webfinger', 'external', 'warn', 'WebFinger returned an unexpected response', 'HTTP ' . $status, 'Check the application logs for the webfinger route.');
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{0: ?int, 1: ?string}
     */
    private function get(string $url, array $headers): array
    {
        try {
            $response = $this->http->requestBounded(
                'GET',
                $url,
                $headers,
                '',
                self::FETCH_TIMEOUT,
                self::MAX_BYTES,
            );
        } catch (Throwable) {
            return [null, null];
        }

        return [$response->status, $response->body];
    }
}
