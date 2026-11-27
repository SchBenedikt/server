<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Support;

use Stringable;

use function in_array;
use function is_array;
use function is_bool;
use function is_scalar;
use function is_string;
use function json_encode;
use function mb_scrub;
use function mb_strlen;
use function mb_substr;
use function parse_url;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function str_contains;
use function strlen;
use function strtolower;
use function strtr;
use function trim;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PHP_URL_HOST;

/**
 * One privacy boundary for every runtime log sink.
 *
 * Call messages are templates. Only allowlisted context fields are expanded;
 * unknown values become a stable redaction marker instead of being copied into
 * the SAPI log or `runtime_error`. Both the expanded message and every stored
 * context value are then scrubbed for credentials, URL userinfo/query/fragment,
 * bearer tokens, cookies and key/value secrets.
 */
final class LogRedactor
{
    public const REDACTED = '[redacted]';

    public const MAX_MESSAGE = 2000;

    public const MAX_CONTEXT = 4000;

    private const SAFE_CONTEXT_KEYS = [
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

    private const SENSITIVE_KEYS = 'password|passwd|passphrase|secret|client_secret|token|access_token'
        . '|refresh_token|id_token|api_key|apikey|private_key|authorization|cookie|set-cookie|session'
        . '|csrf|credential|otp|recovery_code';

    private const URL_PATTERN = '~https?://[^\s<>"\']+~i';

    /**
     * @param array<string, mixed> $context
     */
    public static function message(string $template, array $context): string
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            $placeholder = '{' . $key . '}';
            if (!is_scalar($value) && !$value instanceof Stringable) {
                $replacements[$placeholder] = self::REDACTED;
                continue;
            }

            $replacements[$placeholder] = self::value((string) $key, $value);
        }

        $text = strtr(self::utf8($template), $replacements);
        $text = preg_replace('/\{[A-Za-z0-9_.:-]+\}/i', self::REDACTED, $text) ?? $text;

        return self::text($text, self::MAX_MESSAGE);
    }

    public static function text(string $value, int $limit = self::MAX_MESSAGE): string
    {
        $value = self::utf8($value);

        $value = preg_replace_callback(
            self::URL_PATTERN,
            static fn (array $match): string => self::url($match[0]),
            $value,
        ) ?? $value;

        $value = preg_replace(
            '/(authorization|cookie|set-cookie|x-csrf-token|x-api-key)\s*[:=]\s*[^\r\n,;]+/i',
            '$1: ' . self::REDACTED,
            $value,
        ) ?? $value;

        $value = preg_replace(
            '/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i',
            'Bearer ' . self::REDACTED,
            $value,
        ) ?? $value;

        $value = preg_replace(
            '/(?<![A-Za-z0-9])(["\']?(?:' . self::SENSITIVE_KEYS . ')["\']?\s*[:=]\s*)'
            . '(["\']?[^"\'\s,;&}]+["\']?)/i',
            '$1' . self::REDACTED,
            $value,
        ) ?? $value;

        $value = preg_replace(
            '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/',
            self::REDACTED,
            $value,
        ) ?? $value;

        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim(mb_substr($value, 0, $limit));
    }

    /**
     * Keep only scheme, host, port and path. Credentials, query and fragment
     * never leave the process, including when a URL is part of a free message.
     */
    public static function url(string $url, int $limit = 500): string
    {
        $url = self::utf8(trim($url));
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return self::REDACTED;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return self::REDACTED;
        }

        $host = strtolower((string) $parts['host']);
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '';

        return mb_substr($scheme . '://' . $host . $port . $path, 0, $limit);
    }

    public static function domain(string $value): string
    {
        $value = trim(self::utf8($value));
        if (str_contains($value, '://')) {
            $host = parse_url($value, PHP_URL_HOST);
            if (!is_string($host)) {
                return self::REDACTED;
            }
            $value = $host;
        }

        $value = strtolower(rtrim($value, '.'));

        return preg_match('/^[a-z0-9.-]+$/', $value) === 1 || preg_match('/^\[[0-9a-f:]+\]$/', $value) === 1
            ? mb_substr($value, 0, 120)
            : self::REDACTED;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, scalar>
     */
    public static function context(array $context): array
    {
        $safe = [];
        foreach (self::SAFE_CONTEXT_KEYS as $key) {
            if (!array_key_exists($key, $context)) {
                continue;
            }

            $value = $context[$key];
            if (!is_scalar($value)) {
                continue;
            }

            $safe[$key] = $key === 'url'
                ? self::url((string) $value)
                : ($key === 'domain'
                    ? self::domain((string) $value)
                    : self::text(self::scalarString($value), 500));
        }

        return $safe;
    }

    /**
     * Sanitize a decoded row from an older runtime_error record before it is
     * returned by an admin export or diagnostics view.
     *
     * @return array<string, scalar>|null
     */
    public static function contextArray(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $safe = self::context($value);

        return $safe === [] ? null : $safe;
    }

    /**
     * @param array<string, scalar> $context
     */
    public static function source(array $context): ?string
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
     * @param array<string, scalar> $context
     */
    public static function encodeContext(array $context): ?string
    {
        if ($context === []) {
            return null;
        }

        $encoded = json_encode(
            $context,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return is_string($encoded) && strlen($encoded) <= self::MAX_CONTEXT ? $encoded : null;
    }

    private static function value(string $key, mixed $value): string
    {
        if (!in_array($key, self::SAFE_CONTEXT_KEYS, true)) {
            return self::REDACTED;
        }

        if ($key === 'url') {
            return self::url((string) $value);
        }
        if ($key === 'domain') {
            return self::domain((string) $value);
        }

        return self::text(self::scalarString($value), 500);
    }

    private static function scalarString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    private static function utf8(string $value): string
    {
        return mb_scrub($value, 'UTF-8');
    }
}
