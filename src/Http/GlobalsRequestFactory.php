<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Http;

use AlohaSocial\Core\Http\Request;

use function array_key_exists;
use function array_keys;
use function array_merge;
use function bin2hex;
use function file_get_contents;
use function function_exists;
use function getallheaders;
use function is_array;
use function is_file;
use function is_string;
use function random_bytes;
use function str_starts_with;
use function str_replace;
use function strtolower;
use function substr;
use function trim;

/**
 * Builds the core request from PHP's globals.
 *
 * PHP parses `multipart/form-data` itself and does not expose the raw body on
 * `php://input`, but the core reads uploads from the body. For those requests
 * the body is rebuilt from `$_POST` and `$_FILES` with a fresh boundary, so the
 * core's multipart parser sees the same envelope a non-PHP client sent.
 */
final class GlobalsRequestFactory
{
    private const EOL = "\r\n";

    public static function create(): Request
    {
        $request = Request::fromGlobals();

        $contentType = $request->header('content-type');
        if (
            $request->method !== 'POST'
            || $contentType === null
            || !str_starts_with(strtolower(trim($contentType)), 'multipart/form-data')
            || $request->body !== ''
        ) {
            return $request;
        }

        $rebuilt = self::multipartBody($_POST, $_FILES);
        if ($rebuilt === null) {
            return $request;
        }

        return new Request(
            $request->method,
            $request->path,
            $request->query,
            array_merge(self::requestHeaders(), ['Content-Type' => $rebuilt['contentType']]),
            $rebuilt['body'],
            [],
            $request->remoteAddress,
        );
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     *
     * @return array{contentType: string, body: string}|null
     */
    public static function multipartBody(array $post, array $files): ?array
    {
        if ($post === [] && $files === []) {
            return null;
        }

        $boundary = '----AlohaSocial' . bin2hex(random_bytes(16));
        $body = '';

        foreach (self::flattenFields($post) as [$name, $value]) {
            if (!is_string($value)) {
                continue;
            }

            $body .= '--' . $boundary . self::EOL
                . 'Content-Disposition: form-data; name="' . self::quote($name) . '"' . self::EOL
                . self::EOL
                . $value . self::EOL;
        }

        foreach (self::flattenFiles($files) as [$name, $file]) {
            $tmp = $file['tmp_name'] ?? null;
            if (!is_string($tmp) || $tmp === '' || !is_file($tmp)) {
                continue;
            }

            $contents = file_get_contents($tmp);
            if ($contents === false) {
                continue;
            }

            $filename = is_string($file['name'] ?? null) && $file['name'] !== '' ? $file['name'] : 'upload';
            $type = is_string($file['type'] ?? null) && $file['type'] !== ''
                ? $file['type']
                : 'application/octet-stream';

            $body .= '--' . $boundary . self::EOL
                . 'Content-Disposition: form-data; name="' . self::quote($name)
                . '"; filename="' . self::quote($filename) . '"' . self::EOL
                . 'Content-Type: ' . $type . self::EOL
                . self::EOL
                . $contents . self::EOL;
        }

        if ($body === '') {
            return null;
        }

        return [
            'contentType' => 'multipart/form-data; boundary=' . $boundary,
            'body' => $body . '--' . $boundary . '--' . self::EOL,
        ];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return list<array{0: string, 1: mixed}>
     */
    private static function flattenFields(array $values, string $prefix = ''): array
    {
        $pairs = [];

        foreach ($values as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';

            if (is_array($value)) {
                foreach (self::flattenFields($value, $name) as $pair) {
                    $pairs[] = $pair;
                }

                continue;
            }

            $pairs[] = [$name, $value];
        }

        return $pairs;
    }

    /**
     * @param array<string, mixed> $files
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private static function flattenFiles(array $files, string $prefix = ''): array
    {
        $result = [];

        foreach ($files as $field => $info) {
            if (!is_array($info)) {
                continue;
            }

            $name = $prefix === '' ? (string) $field : $prefix . '[' . $field . ']';

            if (array_key_exists('tmp_name', $info) && is_string($info['tmp_name'])) {
                $result[] = [$name, $info];

                continue;
            }

            $names = $info['name'] ?? [];
            if (!is_array($names)) {
                continue;
            }

            foreach (array_keys($names) as $index) {
                $entry = [];
                foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
                    $entry[$key] = is_array($info[$key] ?? null) ? ($info[$key][$index] ?? null) : null;
                }

                foreach (self::flattenFiles([$index => $entry], $name) as $flattened) {
                    $result[] = $flattened;
                }
            }
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private static function requestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers) && $headers !== []) {
                return $headers;
            }
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($key, 5))] = $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[str_replace('_', '-', $key)] = $value;
            }
        }

        return $headers;
    }

    private static function quote(string $value): string
    {
        return str_replace(['"', "\r", "\n"], '', $value);
    }
}
