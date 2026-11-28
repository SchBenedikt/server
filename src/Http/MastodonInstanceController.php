<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Http;

use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Server\Bootstrap\Runtime;

use function array_map;
use function array_keys;
use function is_string;
use function parse_url;
use function preg_match;
use function preg_quote;
use function rawurldecode;
use function rtrim;
use function sort;
use function strtolower;

/**
 * Mastodon instance sub-resources the core route manifest does not cover.
 *
 * `/instance` itself is a core route; these are the public metadata
 * endpoints Mastodon clients read alongside it — peers, rules,
 * custom_emojis and announcements. They live under the configured
 * compatibility prefix and are answered without authentication, exactly
 * like the core instance endpoint. Where the product has no such concept
 * the answer is an empty list rather than an invented one.
 */
final class MastodonInstanceController
{
    /**
     * Peer discovery reads actor ids from the two federation relationships
     * that prove contact: cached remote posts and accepted follows. The
     * bound keeps the scan proportional to recent federation activity; a
     * peer that only ever delivered once and aged out of the window is
     * simply not listed.
     */
    private const PEER_ACTOR_LIMIT = 5000;

    public function __construct(
        private readonly Runtime $runtime,
    ) {
    }

    public function handle(Request $request): ?Response
    {
        if ($request->method !== 'GET' || !$this->runtime->config->mastodonApi) {
            return null;
        }

        $prefix = rtrim($this->runtime->config->mastodonPrefix, '/');
        $path = $request->path;

        if ($path === $prefix . '/instance/peers') {
            return Response::json($this->peers());
        }
        if ($path === $prefix . '/instance/rules') {
            return Response::json([]);
        }
        if ($path === $prefix . '/instance/custom_emojis') {
            return Response::json([]);
        }
        if ($path === $prefix . '/instance/announcements') {
            return Response::json(array_map(
                $this->announcement(...),
                $this->runtime->announcements->active(20),
            ));
        }
        if (preg_match('#^' . preg_quote($prefix, '#') . '/instance/announcements/([^/]+)$#', $path, $matches) === 1) {
            $announcement = $this->runtime->announcements->find(rawurldecode($matches[1]));
            if ($announcement === null || !$announcement['active'] || $announcement['expired']) {
                return Response::json(['error' => 'not_found'], 404);
            }

            return Response::json($this->announcement($announcement));
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function peers(): array
    {
        $actorIds = [];
        foreach ([
            'SELECT actor_id FROM social_remote_post WHERE deleted_at IS NULL ORDER BY published_at DESC LIMIT ' . self::PEER_ACTOR_LIMIT,
            "SELECT actor_id FROM social_following WHERE state = 'accepted' LIMIT " . self::PEER_ACTOR_LIMIT,
        ] as $sql) {
            foreach ($this->runtime->database->fetchAll($sql) as $row) {
                $actorIds[] = (string) ($row['actor_id'] ?? '');
            }
        }

        $domains = [];
        foreach ($actorIds as $actorId) {
            $host = parse_url($actorId, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $domains[strtolower($host)] = true;
            }
        }

        $peers = array_keys($domains);
        sort($peers);

        return $peers;
    }

    /**
     * @param array<string, mixed> $announcement
     *
     * @return array<string, mixed>
     */
    private function announcement(array $announcement): array
    {
        return [
            'id' => (string) $announcement['id'],
            'content' => (string) $announcement['content'],
            'published_at' => (string) $announcement['created_at'],
            'scheduled_at' => null,
            'status' => 'live',
            'level' => 'other',
            'reaction' => null,
        ];
    }
}
