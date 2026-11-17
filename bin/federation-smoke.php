<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Two real servers, one signed federation round trip.
 *
 * The smoke starts two `php -S` instances with separate databases, keys and
 * blob stores, then lets bob (B) follow alice (A) over HTTP. A verifies the
 * signed Follow, stores the follower edge and queues an Accept; alice then
 * posts and A's worker delivers the Create to B, where it is stored as a
 * remote post. Nothing is mocked: both signatures, actor documents, inboxes
 * and the queue are the production code path.
 *
 * Usage: php bin/federation-smoke.php [--keep]
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this from the command line.\n");
    exit(2);
}

$keep = in_array('--keep', array_slice($argv, 1), true);
$work = $root . '/var/federation-smoke-' . bin2hex(random_bytes(4));
if (!mkdir($work, 0o770, true) && !is_dir($work)) {
    fwrite(STDERR, 'Cannot create the smoke directory: ' . $work . "\n");
    exit(2);
}

$failures = 0;
$servers = [];

$cleanup = static function () use (&$servers, $work, $keep): void {
    foreach ($servers as $server) {
        stopServer($server);
    }

    if ($keep) {
        fwrite(STDOUT, 'Kept for inspection: ' . $work . "\n");

        return;
    }

    removeDirectory($work);
};
register_shutdown_function($cleanup);

/** @return array{0: int, 1: string, 2: string} status, body, error */
function httpRequest(string $method, string $url, array $options = []): array
{
    $handle = curl_init($url);
    if ($handle === false) {
        return [0, '', 'curl_init failed'];
    }

    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $options['headers'] ?? [],
    ]);

    if (isset($options['jar'])) {
        curl_setopt($handle, CURLOPT_COOKIEJAR, $options['jar']);
        curl_setopt($handle, CURLOPT_COOKIEFILE, $options['jar']);
    }

    if (array_key_exists('body', $options)) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $options['body']);
    }

    $body = curl_exec($handle);
    if ($body === false) {
        $error = curl_error($handle);
        curl_close($handle);

        return [0, '', $error];
    }

    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    return [$status, (string) $body, ''];
}

function waitForHealth(string $base): bool
{
    for ($attempt = 0; $attempt < 80; $attempt++) {
        [$status] = httpRequest('GET', $base . '/healthz');
        if ($status === 200) {
            return true;
        }

        usleep(250_000);
    }

    return false;
}

/** @return array{0: string, 1: string} csrf, home page */
function signIn(string $base, string $jar, string $handle, string $password): array
{
    [$status, $html] = httpRequest('GET', $base . '/login', ['jar' => $jar]);
    if ($status !== 200 || preg_match('/name="_csrf" value="([^"]+)"/', $html, $match) !== 1) {
        throw new RuntimeException('Login form missing for ' . $handle . ' (status ' . $status . ').');
    }

    $loginCsrf = html_entity_decode($match[1], ENT_QUOTES);

    [$loginStatus, $loginBody] = httpRequest('POST', $base . '/login', [
        'jar' => $jar,
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
        'body' => http_build_query([
            '_csrf' => $loginCsrf,
            'handle' => $handle,
            'password' => $password,
        ]),
    ]);

    if ($loginStatus !== 303) {
        throw new RuntimeException('Login for ' . $handle . ' failed with ' . $loginStatus . ': ' . $loginBody);
    }

    [$homeStatus, $home] = httpRequest('GET', $base . '/', ['jar' => $jar]);
    if ($homeStatus !== 200 || preg_match('/name="_csrf" value="([^"]+)"/', $home, $match) !== 1) {
        throw new RuntimeException('Signed-in home missing for ' . $handle . ' (status ' . $homeStatus . ').');
    }

    return [html_entity_decode($match[1], ENT_QUOTES), $home];
}

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;

    if (!$ok) {
        $failures++;
    }

    fwrite(STDOUT, ($ok ? 'PASS' : 'FAIL') . ' ' . $label . ($ok || $detail === '' ? '' : ' — ' . $detail) . "\n");
}

function freePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($socket === false) {
        throw new RuntimeException('Cannot reserve a port: ' . $errorMessage);
    }

    $name = stream_socket_get_name($socket, false);
    fclose($socket);

    if (!is_string($name) || !str_contains($name, ':')) {
        throw new RuntimeException('Cannot read the reserved port.');
    }

    return (int) substr($name, strrpos($name, ':') + 1);
}

/** @return array<string, string> */
function instanceEnvironment(string $directory, int $port): array
{
    return [
        'ALOHASOCIAL_INSTANCE_URL' => 'http://127.0.0.1:' . $port,
        'ALOHASOCIAL_INSTANCE_NAME' => 'Federation smoke',
        'ALOHASOCIAL_DB_DSN' => 'sqlite:' . $directory . '/server.sqlite',
        'ALOHASOCIAL_VAR_DIRECTORY' => $directory,
        'ALOHASOCIAL_BLOB_DIRECTORY' => $directory . '/blobs',
        'ALOHASOCIAL_SECRET_KEY_FILE' => $directory . '/secret.key',
        'ALOHASOCIAL_MAIL_TRANSPORT' => 'log',
        'ALOHASOCIAL_MAIL_FROM' => 'no-reply@example.test',
        'ALOHASOCIAL_MAIL_LOG_DIR' => $directory . '/mail',
        'ALOHASOCIAL_DEBUG' => 'false',
        // The built-in server is single-threaded by default; a signed Follow
        // makes A fetch B's actor document while B waits for A's inbox answer.
        'PHP_CLI_SERVER_WORKERS' => '4',
    ];
}

function runPhp(string $root, array $arguments, array $environment, ?string &$output = null): int
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        array_merge([PHP_BINARY], $arguments),
        $descriptors,
        $pipes,
        $root,
        array_merge((array) getenv(), $environment),
    );

    if (!is_resource($process)) {
        $output = 'proc_open failed';

        return 1;
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $output = trim((string) $stdout . "\n" . (string) $stderr);

    return proc_close($process);
}

/** @return array{process: resource, port: int, log: string} */
function startServer(string $root, string $directory, int $port, array $environment): array
{
    $log = $directory . '/server.log';
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', $log, 'a'],
        2 => ['file', $log, 'a'],
    ];

    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', 'public', 'public/index.php'],
        $descriptors,
        $pipes,
        $root,
        array_merge((array) getenv(), $environment),
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start the server on port ' . $port . '.');
    }

    return ['process' => $process, 'port' => $port, 'log' => $log];
}

/** @param array{process: resource, port: int, log: string} $server */
function stopServer(array $server): void
{
    if (!is_resource($server['process'])) {
        return;
    }

    proc_terminate($server['process']);
    $deadline = microtime(true) + 5.0;
    $status = proc_get_status($server['process']);

    while ($status['running'] && microtime(true) < $deadline) {
        usleep(100_000);
        $status = proc_get_status($server['process']);
    }

    if ($status['running']) {
        proc_terminate($server['process'], 9);
    }

    proc_close($server['process']);
}

function removeDirectory(string $directory): void
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
        if (is_dir($path) && !is_link($path)) {
            removeDirectory($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($directory);
}

fwrite(STDOUT, "Federation smoke — two instances, one signed round trip\n");
fwrite(STDOUT, 'Workspace: ' . $work . "\n");

$portA = freePort();
$portB = freePort();
$dirA = $work . '/a';
$dirB = $work . '/b';

foreach ([$dirA, $dirB] as $directory) {
    if (!mkdir($directory, 0o770, true) && !is_dir($directory)) {
        fwrite(STDERR, 'Cannot create ' . $directory . "\n");
        exit(2);
    }
}

$envA = instanceEnvironment($dirA, $portA);
$envB = instanceEnvironment($dirB, $portB);
$baseA = 'http://127.0.0.1:' . $portA;
$baseB = 'http://127.0.0.1:' . $portB;
$password = 'federation-smoke-password';

$code = runPhp($root, ['bin/migrate.php'], $envA, $output);
check('A: migrations applied', $code === 0, (string) $output);

$code = runPhp($root, ['bin/migrate.php'], $envB, $output);
check('B: migrations applied', $code === 0, (string) $output);

$code = runPhp($root, ['bin/create-account.php', 'alice', 'alice@a.example.test', '--password=' . $password], $envA, $output);
check('A: account alice created', $code === 0, (string) $output);

$code = runPhp($root, ['bin/create-account.php', 'bob', 'bob@b.example.test', '--password=' . $password], $envB, $output);
check('B: account bob created', $code === 0, (string) $output);

$servers[] = startServer($root, $dirA, $portA, $envA);
$servers[] = startServer($root, $dirB, $portB, $envB);

check('A: health endpoint answers', waitForHealth($baseA));
check('B: health endpoint answers', waitForHealth($baseB));

$jarA = $work . '/a.cookies';
$jarB = $work . '/b.cookies';

try {
    [$csrfA] = signIn($baseA, $jarA, 'alice', $password);
    [$csrfB] = signIn($baseB, $jarB, 'bob', $password);
    check('A: alice signed in', $csrfA !== '');
    check('B: bob signed in', $csrfB !== '');
} catch (Throwable $exception) {
    check('Both accounts can sign in', false, $exception->getMessage());
    exit(1);
}

$aliceActor = $baseA . '/@alice';
$aliceInbox = $baseA . '/inbox';
$bobActor = $baseB . '/@bob';

// B follows A: the core resolves A's actor, signs a Follow and delivers it
// synchronously; A verifies the signature and stores the follower edge.
[$status, $body] = httpRequest('POST', $baseB . '/api/v1/following', [
    'jar' => $jarB,
    'headers' => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrfB],
    'body' => json_encode(['actor' => $aliceActor, 'inbox' => $aliceInbox], JSON_THROW_ON_ERROR),
]);
check('B: signed Follow queued', $status === 201, 'status ' . $status . ' ' . $body);

// The Follow is intentionally asynchronous: B stores a pending edge and the
// worker sends the signed activity. Without this tick the follower edge never
// exists and A has nobody to deliver a new post to.
$code = runPhp($root, ['bin/worker.php', '--once'], $envB, $output);
check('B: worker delivered the Follow', $code === 0, (string) $output);
fwrite(STDOUT, '     worker: ' . str_replace("\n", ' | ', (string) $output) . "\n");

[$status, $body] = httpRequest('GET', $baseA . '/api/v1/followers', ['jar' => $jarA]);
check('A: bob is recorded as follower', $status === 200 && str_contains($body, $bobActor), 'status ' . $status . ' ' . $body);

// A's worker handles the Accept queued by the inbound Follow before A posts.
$code = runPhp($root, ['bin/worker.php', '--once'], $envA, $output);
check('A: worker delivered the Accept', $code === 0, (string) $output);

// A posts. Fan-out is queued; the worker delivers the Create to bob's inbox,
// where B verifies the signature and stores it as a remote post.
$content = 'Federation smoke post ' . bin2hex(random_bytes(4));
[$status, $body] = httpRequest('POST', $baseA . '/api/v1/posts', [
    'jar' => $jarA,
    'headers' => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrfA],
    'body' => json_encode(['content' => $content, 'visibility' => 'public'], JSON_THROW_ON_ERROR),
]);
check('A: post created', $status === 201, 'status ' . $status . ' ' . $body);

$code = runPhp($root, ['bin/worker.php', '--once'], $envA, $output);
check('A: worker drained the delivery queue', $code === 0, (string) $output);
fwrite(STDOUT, '     worker: ' . str_replace("\n", ' | ', (string) $output) . "\n");

[$status, $body] = httpRequest('GET', $baseB . '/api/v1/timelines/home', ['jar' => $jarB]);
check('B: delivered post appears in bob\'s home timeline', $status === 200 && str_contains($body, $content), 'status ' . $status . ' ' . $body);

[$status, $body] = httpRequest('GET', $baseB . '/api/v1/timelines/public');
check('B: public timeline still answers', $status === 200, 'status ' . $status);

fwrite(STDOUT, $failures === 0 ? "FEDERATION SMOKE PASS\n" : 'FEDERATION SMOKE FAIL (' . $failures . ")\n");

exit($failures === 0 ? 0 : 1);
