<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use AlohaSocial\Server\Bootstrap\ApplicationFactory;
use AlohaSocial\Server\Config\HostConfig;
use AlohaSocial\Server\Http\BootstrapFailureResponse;
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

try {
    $config = HostConfig::load($root);

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

    $response = BootstrapFailureResponse::create($wantsJson);
}

$response->send();
