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
use function is_string;
use function mb_substr;
use function random_bytes;
use function strtolower;

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
        $safeContext = LogRedactor::context($context);
        $text = LogRedactor::message((string) $message, $context);

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
                    'source' => LogRedactor::source($safeContext),
                    'message' => mb_substr($text, 0, 1000),
                    'context' => LogRedactor::encodeContext($safeContext),
                    'created_at' => Time::format($this->clock->now()),
                ],
            );
        } catch (Throwable) {
            // The database error may contain DSNs or credentials. Logging must
            // never turn a recoverable failure into a second, leaky one.
            error_log('runtime_error could not be stored');
        }
    }
}
