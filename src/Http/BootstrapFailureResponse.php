<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Http;

use AlohaSocial\Core\Http\Response;

/** Keeps configuration and infrastructure details in server-side logs only. */
final class BootstrapFailureResponse
{
    public static function create(bool $wantsJson): Response
    {
        if ($wantsJson) {
            return Response::json([
                'error' => [
                    'code' => 'server_not_ready',
                    'message' => 'The server is not ready.',
                ],
            ], 503, ['Retry-After' => '30', 'Cache-Control' => 'no-store']);
        }

        $body = <<<'HTML'
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
    </style>
</head>
<body>
<main>
    <h1>Server is not ready</h1>
    <p>This instance is temporarily unavailable.</p>
    <p>The operator can run <code>php bin/doctor.php</code> and <code>php bin/update.php</code> on the server.</p>
</main>
</body>
</html>
HTML;

        return new Response(503, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
            'Retry-After' => '30',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ], $body);
    }
}
