<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * One real server, the essential signed-in user journeys.
 *
 * The smoke migrates a fresh instance, creates two accounts, starts the
 * production front controller and then exercises the journeys a release gate
 * must prove: sign-in, profile edit, local follow, media upload, post,
 * timeline, like, reply, boost, edit, notifications, discovery, preferences,
 * delete and sign-out. Cross-instance federation is covered separately by
 * bin/federation-smoke.php.
 *
 * Usage: php bin/user-journey-smoke.php [--keep]
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this from the command line.\n");
    exit(2);
}

$keep = in_array('--keep', array_slice($argv, 1), true);
$work = $root . '/var/user-journey-smoke-' . bin2hex(random_bytes(4));
if (!mkdir($work, 0o770, true) && !is_dir($work)) {
    fwrite(STDERR, 'Cannot create the smoke directory: ' . $work . "\n");
    exit(2);
}

$failures = 0;
$server = null;
$serverLog = $work . '/server.log';

$cleanup = static function () use (&$server, $work, $keep, $serverLog): void {
    if (is_array($server)) {
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

function jsonRequest(string $method, string $url, string $jar, string $csrf, ?array $payload = null): array
{
    $options = [
        'jar' => $jar,
        'headers' => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf],
    ];
    if ($payload !== null) {
        $options['body'] = json_encode($payload, JSON_THROW_ON_ERROR);
    }

    return httpRequest($method, $url, $options);
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

function signIn(string $base, string $jar, string $handle, string $password): string
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

    return html_entity_decode($match[1], ENT_QUOTES);
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
        'ALOHASOCIAL_INSTANCE_NAME' => 'User journey smoke',
        'ALOHASOCIAL_DB_DSN' => 'sqlite:' . $directory . '/server.sqlite',
        'ALOHASOCIAL_VAR_DIRECTORY' => $directory,
        'ALOHASOCIAL_BLOB_DIRECTORY' => $directory . '/blobs',
        'ALOHASOCIAL_SECRET_KEY_FILE' => $directory . '/secret.key',
        'ALOHASOCIAL_MAIL_TRANSPORT' => 'log',
        'ALOHASOCIAL_MAIL_FROM' => 'no-reply@example.test',
        'ALOHASOCIAL_MAIL_LOG_DIR' => $directory . '/mail',
        'ALOHASOCIAL_DEBUG' => 'true',
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

function startServer(string $root, string $directory, int $port, array $environment, string $log): array
{
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

/** 1x1 transparent PNG. */
function tinyPng(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        true,
    ) ?: '';
}

fwrite(STDOUT, "User journey smoke — one instance, essential signed-in flows\n");
fwrite(STDOUT, 'Workspace: ' . $work . "\n");

$port = freePort();
$dir = $work . '/instance';
if (!mkdir($dir, 0o770, true) && !is_dir($dir)) {
    fwrite(STDERR, 'Cannot create ' . $dir . "\n");
    exit(2);
}

$env = instanceEnvironment($dir, $port);
$base = 'http://127.0.0.1:' . $port;
$password = 'user-journey-smoke-password';

$code = runPhp($root, ['bin/migrate.php'], $env, $output);
check('migrations applied', $code === 0, (string) $output);

$code = runPhp($root, ['bin/create-account.php', 'alice', 'alice@example.test', '--password=' . $password], $env, $output);
check('account alice created', $code === 0, (string) $output);

$code = runPhp($root, ['bin/create-account.php', 'bob', 'bob@example.test', '--password=' . $password], $env, $output);
check('account bob created', $code === 0, (string) $output);

$server = startServer($root, $dir, $port, $env, $serverLog);
check('health endpoint answers', waitForHealth($base));

$jarAlice = $work . '/alice.cookies';
$jarBob = $work . '/bob.cookies';
$csrfAlice = '';
$csrfBob = '';

try {
    $csrfAlice = signIn($base, $jarAlice, 'alice', $password);
    $csrfBob = signIn($base, $jarBob, 'bob', $password);
    check('alice signed in', $csrfAlice !== '');
    check('bob signed in', $csrfBob !== '');
} catch (Throwable $exception) {
    check('Both accounts can sign in', false, $exception->getMessage());
    exit(1);
}

// An unauthenticated API call must be refused before any journey runs.
[$status] = httpRequest('GET', $base . '/api/v1/timelines/home');
check('API refuses anonymous reads', $status === 401, 'status ' . $status);

// Profile edit.
[$status, $body] = jsonRequest('PATCH', $base . '/api/v1/host/profile', $jarAlice, $csrfAlice, [
    'display_name' => 'Alice Smoke',
]);
$profileOk = false;
if ($status === 200) {
    $decoded = json_decode($body, true);
    $profileOk = is_array($decoded) && ($decoded['account']['display_name'] ?? null) === 'Alice Smoke';
}
check('profile display name updated', $profileOk, 'status ' . $status . ' ' . $body);

// Discovery: local search finds the account (Mastodon-compatible endpoint).
[$status, $body] = httpRequest('GET', $base . '/api/mastodon/v1/search?q=alice', ['jar' => $jarBob]);
$aliceAccountId = null;
if ($status === 200) {
    foreach (json_decode($body, true)['accounts'] ?? [] as $account) {
        $acct = $account['acct'] ?? $account['username'] ?? null;
        if (is_string($acct) && str_starts_with($acct, 'alice')) {
            $aliceAccountId = $account['id'] ?? null;
            break;
        }
    }
}
check('search finds local account alice', is_string($aliceAccountId) && $aliceAccountId !== '', 'status ' . $status . ' ' . substr($body, 0, 300));

// Local follow: bob follows alice. The endpoint also resolves handles, so
// fall back to the bare handle when the search id is unusable.
$followTarget = is_string($aliceAccountId) && $aliceAccountId !== '' ? $aliceAccountId : 'alice';
[$status, $body] = jsonRequest('POST', $base . '/api/v1/accounts/' . rawurlencode($followTarget) . '/follow', $jarBob, $csrfBob);
$followed = false;
if ($status === 200 || $status === 201) {
    $decoded = json_decode($body, true);
    $followed = ($decoded['relationship']['following'] ?? null) === true
        || ($decoded['requested'] ?? null) === true;
}
check('bob follows alice', $followed, 'status ' . $status . ' ' . $body);

$code = runPhp($root, ['bin/worker.php', '--once'], $env, $output);
check('worker tick after follow', $code === 0, (string) $output);

// Media upload, then a post carrying the attachment.
$pngPath = $work . '/pixel.png';
file_put_contents($pngPath, tinyPng());
[$status, $body] = httpRequest('POST', $base . '/api/v1/media', [
    'jar' => $jarAlice,
    'headers' => ['X-CSRF-Token: ' . $csrfAlice],
    'body' => [
        'file' => new CURLFile($pngPath, 'image/png', 'pixel.png'),
        'alt_text' => 'A single pixel',
    ],
]);
$attachmentId = null;
if ($status === 200 || $status === 201) {
    $decoded = json_decode($body, true);
    $attachmentId = $decoded['attachment']['id'] ?? null;
}
check('media upload accepted', is_string($attachmentId) && $attachmentId !== '', 'status ' . $status . ' ' . $body);

$content = 'User journey post ' . bin2hex(random_bytes(4));
$postPayload = ['content' => $content, 'visibility' => 'public'];
if (is_string($attachmentId) && $attachmentId !== '') {
    $postPayload['attachment_ids'] = [$attachmentId];
}
[$status, $body] = jsonRequest('POST', $base . '/api/v1/posts', $jarAlice, $csrfAlice, $postPayload);
$postId = null;
$postObjectId = null;
if ($status === 200 || $status === 201) {
    $decoded = json_decode($body, true);
    $post = $decoded['post'] ?? [];
    $postId = is_array($post) ? ($post['id'] ?? null) : null;
    $postObjectId = is_array($post) ? ($post['object_id'] ?? null) : null;
}
check('alice creates a post', is_string($postId) && $postId !== '', 'status ' . $status . ' ' . $body);

$code = runPhp($root, ['bin/worker.php', '--once'], $env, $output);
check('worker tick after post', $code === 0, (string) $output);

// Bob's home timeline shows the followed author's post, with the media
// attachment and the canonical object id engagement endpoints need.
[$status, $body] = httpRequest('GET', $base . '/api/v1/timelines/home', ['jar' => $jarBob]);
$timelineItem = null;
if ($status === 200) {
    foreach (json_decode($body, true)['posts'] ?? [] as $item) {
        if (($item['content'] ?? null) === $content) {
            $timelineItem = $item;
            break;
        }
    }
}
check(
    "bob's home timeline shows alice's post",
    $timelineItem !== null,
    'status ' . $status,
);
if (is_array($timelineItem)) {
    $hasAttachment = count($timelineItem['attachments'] ?? []) > 0;
    check('timeline item carries the uploaded media', $hasAttachment, json_encode($timelineItem['attachments'] ?? null));
    if (is_string($timelineItem['object_id'] ?? null) && $timelineItem['object_id'] !== '') {
        $postObjectId = $timelineItem['object_id'];
    }
}

// Engagement: like, reply, boost from bob.
$engageId = is_string($postObjectId) && $postObjectId !== '' ? $postObjectId : $postId;
if (is_string($engageId) && $engageId !== '') {
    [$status] = jsonRequest('POST', $base . '/api/v1/posts/' . rawurlencode($engageId) . '/like', $jarBob, $csrfBob, []);
    check('bob likes the post', $status === 200 || $status === 201, 'status ' . $status);

    $replyContent = 'User journey reply ' . bin2hex(random_bytes(4));
    [$status, $body] = jsonRequest('POST', $base . '/api/v1/posts', $jarBob, $csrfBob, [
        'content' => $replyContent,
        'visibility' => 'public',
        'in_reply_to_id' => $postId,
    ]);
    check('bob replies to the post', $status === 200 || $status === 201, 'status ' . $status . ' ' . $body);

    [$status] = jsonRequest('POST', $base . '/api/v1/posts/' . rawurlencode($engageId) . '/boost', $jarBob, $csrfBob, []);
    check('bob boosts the post', $status === 200 || $status === 201, 'status ' . $status);
} else {
    check('bob likes the post', false, 'no post id');
    check('bob replies to the post', false, 'no post id');
    check('bob boosts the post', false, 'no post id');
}

$code = runPhp($root, ['bin/worker.php', '--once'], $env, $output);
check('worker tick after engagement', $code === 0, (string) $output);

// Alice sees the engagement as notifications.
[$status, $body] = httpRequest('GET', $base . '/api/v1/notifications', ['jar' => $jarAlice]);
$types = [];
if ($status === 200) {
    foreach (json_decode($body, true)['notifications'] ?? [] as $notification) {
        if (is_string($notification['type'] ?? null)) {
            $types[$notification['type']] = true;
        }
    }
}
check('alice notified of the like', isset($types['favourite']), 'status ' . $status . ' ' . implode(',', array_keys($types)));
check('alice notified of the reply', isset($types['mention']), 'status ' . $status);
check('alice notified of the boost', isset($types['reblog']), 'status ' . $status);

// Edit propagates to readers.
if (is_string($postId) && $postId !== '') {
    $edited = $content . ' (edited)';
    [$status, $body] = jsonRequest('PATCH', $base . '/api/v1/posts/' . rawurlencode($postId), $jarAlice, $csrfAlice, [
        'content' => $edited,
    ]);
    check('alice edits her post', $status === 200 || $status === 204, 'status ' . $status . ' ' . $body);

    $code = runPhp($root, ['bin/worker.php', '--once'], $env, $output);
    check('worker tick after edit', $code === 0, (string) $output);

    [$status, $body] = httpRequest('GET', $base . '/api/v1/timelines/home', ['jar' => $jarBob]);
    check("bob sees the edited content", $status === 200 && str_contains($body, $edited), 'status ' . $status);
}

// Discovery surfaces.
[$status] = httpRequest('GET', $base . '/api/v1/timelines/public', ['jar' => $jarBob]);
check('public timeline answers', $status === 200, 'status ' . $status);
[$status, $body] = httpRequest('GET', $base . '/api/mastodon/v1/search?q=' . rawurlencode($content), ['jar' => $jarBob]);
check('search finds the post', $status === 200 && str_contains($body, $content), 'status ' . $status);
[$status] = httpRequest('GET', $base . '/api/v1/notifications/unread_count', ['jar' => $jarAlice]);
check('unread notification count answers', $status === 200, 'status ' . $status);

// Preferences round-trip.
[$status, $body] = jsonRequest('PATCH', $base . '/api/v1/host/preferences', $jarBob, $csrfBob, [
    'default_visibility' => 'followers',
]);
$prefOk = false;
if ($status === 200) {
    $decoded = json_decode($body, true);
    $prefOk = ($decoded['preferences']['default_visibility'] ?? null) === 'followers';
}
check('preferences update round-trips', $prefOk, 'status ' . $status . ' ' . $body);

// Delete: the post disappears for the author and followers.
if (is_string($postId) && $postId !== '') {
    [$status] = jsonRequest('DELETE', $base . '/api/v1/posts/' . rawurlencode($postId), $jarAlice, $csrfAlice);
    check('alice deletes her post', $status === 200 || $status === 204, 'status ' . $status);

    $code = runPhp($root, ['bin/worker.php', '--once'], $env, $output);
    check('worker tick after delete', $code === 0, (string) $output);

    [$status, $body] = httpRequest('GET', $base . '/api/v1/timelines/home', ['jar' => $jarBob]);
    check("bob's timeline no longer shows the post", $status === 200 && !str_contains($body, $content) && !str_contains($body, $edited ?? ''), 'status ' . $status);
}

// Sign out: the session must stop working.
[$status, $home] = httpRequest('GET', $base . '/', ['jar' => $jarBob]);
if ($status === 200 && preg_match('/name="_csrf" value="([^"]+)"/', $home, $match) === 1) {
    $logoutCsrf = html_entity_decode($match[1], ENT_QUOTES);
    [$status] = httpRequest('POST', $base . '/logout', [
        'jar' => $jarBob,
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
        'body' => http_build_query(['_csrf' => $logoutCsrf]),
    ]);
    check('bob signs out', $status === 303, 'status ' . $status);

    [$status] = httpRequest('GET', $base . '/api/v1/timelines/home', ['jar' => $jarBob]);
    check('signed-out session loses API access', $status === 401, 'status ' . $status);
} else {
    check('bob signs out', false, 'could not read the CSRF token (status ' . $status . ')');
    check('signed-out session loses API access', false, 'skipped');
}

fwrite(STDOUT, $failures === 0 ? "USER JOURNEY SMOKE PASS\n" : 'USER JOURNEY SMOKE FAIL (' . $failures . ")\n");

exit($failures === 0 ? 0 : 1);
