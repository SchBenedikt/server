<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Support;

use RuntimeException;

use function chmod;
use function dirname;
use function fclose;
use function feof;
use function filesize;
use function fopen;
use function fread;
use function fwrite;
use function is_dir;
use function is_file;
use function is_resource;
use function mkdir;
use function random_bytes;
use function rename;
use function sodium_crypto_pwhash;
use function sodium_crypto_secretstream_xchacha20poly1305_init_pull;
use function sodium_crypto_secretstream_xchacha20poly1305_init_push;
use function sodium_crypto_secretstream_xchacha20poly1305_pull;
use function sodium_crypto_secretstream_xchacha20poly1305_push;
use function strlen;
use function substr;
use function tempnam;
use function unpack;
use function unlink;

use const SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13;
use const SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE;
use const SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE;
use const SODIUM_CRYPTO_PWHASH_SALTBYTES;
use const SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
use const SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
use const SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES;
use const SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
use const SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

/**
 * Encrypted single-file instance export.
 *
 * Layout: a small plaintext header (magic, version, Argon2id parameters,
 * salt), the secretstream header, then an authenticated stream of framed
 * entries — `[u16 path length][path][u64 size][contents]` — each pushed as
 * its own secretstream chunk so every byte is authenticated and memory use
 * stays constant. The passphrase never touches argv; callers read it from
 * the environment or a file.
 */
final class InstanceExport
{
    private const MAGIC = 'ALOEXPORT1';
    private const VERSION = 1;
    private const CHUNK = 1024 * 1024;

    /**
     * @param list<array{path: string, source: string}> $entries
     *        Bundle-relative path => absolute file to read.
     */
    public static function create(string $outputPath, string $passphrase, array $entries): void
    {
        if ($passphrase === '') {
            throw new RuntimeException('An export passphrase is required.');
        }
        if ($entries === []) {
            throw new RuntimeException('An export needs at least one file.');
        }

        $temporary = tempnam(dirname($outputPath), '.export-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot stage the export in ' . dirname($outputPath));
        }

        $out = null;
        try {
            $out = fopen($temporary, 'wb');
            if ($out === false) {
                throw new RuntimeException('Cannot open the export staging file: ' . $temporary);
            }

            $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
            $key = self::deriveKey($passphrase, $salt);
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            unset($key);

            fwrite($out, self::MAGIC);
            fwrite($out, chr(self::VERSION));
            fwrite($out, pack('N', SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE));
            fwrite($out, pack('N', SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE));
            fwrite($out, $salt);
            fwrite($out, $header);

            foreach ($entries as $entry) {
                $pathLength = strlen($entry['path']);
                self::push($state, $out, pack('n', $pathLength));
                self::push($state, $out, $entry['path']);
                $size = filesize($entry['source']);
                if ($size === false) {
                    throw new RuntimeException('Cannot stat export source: ' . $entry['source']);
                }
                self::push($state, $out, pack('J', $size));
                self::streamFile($state, $out, $entry['source']);
            }

            fwrite($out, sodium_crypto_secretstream_xchacha20poly1305_push(
                $state,
                '',
                '',
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL,
            ));

            fclose($out);
            $out = null;

            if (!rename($temporary, $outputPath)) {
                throw new RuntimeException('Cannot move the finished export into place: ' . $outputPath);
            }
            @chmod($outputPath, 0o600);
        } finally {
            if (is_resource($out)) {
                fclose($out);
            }
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * Decrypts an export into a directory, verifying every chunk's
     * authentication tag. Returns the written bundle-relative paths.
     *
     * @return list<string>
     */
    public static function open(string $inputPath, string $passphrase, string $targetDirectory): array
    {
        $in = fopen($inputPath, 'rb');
        if ($in === false) {
            throw new RuntimeException('Cannot open the export: ' . $inputPath);
        }

        try {
            $header = self::readExact($in, strlen(self::MAGIC) + 1 + 8 + SODIUM_CRYPTO_PWHASH_SALTBYTES);
            if (substr($header, 0, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new RuntimeException('Not an Aloha Social export file.');
            }
            $version = ord($header[strlen(self::MAGIC)]);
            if ($version !== self::VERSION) {
                throw new RuntimeException('Unsupported export version: ' . $version);
            }
            $offset = strlen(self::MAGIC) + 1;
            $opslimit = unpack('N', substr($header, $offset, 4))[1];
            $memlimit = unpack('N', substr($header, $offset + 4, 4))[1];
            $salt = substr($header, $offset + 8, SODIUM_CRYPTO_PWHASH_SALTBYTES);

            $streamHeader = self::readExact($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(
                $streamHeader,
                self::deriveKey($passphrase, $salt, $opslimit, $memlimit),
            );

            $written = [];
            while (true) {
                $frame = self::pullHeader($in, $state);
                if ($frame === null) {
                    break;
                }
                [$path, $size] = $frame;
                $target = $targetDirectory . '/' . $path;
                $dir = dirname($target);
                if (!is_dir($dir) && !mkdir($dir, 0o770, true) && !is_dir($dir)) {
                    throw new RuntimeException('Cannot create export directory: ' . $dir);
                }

                $out = fopen($target, 'wb');
                if ($out === false) {
                    throw new RuntimeException('Cannot write restored file: ' . $target);
                }
                try {
                    $remaining = $size;
                    while ($remaining > 0) {
                        $want = min(self::CHUNK, $remaining);
                        $chunk = self::pullChunk($in, $state, $want);
                        if ($chunk === null || strlen($chunk) !== $want) {
                            throw new RuntimeException('Truncated export payload for ' . $path);
                        }
                        fwrite($out, $chunk);
                        $remaining -= $want;
                    }
                } finally {
                    fclose($out);
                }
                @chmod($target, 0o600);
                $written[] = $path;
            }

            return $written;
        } finally {
            fclose($in);
        }
    }

    /** @param resource $state @param resource $out */
    private static function push(&$state, $out, string $data): void
    {
        fwrite($out, sodium_crypto_secretstream_xchacha20poly1305_push(
            $state,
            $data,
            '',
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
        ));
    }

    /** @param resource $state @param resource $out */
    private static function streamFile(&$state, $out, string $source): void
    {
        $in = fopen($source, 'rb');
        if ($in === false) {
            throw new RuntimeException('Cannot read export source: ' . $source);
        }
        try {
            while (!feof($in)) {
                $chunk = fread($in, self::CHUNK);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                self::push($state, $out, $chunk);
            }
        } finally {
            fclose($in);
        }
    }

    /**
     * Reads one frame header (path + declared size). Returns null at the
     * authenticated stream end; the caller then streams the payload itself.
     *
     * @param resource $in
     * @param string $state
     * @return array{0: string, 1: int}|null
     */
    private static function pullHeader($in, string &$state): ?array
    {
        $pathLengthRaw = self::pullChunk($in, $state, 2);
        if ($pathLengthRaw === null) {
            return null;
        }
        $pathLength = unpack('n', $pathLengthRaw)[1];
        $path = self::pullChunk($in, $state, $pathLength);
        $sizeRaw = self::pullChunk($in, $state, 8);
        if ($path === null || $sizeRaw === null) {
            throw new RuntimeException('Truncated export frame.');
        }

        return [$path, unpack('J', $sizeRaw)[1]];
    }

    /**
     * Pulls exactly one secretstream chunk holding $length plaintext bytes.
     * Returns null only when the stream ends cleanly before any bytes.
     *
     * @param resource $in
     * @param string $state
     */
    private static function pullChunk($in, string &$state, int $length): ?string
    {
        $ciphertext = self::readUpTo($in, $length + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
        if ($ciphertext === null) {
            return null;
        }
        // The closing chunk carries an empty payload: exactly ABYTES of
        // authentication overhead. Anything shorter is a truncated file.
        if (strlen($ciphertext) === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
            $tail = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $ciphertext);
            if ($tail !== false && $tail[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL && $tail[0] === '') {
                return null;
            }
        }
        if (strlen($ciphertext) !== $length + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
            throw new RuntimeException('Truncated or corrupt export stream.');
        }

        $plain = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $ciphertext);
        if ($plain === false) {
            throw new RuntimeException('Export authentication failed — wrong passphrase or corrupted file.');
        }
        [$data, $tag] = $plain;
        if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
            throw new RuntimeException('Export stream ended mid-frame.');
        }

        return $data;
    }

    /** @param resource $in */
    private static function readExact($in, int $length): string
    {
        $data = '';
        while (strlen($data) < $length && !feof($in)) {
            $read = fread($in, $length - strlen($data));
            if ($read === false || $read === '') {
                break;
            }
            $data .= $read;
        }
        if (strlen($data) !== $length) {
            throw new RuntimeException('Truncated export header.');
        }

        return $data;
    }

    /**
     * Reads up to $length bytes; returns null only when zero bytes are
     * available at a clean end of file.
     *
     * @param resource $in
     */
    private static function readUpTo($in, int $length): ?string
    {
        $data = '';
        while (strlen($data) < $length) {
            if (feof($in)) {
                break;
            }
            $read = fread($in, $length - strlen($data));
            if ($read === false) {
                break;
            }
            if ($read === '') {
                break;
            }
            $data .= $read;
        }

        return $data === '' ? null : $data;
    }

    private static function deriveKey(string $passphrase, string $salt, ?int $opslimit = null, ?int $memlimit = null): string
    {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            $passphrase,
            $salt,
            $opslimit ?? SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            $memlimit ?? SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }

}
