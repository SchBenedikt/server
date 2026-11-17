<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Support;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;
use Psr\Log\AbstractLogger;
use Stringable;
use Throwable;

use function bin2hex;
use function error_log;
use function in_array;
use function is_array;
use function is_scalar;
use function is_string;
use function json_encode;
use function mb_substr;
use function random_bytes;
use function str_replace;
use function strtolower;
use function strlen;

/**
 * PSR-3 logging that stays useful without shell access.
 *
 * Every warning or failure is still written to the SAPI error log. A bounded
 * copy also lands in `runtime_error`, where the admin can see delivery and
 * federation failures instead of having to grep a server log. Context is
 * reduced to scalar operational fields so request bodies and secrets cannot
 * leak into the administration.
 */
final class RuntimeLogger extends AbstractLogger
{
    private const STORED_LEVELS = ['warning', 'error', 'critical', 'alert', 'emergency'];

    private const CONTEXT_KEYS = [
        'id',
        'job_id',
        'type',
        'code',
        'reason',
        'domain',
        'url',
        'status',
        'attempt',
        'terminal',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $name = is_string($level) ? strtolower($level) : 'info';
        $text = $this->interpolate((string) $message, $context);

        error_log('[' . $name . '] ' . $text);

        if (!in_array($name, self::STORED_LEVELS, true)) {
            return;
        }

        try {
            $this->db->execute(
                'INSERT INTO runtime_error (id, level, source, message, context, created_at)'
                . ' VALUES (:id, :level, :source, :message, :context, :created_at)',
                [
                    'id' => bin2hex(random_bytes(16)),
                    'level' => $name,
                    'source' => $this->source($context),
                    'message' => mb_substr($text, 0, 1000),
                    'context' => $this->safeContext($context),
                    'created_at' => Time::format($this->clock->now()),
                ],
            );
        } catch (Throwable $exception) {
            // Logging must never turn a recoverable failure into a second one.
            error_log('runtime_error could not be stored: ' . $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function interpolate(string $text, array $context): string
    {
        foreach ($context as $key => $value) {
            if (!is_scalar($value) && !$value instanceof Stringable) {
                continue;
            }

            $text = str_replace('{' . $key . '}', (string) $value, $text);
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function source(array $context): ?string
    {
        foreach (['type', 'channel', 'domain', 'code', 'url'] as $key) {
            $value = $context[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return mb_substr(trim((string) $value), 0, 120);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function safeContext(array $context): ?string
    {
        $safe = [];
        foreach (self::CONTEXT_KEYS as $key) {
            $value = $context[$key] ?? null;
            if (is_scalar($value)) {
                $safe[$key] = is_string($value) ? mb_substr($value, 0, 500) : $value;
            }
        }

        if ($safe === []) {
            return null;
        }

        $encoded = json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) && strlen($encoded) <= 4000 ? $encoded : null;
    }
}
