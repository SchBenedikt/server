<?php

declare(strict_types=1);

/** SPDX-License-Identifier: AGPL-3.0-or-later */

namespace AlohaSocial\Server\Http;

use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Server\Auth\AuthenticatedSession;
use AlohaSocial\Server\Bootstrap\Runtime;
use AlohaSocial\Server\Federation\BlueskyException;
use Throwable;

final class AtprotoOAuthController
{
    public function __construct(private readonly Runtime $runtime) {}

    public function handle(Request $request, ?AuthenticatedSession $session): ?Response
    {
        if ($request->method === 'GET' && $request->path === '/oauth/atproto/client-metadata.json') {
            return Response::json($this->runtime->atprotoOAuth->clientMetadata(), 200, ['Cache-Control' => 'public, max-age=300']);
        }
        if ($request->method === 'POST' && $request->path === '/api/v1/host/bluesky/oauth') {
            $userId = $session?->userId();
            if ($userId === null) return Response::json(['error' => ['code' => 'not_authenticated', 'message' => 'Sign in first.']], 401);
            $body = json_decode($request->body, true);
            $handle = is_array($body) && is_string($body['handle'] ?? null) ? $body['handle'] : '';
            try {
                $url = $this->runtime->atprotoOAuth->begin($userId, $session->session->idHash, $handle);
                return Response::json(['authorization_url' => $url]);
            } catch (BlueskyException $error) {
                return Response::json(['error' => ['code' => $error->errorCode, 'message' => $error->getMessage()]], $error->status);
            } catch (Throwable) {
                return Response::json(['error' => ['code' => 'bluesky_oauth_unavailable', 'message' => 'Bluesky authorization could not be started.']], 502);
            }
        }
        if ($request->method === 'GET' && $request->path === '/oauth/atproto/callback') {
            $userId = $session?->userId();
            if ($userId === null) return new Response(303, ['Location' => '/login?next=%2Fsettings', 'Cache-Control' => 'no-store']);
            $denial = $request->queryParam('error');
            try {
                if ($denial !== null) throw new BlueskyException(400, 'bluesky_oauth_denied', 'Bluesky authorization was cancelled.');
                $this->runtime->atprotoOAuth->complete(
                    $userId,
                    $session->session->idHash,
                    (string) $request->queryParam('state', ''),
                    (string) $request->queryParam('code', ''),
                    (string) $request->queryParam('iss', ''),
                );
                return new Response(303, ['Location' => '/settings?atproto=connected', 'Cache-Control' => 'no-store']);
            } catch (Throwable) {
                return new Response(303, ['Location' => '/settings?atproto=error', 'Cache-Control' => 'no-store']);
            }
        }
        return null;
    }
}
