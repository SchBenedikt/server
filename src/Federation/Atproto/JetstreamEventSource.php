<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Federation\Atproto;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccountStatus;
use AlohaSocial\Core\Domain\Atproto\AtprotoEvent;
use AlohaSocial\Core\Domain\Atproto\AtprotoEventKind;
use AlohaSocial\Core\Domain\Atproto\AtprotoEventPage;
use AlohaSocial\Core\Domain\Atproto\AtprotoEventSource;
use AlohaSocial\Core\Domain\Atproto\AtprotoIdentifiers;
use AlohaSocial\Core\Domain\Atproto\AtprotoRecordOperation;
use AlohaSocial\Core\Domain\Atproto\AtprotoSourceStatus;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

use function base64_encode;
use function chr;
use function fclose;
use function feof;
use function fread;
use function fwrite;
use function intdiv;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function json_decode;
use function max;
use function microtime;
use function min;
use function ord;
use function pack;
use function parse_url;
use function random_bytes;
use function rawurlencode;
use function sha1;
use function str_contains;
use function str_starts_with;
use function stream_context_create;
use function stream_get_meta_data;
use function stream_set_timeout;
use function stream_socket_client;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use function unpack;

/**
 * Subscribes to a public Jetstream relay and turns its messages into core
 * events.
 *
 * The host owns the transport: the core only sees bounded pages of
 * {@see AtprotoEvent}. Each pull opens one WebSocket, replays from the stored
 * `time_us` cursor, keeps a bounded number of events and closes again. The
 * cursor is the Jetstream event time, so a restarted worker continues where it
 * stopped instead of losing the gap.
 *
 * Jetstream is a global firehose, so the source filters by the accounts this
 * instance actually follows and by records that reference one of its own
 * published posts. That keeps both the inbound record store and the
 * reconciliation work proportional to the instance, not to Bluesky.
 */
final class JetstreamEventSource implements AtprotoEventSource
{
    public const NAME = 'jetstream';

    /** Jetstream keeps roughly a day of replayable events. */
    private const PULL_SECONDS = 15;

    /** Return after this long without a message so the worker can drain jobs. */
    private const IDLE_SECONDS = 2;

    private const MAX_FRAME_BYTES = 4_194_304;

    private const MAX_HANDSHAKE_BYTES = 16_384;

    private const COLLECTIONS = [
        'app.bsky.feed.post',
        'app.bsky.feed.like',
        'app.bsky.feed.repost',
        'app.bsky.graph.follow',
    ];

    private const WEBSOCKET_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    public function __construct(
        private readonly string $url,
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function status(): AtprotoSourceStatus
    {
        return AtprotoSourceStatus::live(self::NAME, Time::toSecondPrecision($this->clock->now()));
    }

    public function pull(?string $cursor, int $limit): AtprotoEventPage
    {
        $limit = max(1, min(100, $limit));
        [$followed, $published] = $this->watchList();

        // Nothing to watch: do not open the firehose just to throw it away.
        if ($followed === [] && $published === []) {
            return new AtprotoEventPage([], $cursor);
        }

        $socket = $this->connect($cursor, $limit);
        $deadline = microtime(true) + self::PULL_SECONDS;
        $idleDeadline = microtime(true) + self::IDLE_SECONDS;
        $events = [];
        $lastSeen = $cursor;
        $hasMore = false;
        $fragment = '';
        $fragmentOpcode = 0;

        try {
            while (true) {
                $now = microtime(true);
                if ($now >= $deadline) {
                    $hasMore = true;
                    break;
                }

                if ($now >= $idleDeadline) {
                    break;
                }

                $frame = $this->readFrame($socket, min($deadline, $idleDeadline));
                if ($frame === null) {
                    break;
                }

                $idleDeadline = microtime(true) + self::IDLE_SECONDS;

                [$opcode, $payload, $final] = $frame;

                if ($opcode === 0x8) {
                    break;
                }

                if ($opcode === 0x9) {
                    $this->writeFrame($socket, 0xA, $payload);
                    continue;
                }

                if ($opcode === 0xA) {
                    continue;
                }

                if ($opcode === 0x1 || $opcode === 0x2) {
                    $fragment = $payload;
                    $fragmentOpcode = $opcode;
                } elseif ($opcode === 0x0 && $fragmentOpcode !== 0) {
                    $fragment .= $payload;
                } else {
                    continue;
                }

                if (!$final) {
                    continue;
                }

                $message = $fragment;
                $fragment = '';
                $fragmentOpcode = 0;
                if (strlen($message) > self::MAX_FRAME_BYTES) {
                    continue;
                }

                $data = json_decode($message, true);
                if (!is_array($data)) {
                    continue;
                }

                $timeUs = is_int($data['time_us'] ?? null) ? $data['time_us'] : null;
                if ($timeUs !== null) {
                    $lastSeen = (string) $timeUs;
                }

                $event = $this->toEvent($data);
                if ($event === null || !$this->wanted($event, $followed, $published)) {
                    continue;
                }

                $events[] = $event;
                if (count($events) >= $limit) {
                    $hasMore = true;
                    break;
                }
            }

            $this->writeFrame($socket, 0x8, '');
        } finally {
            fclose($socket);
        }

        return new AtprotoEventPage($events, $lastSeen, $hasMore);
    }

    /**
     * The accounts and posts this instance cares about.
     *
     * @return array{0: array<string, true>, 1: array<string, true>}
     */
    private function watchList(): array
    {
        $followed = [];
        $published = [];

        try {
            $rows = $this->db->fetchAll(
                "SELECT actor_id FROM social_following WHERE state = 'accepted'",
            );
            foreach ($rows as $row) {
                $actor = is_string($row['actor_id'] ?? null) ? trim($row['actor_id']) : '';
                if (str_starts_with($actor, 'did:')) {
                    $followed[$actor] = true;
                }
            }

            // AT-native follows are stored separately from the ActivityPub
            // graph; include them or users following only through Bluesky
            // never receive those authors' Jetstream records.
            $rows = $this->db->fetchAll(
                "SELECT subject_did FROM social_atproto_follow WHERE state = 'active'",
            );
            foreach ($rows as $row) {
                $did = is_string($row['subject_did'] ?? null) ? trim($row['subject_did']) : '';
                if (str_starts_with($did, 'did:')) {
                    $followed[$did] = true;
                }
            }

            $rows = $this->db->fetchAll(
                'SELECT uri FROM social_atproto_publication ORDER BY published_at DESC LIMIT 5000',
            );
            foreach ($rows as $row) {
                $uri = is_string($row['uri'] ?? null) ? trim($row['uri']) : '';
                if ($uri !== '') {
                    $published[$uri] = true;
                }
            }
        } catch (Throwable) {
            // An installation without the ATProtocol tables simply has
            // nothing to watch yet.
            return [[], []];
        }

        return [$followed, $published];
    }

    /**
     * @param array<string, true> $followed
     * @param array<string, true> $published
     */
    private function wanted(AtprotoEvent $event, array $followed, array $published): bool
    {
        if ($event->kind === AtprotoEventKind::Account) {
            return isset($followed[$event->did]);
        }

        if (isset($followed[$event->did])) {
            return true;
        }

        $record = $event->record;
        $references = [];

        $subject = $record['subject'] ?? null;
        if (is_array($subject) && is_string($subject['uri'] ?? null)) {
            $references[] = $subject['uri'];
        }

        $reply = $record['reply'] ?? null;
        if (is_array($reply)) {
            foreach (['parent', 'root'] as $key) {
                $reference = $reply[$key] ?? null;
                if (is_array($reference) && is_string($reference['uri'] ?? null)) {
                    $references[] = $reference['uri'];
                }
            }
        }

        foreach ($references as $uri) {
            if (isset($published[trim($uri)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return resource
     */
    private function connect(?string $cursor, int $limit)
    {
        $parts = parse_url($this->url);
        if (!is_array($parts) || !is_string($parts['host'] ?? null) || $parts['host'] === '') {
            throw new RuntimeException('The Jetstream URL is not a valid WebSocket URL.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'wss'));
        $secure = $scheme === 'wss' || $scheme === 'https';
        $host = $parts['host'];
        $port = is_int($parts['port'] ?? null) ? $parts['port'] : ($secure ? 443 : 80);
        $path = is_string($parts['path'] ?? null) && $parts['path'] !== '' ? $parts['path'] : '/subscribe';

        $query = [];
        foreach (self::COLLECTIONS as $collection) {
            $query[] = 'wantedCollections=' . rawurlencode($collection);
        }
        if ($cursor !== null && $cursor !== '') {
            $query[] = 'cursor=' . rawurlencode($cursor);
        }
        $query[] = 'maxMessageSizeBytes=' . self::MAX_FRAME_BYTES;

        $target = $path . '?' . implode('&', $query);
        $transport = $secure ? 'tls' : 'tcp';
        $context = stream_context_create([
            'ssl' => [
                'peer_name' => $host,
                'SNI_enabled' => true,
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $transport . '://' . $host . ':' . $port,
            $errno,
            $errstr,
            10,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if ($socket === false) {
            throw new RuntimeException('The Jetstream relay is unreachable: ' . ($errstr !== '' ? $errstr : 'connection failed'));
        }

        stream_set_timeout($socket, self::PULL_SECONDS);

        $key = base64_encode(random_bytes(16));
        $request = 'GET ' . $target . " HTTP/1.1\r\n"
            . 'Host: ' . $host . ($port === 443 || $port === 80 ? '' : ':' . $port) . "\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . 'Sec-WebSocket-Key: ' . $key . "\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "User-Agent: AlohaSocial\r\n\r\n";

        if (fwrite($socket, $request) === false) {
            fclose($socket);

            throw new RuntimeException('The Jetstream handshake could not be sent.');
        }

        $headers = '';
        while (!str_contains($headers, "\r\n\r\n")) {
            $chunk = fread($socket, 1);
            if ($chunk === false || $chunk === '') {
                fclose($socket);

                throw new RuntimeException('The Jetstream handshake was interrupted.');
            }

            $headers .= $chunk;
            if (strlen($headers) > self::MAX_HANDSHAKE_BYTES) {
                fclose($socket);

                throw new RuntimeException('The Jetstream handshake answer is too large.');
            }
        }

        $expected = base64_encode(sha1($key . self::WEBSOCKET_GUID, true));
        if (!str_contains($headers, ' 101 ') || !str_contains(strtolower($headers), strtolower('Sec-WebSocket-Accept: ' . $expected))) {
            fclose($socket);

            throw new RuntimeException('The Jetstream relay refused the WebSocket upgrade.');
        }

        return $socket;
    }

    /**
     * @param resource $socket
     *
     * @return array{0: int, 1: string, 2: bool}|null
     */
    private function readFrame($socket, float $deadline): ?array
    {
        $header = $this->readBytes($socket, 2, $deadline);
        if ($header === null) {
            return null;
        }

        $first = ord($header[0]);
        $second = ord($header[1]);
        $final = ($first & 0x80) !== 0;
        $opcode = $first & 0x0F;
        $masked = ($second & 0x80) !== 0;
        $length = $second & 0x7F;

        if ($length === 126) {
            $extended = $this->readBytes($socket, 2, $deadline);
            if ($extended === null) {
                return null;
            }
            $length = (int) (unpack('n', $extended)[1] ?? 0);
        } elseif ($length === 127) {
            $extended = $this->readBytes($socket, 8, $deadline);
            if ($extended === null) {
                return null;
            }
            $length = (int) (unpack('J', $extended)[1] ?? 0);
        }

        if ($length < 0 || $length > self::MAX_FRAME_BYTES) {
            throw new RuntimeException('The Jetstream relay sent an oversized frame.');
        }

        $mask = null;
        if ($masked) {
            $mask = $this->readBytes($socket, 4, $deadline);
            if ($mask === null) {
                return null;
            }
        }

        $payload = $length === 0 ? '' : $this->readBytes($socket, $length, $deadline);
        if ($payload === null) {
            return null;
        }

        if ($mask !== null) {
            $payload = $this->unmask($payload, $mask);
        }

        return [$opcode, $payload, $final];
    }

    /**
     * @param resource $socket
     */
    private function readBytes($socket, int $length, float $deadline): ?string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            if (microtime(true) >= $deadline) {
                return null;
            }

            $chunk = fread($socket, $length - strlen($buffer));
            if ($chunk === false) {
                return null;
            }

            if ($chunk === '') {
                $meta = stream_get_meta_data($socket);
                if (feof($socket) || ($meta['timed_out'] ?? false)) {
                    return null;
                }

                continue;
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function unmask(string $payload, string $mask): string
    {
        $unmasked = '';
        $length = strlen($payload);
        for ($index = 0; $index < $length; ++$index) {
            $unmasked .= $payload[$index] ^ $mask[$index % 4];
        }

        return $unmasked;
    }

    /**
     * @param resource $socket
     */
    private function writeFrame($socket, int $opcode, string $payload): void
    {
        $length = strlen($payload);
        $frame = chr(0x80 | $opcode);

        if ($length < 126) {
            $frame .= chr(0x80 | $length);
        } elseif ($length < 65_536) {
            $frame .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $length);
        }

        $mask = random_bytes(4);
        $frame .= $mask . $this->unmask($payload, $mask);

        @fwrite($socket, $frame);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function toEvent(array $data): ?AtprotoEvent
    {
        $did = is_string($data['did'] ?? null) ? trim($data['did']) : '';
        $timeUs = is_int($data['time_us'] ?? null) ? $data['time_us'] : null;
        if ($did === '' || $timeUs === null || !AtprotoIdentifiers::isDid($did)) {
            return null;
        }

        $receivedAt = new DateTimeImmutable('@' . intdiv($timeUs, 1_000_000), new DateTimeZone('UTC'));

        try {
            $kind = strtolower((string) ($data['kind'] ?? ''));

            if ($kind === 'commit') {
                $commit = $data['commit'] ?? null;
                if (!is_array($commit)) {
                    return null;
                }

                $collection = is_string($commit['collection'] ?? null) ? trim($commit['collection']) : '';
                if (!in_array($collection, self::COLLECTIONS, true)) {
                    return null;
                }

                $operation = AtprotoRecordOperation::tryFrom(strtolower((string) ($commit['operation'] ?? '')));
                if ($operation === null) {
                    return null;
                }

                $record = $commit['record'] ?? null;
                $cid = is_string($commit['cid'] ?? null) ? trim($commit['cid']) : null;
                if ($operation !== AtprotoRecordOperation::Delete && !is_array($record)) {
                    return null;
                }

                return new AtprotoEvent(
                    source: self::NAME,
                    kind: AtprotoEventKind::Record,
                    did: $did,
                    receivedAt: $receivedAt,
                    operation: $operation,
                    collection: $collection,
                    rkey: is_string($commit['rkey'] ?? null) ? trim($commit['rkey']) : '',
                    cid: $cid === '' ? null : $cid,
                    rev: is_string($commit['rev'] ?? null) ? trim($commit['rev']) : null,
                    sequence: $timeUs,
                    record: $operation === AtprotoRecordOperation::Delete ? [] : (array) $record,
                );
            }

            if ($kind === 'identity') {
                $identity = $data['identity'] ?? null;
                $handle = is_array($identity) && is_string($identity['handle'] ?? null) ? trim($identity['handle']) : null;
                if ($handle !== null && !AtprotoIdentifiers::isHandle($handle)) {
                    $handle = null;
                }

                return new AtprotoEvent(
                    source: self::NAME,
                    kind: AtprotoEventKind::Account,
                    did: $did,
                    receivedAt: $receivedAt,
                    sequence: $timeUs,
                    active: true,
                    accountStatus: AtprotoAccountStatus::Active,
                    handle: $handle,
                );
            }

            if ($kind === 'account') {
                $account = $data['account'] ?? null;
                if (!is_array($account)) {
                    return null;
                }

                $active = is_bool($account['active'] ?? null) ? $account['active'] : false;
                $status = AtprotoAccountStatus::tryFrom(strtolower((string) ($account['status'] ?? '')));
                if ($status === null || ($active && !$status->isAvailable()) || (!$active && $status->isAvailable())) {
                    $status = $active ? AtprotoAccountStatus::Active : AtprotoAccountStatus::Deleted;
                }

                return new AtprotoEvent(
                    source: self::NAME,
                    kind: AtprotoEventKind::Account,
                    did: $did,
                    receivedAt: $receivedAt,
                    sequence: $timeUs,
                    active: $active,
                    accountStatus: $status,
                );
            }
        } catch (InvalidArgumentException) {
            return null;
        }

        return null;
    }
}
