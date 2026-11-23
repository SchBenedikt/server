<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use AlohaSocial\Core\Http\Response;
use AlohaSocial\Server\Bootstrap\ApplicationFactory;
use AlohaSocial\Server\Config\HostConfig;
use AlohaSocial\Server\Http\FrontController;
use AlohaSocial\Server\Http\GlobalsRequestFactory;
use AlohaSocial\Server\Http\TrustedProxyClientAddress;

$root = dirname(__DIR__);

// With `php -S ... public/index.php` the built-in server calls this router for
// every request, including files under public/. Returning false lets it serve
// the file itself; a production web server serves public/ directly.
if (PHP_SAPI === 'cli-server') {
    $requested = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $file = realpath(__DIR__ . (is_string($requested) ? $requested : '/'));

    if ($file !== false && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR) && is_file($file)) {
        return false;
    }
}

require $root . '/vendor/autoload.php';

$debug = false;

try {
    $config = HostConfig::load($root);
    $debug = $config->debug;

    $runtime = (new ApplicationFactory($config, $root))->boot();

    $clientAddress = TrustedProxyClientAddress::resolve(
        isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null,
        isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? (string) $_SERVER['HTTP_X_FORWARDED_FOR'] : null,
        $config->trustedProxies,
    );
    $request = GlobalsRequestFactory::create($clientAddress);
    $response = (new FrontController($runtime))->handle(
        $request,
        $_COOKIE,
        isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null,
        $clientAddress,
    );
} catch (Throwable $exception) {
    error_log('alohasocial/server boot failed: ' . $exception->getMessage());

    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $wantsJson = str_starts_with($path, '/api/')
        || str_contains($accept, 'application/json')
        || str_contains($accept, 'application/activity+json');

    if (!$wantsJson) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('Retry-After: 30');

        // A configuration or migration failure is actionable and contains no
        // credentials. Show it even without debug so the operator does not
        // have to guess why the instance refuses to boot.
        $configFailure = $exception instanceof RuntimeException && !$exception instanceof PDOException;
        $detail = $debug || $configFailure ? $exception->getMessage() : null;
        $message = 'This instance is not ready yet.';
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Not ready · Aloha Social</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #fbf4ea; color: #1c2f37; font: 16px/1.6 system-ui, sans-serif; }
        main { width: min(34rem, 100% - 2rem); padding: 2rem; background: #fff; border: 1px solid #e9dfd1; border-radius: 1rem; }
        h1 { margin: 0 0 .5rem; color: #0f4c5c; font-size: 1.6rem; }
        p { margin: .6rem 0; }
        code { font-family: ui-monospace, monospace; background: #f7efe3; padding: .1rem .3rem; border-radius: .3rem; }
        .detail { margin-top: 1rem; padding: .8rem; background: #f8e3df; border-radius: .6rem; overflow-wrap: anywhere; }
    </style>
</head>
<body>
<main>
    <h1>Server is not ready</h1>
    <p><?= htmlspecialchars($message, ENT_QUOTES) ?></p>
    <?php if ($detail !== null && $detail !== ''): ?>
        <p class="detail"><strong>Reason:</strong> <?= htmlspecialchars($detail, ENT_QUOTES) ?></p>
    <?php endif; ?>
    <p>The operator can run <code>php bin/doctor.php</code> and <code>php bin/update.php</code> on the server.</p>
</main>
</body>
</html>
        <?php
        exit;
    }

    $response = Response::json(
        [
            'error' => [
                'code' => 'server_not_ready',
                'message' => $debug ? $exception->getMessage() : 'The server is not ready.',
            ],
        ],
        503,
    );
}

$response->send();
