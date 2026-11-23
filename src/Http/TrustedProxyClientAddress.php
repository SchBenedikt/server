<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Http;

/** Resolves a client address without trusting forwarding headers from clients. */
final class TrustedProxyClientAddress
{
    /** @param list<string> $trustedRanges */
    public static function resolve(?string $peer, ?string $forwardedFor, array $trustedRanges): ?string
    {
        $peer = self::normalizeAddress($peer);
        if ($peer === null || $forwardedFor === null || $forwardedFor === '' || strlen($forwardedFor) > 4096) {
            return $peer;
        }

        $chain = array_map(static fn (string $address): ?string => self::normalizeAddress(trim($address)), explode(',', $forwardedFor));
        if (count($chain) > 32 || in_array(null, $chain, true)) {
            return $peer;
        }

        $current = $peer;
        for ($index = count($chain) - 1; $index >= 0 && self::isTrusted($current, $trustedRanges); $index--) {
            $current = $chain[$index];
        }

        return $current;
    }

    public static function isValidRange(string $range): bool
    {
        $parts = explode('/', trim($range));
        if (count($parts) > 2 || ($packed = @inet_pton($parts[0])) === false) {
            return false;
        }

        if (!isset($parts[1])) {
            return true;
        }

        $max = strlen($packed) * 8;
        return ctype_digit($parts[1]) && (int) $parts[1] <= $max;
    }

    private static function normalizeAddress(?string $address): ?string
    {
        if ($address === null) {
            return null;
        }

        $address = trim($address);
        if (str_starts_with($address, '[') && str_ends_with($address, ']')) {
            $address = substr($address, 1, -1);
        }
        if (str_starts_with($address, '::ffff:')) {
            $mapped = substr($address, 7);
            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $address = $mapped;
            }
        }

        return filter_var($address, FILTER_VALIDATE_IP) === false ? null : $address;
    }

    /** @param list<string> $trustedRanges */
    private static function isTrusted(string $address, array $trustedRanges): bool
    {
        $packedAddress = inet_pton($address);
        if ($packedAddress === false) {
            return false;
        }

        foreach ($trustedRanges as $range) {
            [$network, $prefix] = array_pad(explode('/', $range, 2), 2, null);
            $packedNetwork = inet_pton($network);
            if ($packedNetwork === false || strlen($packedAddress) !== strlen($packedNetwork)) {
                continue;
            }

            $bits = $prefix === null ? strlen($packedNetwork) * 8 : (int) $prefix;
            $bytes = intdiv($bits, 8);
            $remainingBits = $bits % 8;
            if (substr($packedAddress, 0, $bytes) !== substr($packedNetwork, 0, $bytes)) {
                continue;
            }
            if ($remainingBits === 0) {
                return true;
            }

            $mask = (0xff << (8 - $remainingBits)) & 0xff;
            if ((ord($packedAddress[$bytes]) & $mask) === (ord($packedNetwork[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
