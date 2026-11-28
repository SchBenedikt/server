<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Auth;

use function chr;
use function ctype_digit;
use function hash_equals;
use function hash_hmac;
use function intdiv;
use function ord;
use function preg_replace;
use function random_bytes;
use function rawurlencode;
use function str_pad;
use function str_split;
use function strlen;
use function strtoupper;
use function strtr;
use function substr;
use function time;

/**
 * RFC 6238 TOTP (HMAC-SHA1, 30-second step, 6 digits) over base32 secrets.
 *
 * Pure PHP on purpose: the primitive is small, has published test vectors and
 * must not grow a dependency both hosts would have to adopt.
 */
final class Totp
{
    private const PERIOD = 30;

    private const DIGITS = 6;

    private const SECRET_BYTES = 20;

    /**
     * A fresh base32 secret (no padding), suitable for otpauth URIs.
     */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(self::SECRET_BYTES));
    }

    public static function code(string $base32Secret, ?int $timestamp = null): string
    {
        $key = self::base32Decode($base32Secret);
        $counter = intdiv($timestamp ?? time(), self::PERIOD);

        $binary = '';
        for ($i = 7; $i >= 0; $i--) {
            $binary .= chr(($counter >> ($i * 8)) & 0xff);
        }

        $hash = hash_hmac('sha1', $binary, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = (
            ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff)
        ) % (10 ** self::DIGITS);

        return str_pad((string) $value, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Accepts the current code plus one step of clock drift on either side.
     */
    public static function verify(string $base32Secret, string $code, ?int $timestamp = null, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (strlen($code) !== self::DIGITS || !ctype_digit($code)) {
            return false;
        }

        $now = intdiv($timestamp ?? time(), self::PERIOD);
        for ($drift = -$window; $drift <= $window; $drift++) {
            if (hash_equals(self::code($base32Secret, ($now + $drift) * self::PERIOD), $code)) {
                return true;
            }
        }

        return false;
    }

    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer . ':' . $account);

        return 'otpauth://totp/' . $label
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer)
            . '&digits=' . self::DIGITS
            . '&period=' . self::PERIOD;
    }

    private static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $result = '';
        $buffer = 0;
        $bits = 0;
        $length = strlen($data);

        for ($i = 0; $i < $length; $i++) {
            $buffer = ($buffer << 8) | ord($data[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $result .= $alphabet[($buffer >> $bits) & 0x1f];
            }
        }
        if ($bits > 0) {
            $result .= $alphabet[($buffer << (5 - $bits)) & 0x1f];
        }

        return $result;
    }

    private static function base32Decode(string $encoded): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $lookup = [];
        for ($i = 0; $i < 32; $i++) {
            $lookup[$alphabet[$i]] = $i;
        }

        $clean = strtoupper(strtr($encoded, ['=' => '', ' ' => '', '-' => '']));
        $result = '';
        $buffer = 0;
        $bits = 0;

        foreach (str_split($clean) as $char) {
            if (!isset($lookup[$char])) {
                continue;
            }
            $buffer = ($buffer << 5) | $lookup[$char];
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $result .= chr(($buffer >> $bits) & 0xff);
            }
        }

        return $result;
    }
}
