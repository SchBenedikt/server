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

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$debug = false;

try {
    $config = HostConfig::load($root);
    $debug = $config->debug;

    $runtime = (new ApplicationFactory($config, $root))->boot();

    $request = GlobalsRequestFactory::create();
    $response = (new FrontController($runtime))->handle(
        $request,
        $_COOKIE,
        isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null,
        isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null,
    );
} catch (Throwable $exception) {
    error_log('alohasocial/server boot failed: ' . $exception->getMessage());

    $response = Response::json(
        [
            'error' => [
                'code' => 'server_not_ready',
                'message' => $debug ? $exception->getMessage() : 'The server is not ready.',
            ],
        ],
        500,
    );
}

$response->send();
