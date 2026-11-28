<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Admin;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Account\AccountDeletion;
use AlohaSocial\Core\Jobs\JobSubject;
use AlohaSocial\Core\Domain\Moderation\ModerationAction;
use AlohaSocial\Core\Domain\Moderation\ModerationActionKind;
use AlohaSocial\Core\Domain\Moderation\PdoModerationActionRepository;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Storage\S3CompatibleClient;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Server\Accounts\Account;
use AlohaSocial\Server\Accounts\AccountRepository;
use AlohaSocial\Server\Accounts\EmailChangeService;
use AlohaSocial\Server\Accounts\Invitations;
use AlohaSocial\Server\Auth\AtprotoSessionRepository;
use AlohaSocial\Server\Auth\MastodonOAuthRepository;
use AlohaSocial\Server\Auth\PasswordHasher;
use AlohaSocial\Server\Auth\PasswordPolicy;
use AlohaSocial\Server\Auth\SessionManager;
use AlohaSocial\Server\Config\HostConfig;
use AlohaSocial\Server\Community\InstancePages;
use AlohaSocial\Server\Mail\MailException;
use AlohaSocial\Server\Mail\Mailer;
use AlohaSocial\Server\Migrations\CoreSchemaFingerprint;
use AlohaSocial\Server\Migrations\MigrationRunner;
use AlohaSocial\Server\Migrations\Migrations;
use AlohaSocial\Server\Support\BuildInfo;
use AlohaSocial\Server\Support\CoreVersion;
use AlohaSocial\Server\Support\EnvFile;
use AlohaSocial\Server\Support\LogRedactor;
use DateTimeImmutable;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

use function array_diff;
use function array_is_list;
use function array_keys;
use function array_map;
use function array_slice;
use function arsort;
use function basename;
use function count;
use function explode;
use function hash;
use function file_exists;
use function filesize;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_readable;
use function is_scalar;
use function is_string;
use function is_writable;
use function json_decode;
use function max;
use function mb_strlen;
use function mb_substr;
use function min;
use function parse_url;
use function preg_match;
use function preg_split;
use function rawurlencode;
use function round;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_getcsv;
use function strlen;
use function strpos;
use function strrpos;
use function str_starts_with;
use function strstr;
use function strtolower;
use function substr;
use function time;
use function trim;

/**
 * Administration and moderation read/write model.
 *
 * Everything that needs privileged access lives here rather than in the core:
 * the host owns accounts, sessions and the moderation queue. The core
 * application is only used for actions the core authorises itself, such as a
 * federation-aware post deletion.
 */
final class AdminService
{
    public const REPORT_CATEGORIES = ['spam', 'abuse', 'harassment', 'misinformation', 'other'];

    public const REPORT_STATES = ['open', 'resolved', 'dismissed'];

    private const WORKER_HEARTBEAT = 'worker';

    private const TASK_HEARTBEAT_PREFIX = 'worker_task.';

    /** A task that has not succeeded for this long is reported as stale. */
    private const TASK_STALE_SECONDS = 600;

    /** Success writes are throttled; the worker loop runs several times a second. */
    private const TASK_WRITE_INTERVAL = 15;

    private array $taskWriteThrottle = [];

    private readonly AtprotoSessionRepository $atprotoSessions;

    private readonly MastodonOAuthRepository $mastodonOAuth;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AccountRepository $accounts,
        private readonly PasswordHasher $hasher,
        private readonly SessionManager $sessions,
        private readonly Application $app,
        private readonly HostConfig $config,
        private readonly ?S3CompatibleClient $s3Client = null,
        private readonly ?EmailChangeService $emailChange = null,
        private readonly ?InstancePages $instancePages = null,
        private readonly ?Mailer $mailer = null,
        private readonly ?Invitations $invitations = null,
    ) {
        $this->atprotoSessions = new AtprotoSessionRepository($db);
        $this->mastodonOAuth = new MastodonOAuthRepository($db);
    }

    /**
     * Writes the worker heartbeat. Called once per loop by `bin/worker.php`.
     */
    public function heartbeat(): void
    {
        $now = Time::format($this->clock->now());

        $updated = $this->db->execute(
            'UPDATE runtime_state SET value = :value, updated_at = :updated_at WHERE name = :name',
            ['value' => (string) time(), 'updated_at' => $now, 'name' => self::WORKER_HEARTBEAT],
        );

        if ($updated === 0) {
            $this->db->execute(
                'INSERT INTO runtime_state (name, value, updated_at) VALUES (:name, :value, :updated_at)',
                ['name' => self::WORKER_HEARTBEAT, 'value' => (string) time(), 'updated_at' => $now],
            );
        }
    }

    /**
     * Records that a periodic worker task completed. Called by `bin/worker.php`
     * after each bounded step so diagnostics can distinguish a live worker
     * from one whose background pipelines silently stopped.
     */
    public function taskSuccess(string $task): void
    {
        $this->recordTask($task, 'ok');
    }

    /**
     * Records a failed worker task. The reason is truncated and never carries
     * request bodies, secrets or actor tokens.
     */
    public function taskFailure(string $task, string $reason): void
    {
        $this->recordTask($task, 'fail', $reason);
    }

    private function recordTask(string $task, string $outcome, ?string $reason = null): void
    {
        $task = (string) preg_replace('/[^a-z0-9-]/', '', strtolower($task));
        if ($task === '') {
            return;
        }

        $now = time();
        if ($outcome === 'ok') {
            if (isset($this->taskWriteThrottle[$task])
                && $now - $this->taskWriteThrottle[$task] < self::TASK_WRITE_INTERVAL) {
                return;
            }
            $this->taskWriteThrottle[$task] = $now;
        }

        $name = self::TASK_HEARTBEAT_PREFIX . $task;
        $row = $this->db->fetchOne(
            'SELECT value FROM runtime_state WHERE name = :name',
            ['name' => $name],
        );
        $data = is_array($row) ? json_decode((string) $row['value'], true) : null;
        $data = is_array($data) ? $data : [];

        if ($outcome === 'ok') {
            // The last failure reason stays visible until the next failure;
            // diagnostics benefit from knowing what broke even after recovery.
            $data['ok'] = $now;
        } else {
            $data['fail'] = $now;
            $data['error'] = mb_substr($reason ?? 'unknown', 0, 200);
        }

        $value = json_encode($data, JSON_THROW_ON_ERROR);
        $updated = $this->db->execute(
            'UPDATE runtime_state SET value = :value, updated_at = :updated_at WHERE name = :name',
            ['value' => $value, 'updated_at' => Time::format($this->clock->now()), 'name' => $name],
        );
        if ($updated === 0) {
            $this->db->execute(
                'INSERT INTO runtime_state (name, value, updated_at) VALUES (:name, :value, :updated_at)',
                ['name' => $name, 'value' => $value, 'updated_at' => Time::format($this->clock->now())],
            );
        }
    }

    /**
     * Per-task worker health for the diagnostics surface. Only tasks that have
     * reported at least once are listed, so disabled pipelines (for example
     * AT Protocol sync without a relay) never warn.
     *
     * @return list<array<string, mixed>>
     */
    public function workerTasks(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT name, value FROM runtime_state WHERE name LIKE '" . self::TASK_HEARTBEAT_PREFIX . "%'",
        );

        $now = time();
        $tasks = [];
        foreach ($rows as $row) {
            $data = json_decode((string) $row['value'], true);
            if (!is_array($data)) {
                continue;
            }
            $ok = isset($data['ok']) ? (int) $data['ok'] : null;
            $age = $ok === null ? null : $now - $ok;
            $tasks[] = [
                'task' => substr((string) $row['name'], strlen(self::TASK_HEARTBEAT_PREFIX)),
                'last_ok_seconds_ago' => $age,
                'stale' => $age === null || $age > self::TASK_STALE_SECONDS,
                'last_failure_seconds_ago' => isset($data['fail']) ? $now - (int) $data['fail'] : null,
                'error' => isset($data['error']) ? (string) $data['error'] : null,
            ];
        }
        usort($tasks, static fn (array $a, array $b): int => $a['task'] <=> $b['task']);

        return $tasks;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Public readiness for orchestrators: HTTP liveness is the separate
     * `/healthz` route; this checks the state that actually serves requests.
     * No secret, DSN or raw exception text is returned.
     *
     * @return array<string, mixed>
     */
    public function readiness(): array
    {
        $database = false;
        try {
            $this->db->fetchValue('SELECT 1');
            CoreSchemaFingerprint::assertCurrent($this->db);
            $database = true;
        } catch (Throwable) {
            $database = false;
        }

        $worker = $this->workerStatus();
        $queue = $database ? $this->queueCounts() : ['pending' => 0, 'running' => 0, 'dead' => 0, 'oldest_pending' => null];
        $ready = $database;

        return [
            'status' => $ready ? ($worker['online'] ? 'ok' : 'degraded') : 'unavailable',
            'ready' => $ready,
            'checks' => [
                'database' => [
                    'status' => $database ? 'ok' : 'error',
                    'detail' => $database ? 'Reachable and schema current' : 'Unavailable, migration pending or schema drift',
                ],
                'worker' => [
                    'status' => $worker['online'] ? 'ok' : 'warn',
                    'last_beat' => $worker['last_beat'],
                ],
                'queue' => [
                    'status' => (int) ($queue['dead'] ?? 0) > 0 ? 'warn' : 'ok',
                    'pending' => (int) ($queue['pending'] ?? 0),
                    'running' => (int) ($queue['running'] ?? 0),
                    'dead' => (int) ($queue['dead'] ?? 0),
                    'oldest_pending' => $queue['oldest_pending'] ?? null,
                ],
            ],
        ];
    }

    public function overview(): array
    {
        $now = $this->clock->now();
        $today = Time::format($now->setTime(0, 0));
        $weekAgo = Time::format($now->modify('-7 days'));
        $dayAgo = Time::format($now->modify('-24 hours'));

        $stats = [
            'accounts' => [
                'total' => $this->intValue('SELECT COUNT(*) FROM account'),
                'active' => $this->intValue('SELECT COUNT(*) FROM account WHERE disabled_at IS NULL'),
                'disabled' => $this->intValue('SELECT COUNT(*) FROM account WHERE disabled_at IS NOT NULL'),
                'admins' => $this->intValue("SELECT COUNT(*) FROM account WHERE role = 'admin' AND disabled_at IS NULL"),
                'admins_without_mfa' => $this->intValue(
                    "SELECT COUNT(*) FROM account WHERE role = 'admin' AND disabled_at IS NULL AND totp_enabled_at IS NULL",
                ),
                'new_7d' => $this->intValue('SELECT COUNT(*) FROM account WHERE created_at >= :cutoff', ['cutoff' => $weekAgo]),
            ],
            'posts' => [
                'total' => $this->intValue('SELECT COUNT(*) FROM social_post WHERE deleted_at IS NULL'),
                'public' => $this->intValue("SELECT COUNT(*) FROM social_post WHERE deleted_at IS NULL AND visibility = 'public'"),
                'replies' => $this->intValue('SELECT COUNT(*) FROM social_post WHERE deleted_at IS NULL AND (in_reply_to_id IS NOT NULL OR in_reply_to_remote_object_id IS NOT NULL)'),
                'today' => $this->intValue('SELECT COUNT(*) FROM social_post WHERE deleted_at IS NULL AND published_at >= :today', ['today' => $today]),
            ],
            'media' => [
                'attachments' => $this->intValue('SELECT COUNT(*) FROM social_attachment'),
                'bytes' => $this->intValue('SELECT COALESCE(SUM(byte_size), 0) FROM social_attachment'),
                'blobs' => $this->blobCount(),
                'blob_bytes' => $this->blobBytes(),
                'storage_driver' => $this->config->storageDriver,
            ],
            'federation' => [
                'remote_posts' => $this->intValue('SELECT COUNT(*) FROM social_remote_post WHERE deleted_at IS NULL'),
                'remote_actors' => $this->intValue('SELECT COUNT(DISTINCT actor_id) FROM social_remote_post WHERE deleted_at IS NULL'),
                'followers' => $this->intValue('SELECT COUNT(*) FROM social_follower'),
                'following' => $this->intValue('SELECT COUNT(*) FROM social_following'),
                'blocked_actors' => $this->intValue('SELECT COUNT(*) FROM social_block'),
                'peer_domains' => count($this->peerDomains()),
            ],
            'notifications' => [
                'total' => $this->intValue('SELECT COUNT(*) FROM social_notification'),
                'unread' => $this->intValue('SELECT COUNT(*) FROM social_notification WHERE read_at IS NULL'),
            ],
            'queue' => $this->queueCounts(),
            'reports' => [
                'open' => $this->intValue("SELECT COUNT(*) FROM moderation_report WHERE state = 'open'"),
                'total' => $this->intValue('SELECT COUNT(*) FROM moderation_report'),
            ],
            'scheduled' => $this->scheduledCounts(),
            'errors' => [
                'recent' => $this->intValue('SELECT COUNT(*) FROM runtime_error WHERE created_at >= :cutoff', ['cutoff' => $dayAgo]),
                'warnings' => $this->intValue("SELECT COUNT(*) FROM runtime_error WHERE created_at >= :cutoff AND level = 'warning'", ['cutoff' => $dayAgo]),
                'failures' => $this->intValue(
                    "SELECT COUNT(*) FROM runtime_error WHERE created_at >= :cutoff AND level IN ('error', 'critical', 'alert', 'emergency')",
                    ['cutoff' => $dayAgo],
                ),
            ],
        ];

        return [
            'generated_at' => Time::format($now),
            'stats' => $stats,
            'health' => $this->health($stats),
            'worker' => $this->workerStatus(),
            'recent_users' => $this->recentUsers(5),
            'recent_posts' => $this->recentPosts(6),
            'audit' => $this->audit(8),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function users(string $query = '', int $page = 1, int $perPage = 20): array
    {
        $perPage = max(5, min(100, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $query = trim($query);

        $where = '';
        $parameters = [];
        if ($query !== '') {
            $where = ' WHERE (handle LIKE :query OR email LIKE :query OR display_name LIKE :query)';
            $parameters['query'] = '%' . $query . '%';
        }

        $total = $this->intValue('SELECT COUNT(*) FROM account' . $where, $parameters);

        $rows = $this->db->fetchAll(
            'SELECT a.id, a.handle, a.email, a.pending_email, a.email_verified_at, a.role, a.display_name, a.created_at, a.disabled_at,'
            . ' (SELECT COUNT(*) FROM social_post p WHERE p.author_id = a.id AND p.deleted_at IS NULL) AS post_count,'
            . ' (SELECT COUNT(*) FROM social_follower f WHERE f.local_user_id = a.id) AS follower_count,'
            . ' (SELECT COUNT(*) FROM social_following g WHERE g.local_user_id = a.id) AS following_count'
            . ' FROM account a' . $where . ' ORDER BY a.created_at DESC, a.handle ASC'
            . ' LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $parameters,
        );

        return [
            'users' => array_map(fn (array $row): array => $this->userRow($row), $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function createUser(
        string $adminId,
        string $handle,
        string $email,
        string $password,
        string $role = Account::ROLE_USER,
        ?string $displayName = null,
    ): array {
        $handle = strtolower(trim($handle));
        $email = strtolower(trim($email));
        $role = strtolower(trim($role));
        $displayName = $displayName === null ? null : trim($displayName);

        $fields = [];
        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,29}$/', $handle) !== 1) {
            $fields['handle'] = '2–30 characters: lowercase letters, digits, "_" or "-".';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $fields['email'] = 'Please enter a valid email address.';
        }
        $passwordError = PasswordPolicy::error($password);
        if ($passwordError !== null) {
            $fields['password'] = $passwordError;
        }
        if (!in_array($role, [Account::ROLE_ADMIN, Account::ROLE_MODERATOR, Account::ROLE_USER], true)) {
            $fields['role'] = 'Unknown role.';
        }
        if ($displayName !== null && mb_strlen($displayName) > 80) {
            $fields['display_name'] = 'At most 80 characters.';
        }
        if ($fields !== []) {
            throw AdminException::invalid('Please check the account details.', $fields);
        }

        if ($this->accounts->exists($handle, $email)) {
            throw AdminException::conflict('Handle or email address is already in use.');
        }

        $account = $this->accounts->create(
            $handle,
            $email,
            $this->hasher->hash($password),
            $role,
            $displayName,
        );

        $this->record($adminId, 'user.create', 'account', $account->id, [
            'role' => $account->role,
        ]);

        return $this->user($account->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateUser(
        string $adminId,
        string $id,
        ?string $displayName = null,
        ?string $role = null,
    ): array {
        $target = $this->requireUser($id);

        if ($role !== null) {
            $role = strtolower(trim($role));
            if (!in_array($role, [Account::ROLE_ADMIN, Account::ROLE_MODERATOR, Account::ROLE_USER], true)) {
                throw AdminException::invalid('Unknown role.', ['role' => 'admin, moderator or user.']);
            }

            if ($target->role === Account::ROLE_ADMIN
                && $role !== Account::ROLE_ADMIN
                && $this->activeAdminCount() <= 1
            ) {
                throw AdminException::conflict('The last active administrator cannot be demoted.');
            }

            if ($role !== $target->role) {
                $this->accounts->updateRole($id, $role);
                $this->record($adminId, 'user.role', 'account', $id, [
                    'from' => $target->role,
                    'to' => $role,
                ]);
            }
        }

        if ($displayName !== null) {
            $displayName = trim($displayName);
            if (mb_strlen($displayName) > 80) {
                throw AdminException::invalid('The display name is too long.', ['display_name' => 'At most 80 characters.']);
            }

            $this->accounts->updateDisplayName($id, $displayName === '' ? null : $displayName);
            $this->record($adminId, 'user.display_name', 'account', $id, []);
        }

        return $this->user($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function disableUser(string $adminId, string $id): array
    {
        $target = $this->requireUser($id);
        if ($id === $adminId) {
            throw AdminException::conflict('You cannot deactivate your own account.');
        }
        if ($target->isDisabled()) {
            return $this->user($id);
        }
        if ($target->role === Account::ROLE_ADMIN && $this->activeAdminCount() <= 1) {
            throw AdminException::conflict('The last active administrator cannot be deactivated.');
        }

        $this->accounts->disable($id);
        // A suspended account must not complete an in-flight email move.
        $this->emailChange?->cancelForAccount($id);
        $cancelled = $this->app->queue->cancelBySubject(JobSubject::account($id));
        $revoked = $this->sessions->revokeAccount($id);
        $revoked += $this->atprotoSessions->deleteForAccount($id);
        $revoked += $this->mastodonOAuth->revokeAccount($id, Time::format($this->clock->now()));
        $this->record($adminId, 'user.disable', 'account', $id, [
            'sessions_revoked' => $revoked,
            'jobs_cancelled' => $cancelled,
        ]);

        return $this->user($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function enableUser(string $adminId, string $id): array
    {
        $this->requireUser($id);
        $deletion = $this->app->accountData?->deletionStatus($id);
        if ($deletion !== null && $deletion->state !== AccountDeletion::ACTIVE) {
            throw AdminException::conflict('An account with an active or completed deletion cannot be reactivated.');
        }

        $this->accounts->enable($id);
        $this->record($adminId, 'user.enable', 'account', $id, []);

        return $this->user($id);
    }

    public function resetPassword(string $adminId, string $id, string $password): int
    {
        $this->requireUser($id);
        $passwordError = PasswordPolicy::error($password);
        if ($passwordError !== null) {
            throw AdminException::invalid($passwordError, [
                'password' => $passwordError,
            ]);
        }

        $this->accounts->updatePasswordHash($id, $this->hasher->hash($password));
        $revoked = $this->sessions->revokeAccount($id);
        $revoked += $this->atprotoSessions->deleteForAccount($id);
        $revoked += $this->mastodonOAuth->revokeAccount($id, Time::format($this->clock->now()));
        $this->record($adminId, 'user.password', 'account', $id, ['sessions_revoked' => $revoked]);

        return $revoked;
    }

    public function revokeSessions(string $adminId, string $id): int
    {
        $this->requireUser($id);
        $revoked = $this->sessions->revokeAccount($id);
        $revoked += $this->atprotoSessions->deleteForAccount($id);
        $revoked += $this->mastodonOAuth->revokeAccount($id, Time::format($this->clock->now()));
        $this->record($adminId, 'user.sessions', 'account', $id, ['sessions_revoked' => $revoked]);

        return $revoked;
    }

    /**
     * @return array<string, mixed>
     */
    public function reports(string $state = 'open', int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $parameters = [];
        $where = '';
        if ($state !== 'all') {
            if (!in_array($state, self::REPORT_STATES, true)) {
                $state = 'open';
            }
            $where = ' WHERE r.state = :state';
            $parameters['state'] = $state;
        }

        $rows = $this->db->fetchAll(
            'SELECT r.*, a.display_name AS reporter_name, a.handle AS reporter_handle,'
            . ' a.disabled_at AS reporter_disabled_at,'
            . ' admin.display_name AS assignee_name, admin.handle AS assignee_handle'
            . ' FROM moderation_report r'
            . ' LEFT JOIN account a ON a.id = r.reporter_id'
            . ' LEFT JOIN account admin ON admin.id = r.assigned_to'
            . $where . ' ORDER BY r.created_at DESC LIMIT ' . $limit,
            $parameters,
        );

        $counts = [];
        foreach (self::REPORT_STATES as $known) {
            $counts[$known] = $this->intValue(
                'SELECT COUNT(*) FROM moderation_report WHERE state = :state',
                ['state' => $known],
            );
        }

        return [
            'reports' => array_map(fn (array $row): array => $this->reportRow($row), $rows),
            'counts' => $counts,
            'state' => $state,
        ];
    }

    /**
     * A user-submitted report. The target must exist when it is reported, so
     * an admin never opens an empty case.
     */
    public function createReport(
        string $reporterId,
        string $targetType,
        string $targetId,
        string $category,
        ?string $comment,
    ): array {
        $targetType = strtolower(trim($targetType));
        $targetId = trim($targetId);
        $category = strtolower(trim($category));
        $comment = $comment === null ? null : trim($comment);

        if (!in_array($targetType, ['post', 'account'], true)) {
            throw AdminException::invalid('Unknown target.', ['target_type' => 'post or account.']);
        }
        if ($targetId === '' || mb_strlen($targetId) > 512) {
            throw AdminException::invalid('The target is missing.', ['target_id' => 'Please provide a target.']);
        }
        if (!in_array($category, self::REPORT_CATEGORIES, true)) {
            throw AdminException::invalid('Unknown report reason.', ['category' => 'Please choose a reason.']);
        }
        if ($comment !== null && mb_strlen($comment) > 1000) {
            throw AdminException::invalid('The comment is too long.', ['comment' => 'At most 1000 characters.']);
        }
        if ($targetType === 'post' && !$this->postExists($targetId) && !$this->isRemoteReference($targetId)) {
            throw AdminException::notFound('This post is not available.');
        }
        if ($targetType === 'account' && !$this->accountTargetExists($targetId) && !$this->isRemoteReference($targetId)) {
            throw AdminException::notFound('This account is not available.');
        }

        $existing = $this->db->fetchValue(
            "SELECT COUNT(*) FROM moderation_report WHERE reporter_id = :reporter AND target_type = :type"
            . " AND target_id = :target AND state = 'open'",
            ['reporter' => $reporterId, 'type' => $targetType, 'target' => $targetId],
        );
        if ((int) ($existing ?? 0) > 0) {
            throw AdminException::conflict('You have already reported this target. The report is still open.');
        }

        $now = Time::format($this->clock->now());
        $id = $this->newId();

        $this->db->execute(
            'INSERT INTO moderation_report'
            . ' (id, reporter_id, target_type, target_id, category, comment, state, resolution_note, created_at, updated_at, resolved_at, resolved_by)'
            . ' VALUES (:id, :reporter, :type, :target, :category, :comment, :state, NULL, :created, :updated, NULL, NULL)',
            [
                'id' => $id,
                'reporter' => $reporterId,
                'type' => $targetType,
                'target' => $targetId,
                'category' => $category,
                'comment' => $comment === '' ? null : $comment,
                'state' => 'open',
                'created' => $now,
                'updated' => $now,
            ],
        );

        $this->record($reporterId, 'report.create', $targetType, $targetId, ['category' => $category]);

        return $this->report($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveReport(string $adminId, string $id, string $state, ?string $note): array
    {
        $state = strtolower(trim($state));
        if (!in_array($state, ['resolved', 'dismissed'], true)) {
            throw AdminException::invalid('Unknown status.', ['state' => 'resolved or dismissed.']);
        }

        $note = $note === null ? null : trim($note);
        if ($note !== null && mb_strlen($note) > 500) {
            throw AdminException::invalid('The note is too long.', ['note' => 'At most 500 characters.']);
        }

        $row = $this->db->fetchOne('SELECT * FROM moderation_report WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            throw AdminException::notFound('This report does not exist.');
        }

        $now = Time::format($this->clock->now());
        $this->db->execute(
            'UPDATE moderation_report SET state = :state, resolution_note = :note, updated_at = :updated,'
            . ' resolved_at = :resolved, resolved_by = :admin WHERE id = :id',
            [
                'state' => $state,
                'note' => $note === '' ? null : $note,
                'updated' => $now,
                'resolved' => $now,
                'admin' => $adminId,
                'id' => $id,
            ],
        );

        $this->record($adminId, 'report.' . $state, (string) $row['target_type'], (string) $row['target_id'], [
            'report_id' => $id,
            'note' => $note,
        ]);

        return $this->report($id);
    }

    /**
     * Assigns an open report to an administrator (or clears the assignment
     * with null) so the review queue has a named owner. Only open reports can
     * change hands; a resolved case is history.
     *
     * @return array<string, mixed>
     */
    public function assignReport(string $adminId, string $id, ?string $assigneeId): array
    {
        $assigneeId = $assigneeId === null ? null : trim($assigneeId);
        if ($assigneeId === '') {
            $assigneeId = null;
        }

        if ($assigneeId !== null) {
            $assignee = $this->accounts->find($assigneeId);
            if ($assignee === null || !$assignee->isAdmin() || $assignee->isDisabled()) {
                throw AdminException::invalid(
                    'Unknown assignee.',
                    ['assigned_to' => 'Choose an active administrator account.'],
                );
            }
        }

        $row = $this->db->fetchOne('SELECT * FROM moderation_report WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            throw AdminException::notFound('This report does not exist.');
        }
        if ((string) $row['state'] !== 'open') {
            throw AdminException::conflict('Only an open report can be assigned.');
        }

        $now = Time::format($this->clock->now());
        $this->db->execute(
            'UPDATE moderation_report SET assigned_to = :assignee, assigned_at = :assigned_at,'
            . ' updated_at = :updated WHERE id = :id',
            [
                'assignee' => $assigneeId,
                'assigned_at' => $assigneeId === null ? null : $now,
                'updated' => $now,
                'id' => $id,
            ],
        );

        $this->record(
            $adminId,
            $assigneeId === null ? 'report.unassign' : 'report.assign',
            (string) $row['target_type'],
            (string) $row['target_id'],
            ['report_id' => $id, 'assigned_to' => $assigneeId],
        );

        return $this->report($id);
    }

    /**
     * Reopens a resolved or dismissed report. The resolution fields are
     * cleared — the audit trail keeps who resolved it and why — and the
     * report returns to the open queue, keeping its assignee.
     *
     * @return array<string, mixed>
     */
    public function reopenReport(string $adminId, string $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM moderation_report WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            throw AdminException::notFound('This report does not exist.');
        }
        if ((string) $row['state'] === 'open') {
            throw AdminException::conflict('This report is already open.');
        }

        $now = Time::format($this->clock->now());
        $this->db->execute(
            "UPDATE moderation_report SET state = 'open', resolution_note = NULL, resolved_at = NULL,"
            . ' resolved_by = NULL, updated_at = :updated WHERE id = :id',
            ['updated' => $now, 'id' => $id],
        );

        $this->record($adminId, 'report.reopen', (string) $row['target_type'], (string) $row['target_id'], [
            'report_id' => $id,
            'previous_state' => (string) $row['state'],
        ]);

        return $this->report($id);
    }

    /**
     * Removes a reported post. Local posts go through the core so a federated
     * delete is queued; remote copies are tombstoned in the local cache.
     */
    public function deletePost(string $adminId, string $postId): void
    {
        try {
            $post = $this->app->posts->get($postId);
            $this->app->posts->delete($post->authorId, $postId);
            $this->record($adminId, 'post.delete', 'post', $postId, ['scope' => 'local']);

            return;
        } catch (PostNotFoundException) {
            // Fall through to the remote cache.
        }

        $remote = $this->db->fetchOne(
            'SELECT activity_id, object_id FROM social_remote_post WHERE object_id = :id AND deleted_at IS NULL LIMIT 1',
            ['id' => $postId],
        );
        if ($remote === null) {
            throw AdminException::notFound('This post is not available.');
        }

        $now = Time::format($this->clock->now());
        $this->db->execute(
            'UPDATE social_remote_post SET deleted_at = :now WHERE object_id = :id',
            ['now' => $now, 'id' => $postId],
        );
        $this->record($adminId, 'post.delete', 'post', $postId, ['scope' => 'remote']);
    }

    /**
     * @return array<string, mixed>
     */
    public function jobs(string $state = 'all', int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $where = '';
        if ($state === 'pending') {
            $where = ' WHERE dead_at IS NULL AND reserved_at IS NULL';
        } elseif ($state === 'running') {
            $where = ' WHERE dead_at IS NULL AND reserved_at IS NOT NULL';
        } elseif ($state === 'dead') {
            $where = ' WHERE dead_at IS NOT NULL';
        } else {
            $state = 'all';
        }

        $rows = $this->db->fetchAll(
            'SELECT * FROM social_job' . $where . ' ORDER BY available_at ASC, id ASC LIMIT ' . $limit,
        );

        return [
            'jobs' => array_map(fn (array $row): array => $this->jobRow($row), $rows),
            'counts' => $this->queueCounts(),
            'state' => $state,
            'error_classes' => $this->errorClasses($rows),
        ];
    }

    /**
     * Failure classification for the listed dead jobs: the exception class
     * prefix of each sanitized last_error, counted so an operator can see
     * at a glance whether deaths cluster on one cause (for example a remote
     * rejection) instead of reading every row.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, int>
     */
    private function errorClasses(array $rows): array
    {
        $classes = [];
        foreach ($rows as $row) {
            if ($row['dead_at'] === null || !is_string($row['last_error'] ?? null) || $row['last_error'] === '') {
                continue;
            }

            $class = strstr((string) $row['last_error'], ':', true);
            $class = is_string($class) && $class !== '' ? $class : 'unknown';
            $classes[$class] = ($classes[$class] ?? 0) + 1;
        }
        arsort($classes);

        return array_slice($classes, 0, 10, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function retryJob(string $adminId, string $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM social_job WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            throw AdminException::notFound('This job does not exist.');
        }
        if ($row['dead_at'] === null) {
            throw AdminException::conflict('Only dead jobs can be retried; pending and running jobs need no intervention.');
        }

        $now = Time::format($this->clock->now());
        $this->db->execute(
            'UPDATE social_job SET attempts = 0, dead_at = NULL, reserved_at = NULL, available_at = :now WHERE id = :id',
            ['now' => $now, 'id' => $id],
        );
        $this->record($adminId, 'job.retry', 'job', $id, ['type' => (string) $row['type']]);

        return $this->job($id);
    }

    public function deleteJob(string $adminId, string $id): void
    {
        $row = $this->db->fetchOne('SELECT type FROM social_job WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            throw AdminException::notFound('This job does not exist.');
        }

        $this->db->execute('DELETE FROM social_job WHERE id = :id', ['id' => $id]);
        $this->record($adminId, 'job.delete', 'job', $id, ['type' => (string) $row['type']]);
    }

    public function retryDeadJobs(string $adminId): int
    {
        $now = Time::format($this->clock->now());
        $count = $this->db->execute(
            'UPDATE social_job SET attempts = 0, dead_at = NULL, reserved_at = NULL, available_at = :now WHERE dead_at IS NOT NULL',
            ['now' => $now],
        );
        $this->record($adminId, 'job.retry_all', 'job', null, ['count' => $count]);

        return $count;
    }

    public function purgeDeadJobs(string $adminId, int $olderThanDays = 7): int
    {
        $olderThanDays = max(1, min(365, $olderThanDays));
        $cutoff = Time::format($this->clock->now()->modify('-' . $olderThanDays . ' days'));
        $count = $this->db->execute(
            'DELETE FROM social_job WHERE dead_at IS NOT NULL AND dead_at <= :cutoff',
            ['cutoff' => $cutoff],
        );
        $this->record($adminId, 'job.purge', 'job', null, ['count' => $count, 'older_than_days' => $olderThanDays]);

        return $count;
    }

    public function runQueue(string $adminId): int
    {
        $handled = $this->app->worker->runOnce(20);
        $this->record($adminId, 'queue.run', null, null, ['handled' => $handled]);

        return $handled;
    }

    /**
     * @return array<string, mixed>
     */
    public function federation(): array
    {
        $peers = $this->peerDomains();
        $blocks = $this->db->fetchAll(
            'SELECT b.id, b.local_user_id, b.actor_id, b.blocked_at FROM social_block b ORDER BY b.blocked_at DESC LIMIT 100',
        );

        $recent = $this->db->fetchAll(
            'SELECT object_id, object_author_id, actor_id, content, published_at FROM social_remote_post'
            . ' WHERE deleted_at IS NULL AND kind = :kind ORDER BY published_at DESC LIMIT 12',
            ['kind' => 'post'],
        );

        return [
            'counts' => [
                'remote_posts' => $this->intValue('SELECT COUNT(*) FROM social_remote_post WHERE deleted_at IS NULL'),
                'remote_actors' => $this->intValue('SELECT COUNT(DISTINCT actor_id) FROM social_remote_post WHERE deleted_at IS NULL'),
                'followers' => $this->intValue('SELECT COUNT(*) FROM social_follower'),
                'following' => $this->intValue('SELECT COUNT(*) FROM social_following'),
                'blocks' => $this->intValue('SELECT COUNT(*) FROM social_block'),
                'peers' => count($peers),
            ],
            'peers' => array_slice($peers, 0, 50),
            'recent' => array_map(fn (array $row): array => [
                'id' => (string) $row['object_id'],
                'author' => (string) ($row['object_author_id'] ?? $row['actor_id']),
                'actor' => (string) $row['actor_id'],
                'domain' => $this->hostOf((string) $row['actor_id']),
                'content' => $this->excerpt((string) $row['content'], 240),
                'published_at' => (string) $row['published_at'],
            ], $recent),
            'blocks' => array_map(fn (array $row): array => [
                'id' => (string) $row['id'],
                'local_user_id' => (string) $row['local_user_id'],
                'actor' => (string) $row['actor_id'],
                'domain' => $this->hostOf((string) $row['actor_id']),
                'blocked_at' => (string) $row['blocked_at'],
            ], $blocks),
        ];
    }

    /**
     * Active actor/domain policies plus the history needed for an audit view.
     *
     * @return array<string, mixed>
     */
    public function moderation(int $limit = 100): array
    {
        $limit = max(1, min(250, $limit));
        $rows = $this->db->fetchAll(
            'SELECT id, kind, target, report_id, moderator_id, note, public_comment, created_at,'
            . ' reversed_at, reversed_by, reversal_note'
            . ' FROM social_moderation_action ORDER BY created_at DESC LIMIT ' . $limit,
        );

        $actions = array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'kind' => (string) $row['kind'],
            'target' => (string) $row['target'],
            'report_id' => $row['report_id'] === null ? null : (string) $row['report_id'],
            'moderator_id' => (string) $row['moderator_id'],
            'note' => $row['note'] === null ? null : (string) $row['note'],
            'public_comment' => $row['public_comment'] === null ? null : (string) $row['public_comment'],
            'created_at' => (string) $row['created_at'],
            'reversed_at' => $row['reversed_at'] === null ? null : (string) $row['reversed_at'],
            'reversed_by' => $row['reversed_by'] === null ? null : (string) $row['reversed_by'],
            'reversal_note' => $row['reversal_note'] === null ? null : (string) $row['reversal_note'],
        ], $rows);

        return ['actions' => $actions, 'active' => count(array_filter($actions, static fn (array $a): bool => $a['reversed_at'] === null))];
    }

    /**
     * @return array<string, mixed>
     */
    public function applyModeration(
        string $adminId,
        string $kind,
        string $target,
        ?string $note = null,
        ?string $publicComment = null,
    ): array {
        $actionKind = ModerationActionKind::tryFrom(strtolower(trim($kind)));
        if ($actionKind === null) {
            throw AdminException::invalid('Unknown moderation action.', [
                'kind' => 'silence_actor, suspend_actor or block_domain.',
            ]);
        }

        $target = trim($target);
        if ($actionKind === ModerationActionKind::BlockDomain) {
            $target = $this->normalizeDomain($target);
        } elseif (preg_match('#^https?://#i', $target) !== 1) {
            throw AdminException::invalid('Enter the actor URL to moderate.', [
                'target' => 'Use an absolute http(s) actor URL.',
            ]);
        }

        $existing = $this->db->fetchValue(
            'SELECT COUNT(*) FROM social_moderation_action WHERE kind = :kind AND target = :target AND reversed_at IS NULL',
            ['kind' => $actionKind->value, 'target' => $target],
        );
        if ((int) ($existing ?? 0) > 0) {
            throw AdminException::conflict('This target already has an active action.');
        }

        $note = $note === null ? null : trim($note);
        if ($note !== null && mb_strlen($note) > ModerationAction::MAX_NOTE_LENGTH) {
            throw AdminException::invalid('The note is too long.', ['note' => 'At most 2000 characters.']);
        }

        $publicComment = $publicComment === null ? null : trim($publicComment);
        if ($publicComment !== null && mb_strlen($publicComment) > ModerationAction::MAX_PUBLIC_COMMENT_LENGTH) {
            throw AdminException::invalid('The public comment is too long.', [
                'public_comment' => 'At most 500 characters; it may be published.',
            ]);
        }

        $action = new ModerationAction(
            id: $this->newId(),
            kind: $actionKind,
            target: $target,
            reportId: null,
            moderatorId: $adminId,
            note: $note === '' ? null : $note,
            createdAt: $this->clock->now(),
            publicComment: $publicComment === '' ? null : $publicComment,
        );
        (new PdoModerationActionRepository($this->db))->insert($action);
        $this->record(
            $adminId,
            'moderation.' . $actionKind->value,
            $actionKind === ModerationActionKind::BlockDomain ? 'domain' : 'actor',
            $target,
            ['note' => $note],
        );

        return $action->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function reverseModeration(string $adminId, string $id, ?string $note = null): array
    {
        $row = $this->db->fetchOne('SELECT * FROM social_moderation_action WHERE id = :id', ['id' => $id]);
        if ($row === null || $row['reversed_at'] !== null) {
            throw AdminException::notFound('This moderation action is not active.');
        }

        $now = Time::format($this->clock->now());
        $this->db->execute(
            'UPDATE social_moderation_action SET reversed_at = :now, reversed_by = :admin, reversal_note = :note WHERE id = :id',
            ['now' => $now, 'admin' => $adminId, 'note' => $note === null || trim($note) === '' ? null : trim($note), 'id' => $id],
        );
        $this->record($adminId, 'moderation.reverse', 'moderation_action', $id, [
            'kind' => (string) $row['kind'],
            'target' => (string) $row['target'],
        ]);

        return [
            'id' => (string) $row['id'],
            'kind' => (string) $row['kind'],
            'target' => (string) $row['target'],
            'reversed_at' => $now,
        ];
    }

    /**
     * Active domain blocks projected for public listing. `note` never leaves
     * the admin surface; only `public_comment` — a reason the moderator
     * explicitly marked publishable — may appear.
     *
     * @return list<array{domain: string, digest: string, severity: string, comment: string}>
     */
    public function domainBlocks(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT target, public_comment FROM social_moderation_action"
            . " WHERE kind = 'block_domain' AND reversed_at IS NULL ORDER BY target",
        );

        return array_map(static fn (array $row): array => [
            'domain' => (string) $row['target'],
            'digest' => hash('sha256', (string) $row['target']),
            'severity' => 'suspend',
            'comment' => (string) ($row['public_comment'] ?? ''),
        ], $rows);
    }

    /**
     * Parses a pasted or uploaded import file into entry rows for
     * {@see importDomainBlocks()}. `lines` is the plain blocklist format —
     * one domain per line with an optional `# comment` suffix. `csv` accepts
     * `domain,comment` rows with an optional header line, quoted cells
     * included. `json` accepts a top-level array of domain strings or
     * `{domain, comment}` objects.
     *
     * @return list<mixed>
     */
    public static function parseDomainBlockImport(string $content, string $format): array
    {
        if (strlen($content) > 1024 * 1024) {
            throw AdminException::invalid('The import file is too large.', [
                'content' => 'Files are limited to 1 MB.',
            ]);
        }

        return match ($format) {
            'lines' => self::parseDomainBlockLines($content),
            'csv' => self::parseDomainBlockCsv($content),
            'json' => self::parseDomainBlockJson($content),
            default => throw AdminException::invalid('Unknown import format.', [
                'format' => 'Use lines, csv or json.',
            ]),
        };
    }

    /**
     * @return list<mixed>
     */
    private static function parseDomainBlockLines(string $content): array
    {
        $entries = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $hash = strpos($line, '#');
            if ($hash === false) {
                $entries[] = $line;

                continue;
            }
            $domain = trim(substr($line, 0, $hash));
            $comment = trim(substr($line, $hash + 1));
            $entries[] = $comment === '' ? $domain : ['domain' => $domain, 'comment' => $comment];
        }

        return $entries;
    }

    /**
     * @return list<mixed>
     */
    private static function parseDomainBlockCsv(string $content): array
    {
        $entries = [];
        $first = true;
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, ',', '"', '');
            if ($first) {
                $first = false;
                if (strtolower(trim((string) ($cells[0] ?? ''))) === 'domain') {
                    continue;
                }
            }
            $domain = trim((string) ($cells[0] ?? ''));
            $comment = trim((string) ($cells[1] ?? ''));
            $entries[] = $comment === '' ? $domain : ['domain' => $domain, 'comment' => $comment];
        }

        return $entries;
    }

    /**
     * @return list<mixed>
     */
    private static function parseDomainBlockJson(string $content): array
    {
        $decoded = json_decode($content, true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw AdminException::invalid('The JSON import must be a top-level array.', [
                'content' => 'Expected a list of domains or {domain, comment} objects.',
            ]);
        }

        return $decoded;
    }

    /**
     * Bulk domain-block import with a mandatory dry run. `apply` is false by
     * default: the caller gets a per-domain verdict first and only repeats
     * the call with apply=true to write. Entries may be bare domains or
     * `{domain, comment}` pairs; invalid, duplicate and already-blocked
     * domains are reported, never silently dropped.
     *
     * @param list<mixed> $entries
     *
     * @return array<string, mixed>
     */
    public function importDomainBlocks(string $adminId, array $entries, bool $apply): array
    {
        if (count($entries) === 0 || count($entries) > 5000) {
            throw AdminException::invalid('The import needs between 1 and 5000 entries.', [
                'entries' => 'Paste up to 5000 domains.',
            ]);
        }

        $seen = [];
        $verdicts = [];
        foreach ($entries as $entry) {
            $raw = is_array($entry) ? ($entry['domain'] ?? null) : $entry;
            $comment = is_array($entry) ? ($entry['comment'] ?? null) : null;
            $verdict = ['input' => is_scalar($raw) ? (string) $raw : '', 'status' => 'invalid', 'domain' => null];

            try {
                $domain = $this->normalizeDomain(is_scalar($raw) ? (string) $raw : '');
                $comment = is_string($comment) ? trim($comment) : null;
                if ($comment !== null && mb_strlen($comment) > ModerationAction::MAX_PUBLIC_COMMENT_LENGTH) {
                    throw AdminException::invalid('The public comment is too long.');
                }
            } catch (AdminException) {
                $verdicts[] = $verdict;

                continue;
            }

            $verdict['domain'] = $domain;
            if (isset($seen[$domain])) {
                $verdict['status'] = 'duplicate';
            } elseif ($this->domainBlockActive($domain)) {
                $verdict['status'] = 'already_blocked';
            } else {
                $seen[$domain] = true;
                $verdict['status'] = 'new';
            }
            $verdict['comment'] = $comment === '' ? null : $comment;
            $verdicts[] = $verdict;
        }

        $counts = ['new' => 0, 'duplicate' => 0, 'already_blocked' => 0, 'invalid' => 0];
        foreach ($verdicts as $verdict) {
            ++$counts[$verdict['status']];
        }

        $applied = 0;
        if ($apply) {
            foreach ($verdicts as $verdict) {
                if ($verdict['status'] !== 'new' || $verdict['domain'] === null) {
                    continue;
                }
                $this->applyModeration(
                    $adminId,
                    ModerationActionKind::BlockDomain->value,
                    $verdict['domain'],
                    null,
                    $verdict['comment'] ?? null,
                );
                ++$applied;
            }
            $this->record($adminId, 'moderation.import', 'domain', null, [
                'entries' => count($entries),
                'applied' => $applied,
            ]);
        }

        return [
            'applied' => $apply,
            'counts' => $counts,
            'applied_count' => $applied,
            'entries' => $verdicts,
        ];
    }

    /**
     * @return array{invitations: list<array<string, mixed>>}
     */
    public function invitations(): array
    {
        return ['invitations' => $this->requireInvitations()->all()];
    }

    /**
     * Issues a registration invitation. The plaintext code is returned once
     * and never stored; only its hash persists.
     *
     * @return array<string, mixed>
     */
    public function createInvitation(string $adminId, int $maxUses, ?int $expiresInDays, ?string $note): array
    {
        try {
            $result = $this->requireInvitations()->create($adminId, $maxUses, $expiresInDays, $note);
        } catch (\InvalidArgumentException $exception) {
            throw AdminException::invalid($exception->getMessage());
        }

        $this->record($adminId, 'invitation.create', 'account_invitation', (string) ($result['invitation']['id'] ?? ''), [
            'max_uses' => $maxUses,
            'expires_in_days' => $expiresInDays,
        ]);

        return $result;
    }

    public function revokeInvitation(string $adminId, string $id): array
    {
        if (!$this->requireInvitations()->revoke($id)) {
            throw AdminException::invalid('This invitation does not exist or is already revoked.', [
                'id' => 'Unknown invitation.',
            ]);
        }

        $this->record($adminId, 'invitation.revoke', 'account_invitation', $id, []);

        return ['revoked' => true];
    }

    private function requireInvitations(): Invitations
    {
        if ($this->invitations === null) {
            throw new \LogicException('Invitations are not wired into this runtime.');
        }

        return $this->invitations;
    }

    /**
     * @return array<string, mixed>
     */
    public function instance(): array
    {
        $worker = $this->workerStatus();
        $core = CoreVersion::details();
        $required = [
            'database' => $this->dbReachable(),
            'blob_directory' => $this->storageAvailable(),
            'secret_key' => $this->config->secretKey !== null || is_readable($this->config->secretKeyFile),
            'worker' => $worker['online'],
        ];

        return [
            'instance' => [
                'name' => $this->config->instanceName,
                'url' => $this->config->instanceUrl,
                'description' => $this->config->instanceDescription,
                'contact' => $this->config->contactEmail,
                'actor_domain' => parse_url($this->config->instanceUrl, PHP_URL_HOST) ?: 'localhost',
            ],
            'versions' => [
                'application' => CoreVersion::current(),
                'core' => CoreVersion::current(),
                'core_reference' => $core['reference'],
                'server_build' => BuildInfo::server(),
                'ui_bundle' => BuildInfo::uiBundle(dirname(__DIR__, 2)),
                'php' => PHP_VERSION,
                'database' => $this->databaseDriver(),
                'environment' => $this->config->debug ? 'development' : 'production',
            ],
            'features' => [
                'federation' => true,
                'remote_media' => $this->config->remoteMedia,
                'mastodon_api' => $this->config->mastodonApi,
                'mastodon_prefix' => $this->config->mastodonPrefix,
                'mail_transport' => $this->config->mailTransport,
                'secure_cookies' => $this->config->secureCookies,
                'atproto' => $this->config->atprotoPublish,
                'atproto_pds' => $this->config->atprotoPds,
                'registrations' => $this->config->registrations,
                'storage_driver' => $this->config->storageDriver,
            ],
            'limits' => [
                'session_lifetime_seconds' => $this->config->sessionLifetimeSeconds,
                'max_post_characters' => 5000,
                'max_attachments' => 4,
                'max_attachment_bytes' => 5 * 1024 * 1024,
            ],
            'paths' => [
                'var' => $this->config->varDirectory,
                'blobs' => $this->config->storageDriver === 'local' ? $this->config->blobDirectory : 's3://' . $this->config->s3Bucket,
                'mail' => $this->config->mailLogDirectory,
                'secret_key' => $this->config->secretKeyFile,
            ],
            'worker' => $worker,
            'checks' => $required,
            'warnings' => $this->configurationWarnings(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function instancePages(): array
    {
        return $this->pages()?->all() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function saveInstancePage(string $adminId, string $slug, mixed $title, mixed $body, mixed $published): array
    {
        $pages = $this->pages();
        if ($pages === null) {
            throw new \LogicException('Instance pages are not configured.');
        }
        $page = $pages->save($adminId, $slug, $title, $body, $published);
        $this->record($adminId, 'page.save', 'instance_page', (string) $page['slug'], [
            'title' => (string) $page['title'],
            'published' => $page['published'],
        ]);

        return $page;
    }

    public function deleteInstancePage(string $adminId, string $slug): void
    {
        $pages = $this->pages();
        if ($pages === null) {
            throw new \LogicException('Instance pages are not configured.');
        }
        $pages->delete($slug);
        $this->record($adminId, 'page.delete', 'instance_page', $slug, []);
    }

    private function pages(): ?InstancePages
    {
        return $this->instancePages;
    }

    /**
     * The operator-facing settings that can be changed at runtime. Changes are
     * written to the persistent env file and take effect on the next request
     * (some deployment settings need a container restart).
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return [
            'core' => [
                'installed' => CoreVersion::current(),
                'reference' => CoreVersion::details()['reference'],
                'channel' => $this->config->coreChannel,
                'version' => $this->config->coreVersion,
                'env_file' => $this->config->envFile,
                'apply_command' => 'php bin/core-version.php',
            ],
            'registrations' => [
                'enabled' => $this->config->registrations,
                'approval' => $this->config->registrationApproval,
            ],
            'atproto' => [
                'publish' => $this->config->atprotoPublish,
                'pds_enabled' => $this->config->atprotoPds,
                'pds_url' => $this->config->atprotoPdsUrl,
                'handle_domain' => $this->config->atprotoPdsHandleDomain,
                'plc_url' => $this->config->atprotoPlcUrl,
            ],
            'instance' => [
                'name' => $this->config->instanceName,
                'description' => $this->config->instanceDescription,
                'contact_email' => $this->config->contactEmail,
            ],
            'moderation' => [
                'publish_domain_blocks' => $this->config->publishDomainBlocks,
            ],
            'jetstream' => $this->config->atprotoJetstreamUrl,
            'restart_required' => false,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function updateSettings(string $adminId, array $input): array
    {
        $bool = static function (mixed $value, bool $default): bool {
            if ($value === null) {
                return $default;
            }

            return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
        };

        $channel = HostConfig::normalizeCoreChannel((string) ($input['core_channel'] ?? $this->config->coreChannel));
        $version = trim((string) ($input['core_version'] ?? $this->config->coreVersion));
        if ($channel === 'release' && $version !== ''
            && preg_match('/^v?\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/', $version) !== 1
        ) {
            throw AdminException::invalid('Use a tagged Core version such as 0.7.0.', [
                'core_version' => 'Expected a release tag like 0.7.0.',
            ]);
        }

        $pdsEnabled = $bool($input['atproto_pds'] ?? null, $this->config->atprotoPds);
        $pdsUrl = rtrim(trim((string) ($input['atproto_pds_url'] ?? $this->config->atprotoPdsUrl)), '/');
        $handleDomain = HostConfig::normalizeHandleDomain((string) ($input['atproto_pds_handle_domain'] ?? $this->config->atprotoPdsHandleDomain));
        $plcUrl = rtrim(trim((string) ($input['atproto_plc_url'] ?? $this->config->atprotoPlcUrl)), '/');

        // The env format is line based: a pasted line break would otherwise
        // corrupt the file when the form is saved.
        $instanceName = trim((string) preg_replace('/\s+/u', ' ', (string) ($input['instance_name'] ?? $this->config->instanceName)));
        if ($instanceName === '' || mb_strlen($instanceName) > 80) {
            throw AdminException::invalid('The instance name needs 1 to 80 characters.', [
                'instance_name' => 'Use a short public name.',
            ]);
        }

        $instanceDescription = trim((string) preg_replace('/\s+/u', ' ', (string) ($input['instance_description'] ?? $this->config->instanceDescription)));
        if (mb_strlen($instanceDescription) > 500) {
            throw AdminException::invalid('The description is too long.', [
                'instance_description' => 'At most 500 characters.',
            ]);
        }

        $contactEmail = trim((string) ($input['contact_email'] ?? $this->config->contactEmail));
        if ($contactEmail !== '' && preg_match('/^[^@\s]+@[^@\s]+$/', $contactEmail) !== 1) {
            throw AdminException::invalid('The contact address is not an email address.', [
                'contact_email' => 'Use an address such as admin@example.org.',
            ]);
        }

        $jetstream = trim((string) ($input['atproto_jetstream'] ?? $this->config->atprotoJetstreamUrl));
        if ($jetstream !== '' && preg_match('#^wss?://[^/]+#i', $jetstream) !== 1) {
            throw AdminException::invalid('The Jetstream relay must be a ws(s) URL.', [
                'atproto_jetstream' => 'Use wss://jetstream2.us-east.bsky.network or leave it empty.',
            ]);
        }

        if ($pdsUrl === '' || preg_match('#^https?://[^/]+#', $pdsUrl) !== 1) {
            throw AdminException::invalid('The PDS URL must be an absolute http(s) URL.', [
                'atproto_pds_url' => 'Use an origin such as https://social.example.',
            ]);
        }
        if ($pdsEnabled && $handleDomain === '') {
            throw AdminException::invalid('A handle domain is required for the native PDS.', [
                'atproto_pds_handle_domain' => 'Use the domain under which member handles are issued.',
            ]);
        }
        if ($pdsEnabled && parse_url($pdsUrl, PHP_URL_SCHEME) !== 'https') {
            throw AdminException::invalid('The native PDS needs a public HTTPS URL.', [
                'atproto_pds_url' => 'Use an https origin such as https://social.example.',
            ]);
        }
        if ($pdsEnabled && parse_url($plcUrl, PHP_URL_SCHEME) !== 'https') {
            throw AdminException::invalid('The PLC directory must use HTTPS.', [
                'atproto_plc_url' => 'Use https://plc.directory unless you run a private directory.',
            ]);
        }
        if (preg_match('#^https?://[^/]+#', $plcUrl) !== 1) {
            throw AdminException::invalid('The PLC directory URL must be an absolute http(s) URL.', [
                'atproto_plc_url' => 'Use https://plc.directory unless you run a private directory.',
            ]);
        }

        EnvFile::set($this->config->envFile, [
            'ALOHASOCIAL_REGISTRATIONS' => $bool($input['registrations'] ?? null, $this->config->registrations) ? 'true' : 'false',
            'ALOHASOCIAL_REGISTRATION_APPROVAL' => $bool($input['registration_approval'] ?? null, $this->config->registrationApproval) ? 'true' : 'false',
            'ALOHASOCIAL_CORE_CHANNEL' => $channel,
            'ALOHASOCIAL_CORE_VERSION' => $version,
            'ALOHASOCIAL_ATPROTO_PDS' => $pdsEnabled ? 'true' : 'false',
            'ALOHASOCIAL_ATPROTO_PDS_URL' => $pdsUrl,
            'ALOHASOCIAL_ATPROTO_PDS_HANDLE_DOMAIN' => $handleDomain,
            'ALOHASOCIAL_ATPROTO_PLC_URL' => $plcUrl,
            'ALOHASOCIAL_INSTANCE_NAME' => $instanceName,
            'ALOHASOCIAL_INSTANCE_DESCRIPTION' => $instanceDescription,
            'ALOHASOCIAL_CONTACT_EMAIL' => $contactEmail,
            'ALOHASOCIAL_ATPROTO_JETSTREAM' => $jetstream,
            'ALOHASOCIAL_PUBLISH_DOMAIN_BLOCKS' => $bool($input['publish_domain_blocks'] ?? null, $this->config->publishDomainBlocks) ? 'true' : 'false',
        ]);

        $registrations = $bool($input['registrations'] ?? null, $this->config->registrations);
        $approval = $bool($input['registration_approval'] ?? null, $this->config->registrationApproval);

        $this->record($adminId, 'settings.update', 'instance', null, [
            'core_channel' => $channel,
            'core_version' => $version,
            'registrations' => $registrations,
            'atproto_pds' => $pdsEnabled,
        ]);

        $settings = $this->settings();
        $settings['core']['channel'] = $channel;
        $settings['core']['version'] = $version;
        $settings['registrations']['enabled'] = $registrations;
        $settings['registrations']['approval'] = $approval;
        $settings['atproto']['pds_enabled'] = $pdsEnabled;
        $settings['atproto']['pds_url'] = $pdsUrl;
        $settings['atproto']['handle_domain'] = $handleDomain;
        $settings['atproto']['plc_url'] = $plcUrl;
        $settings['instance']['name'] = $instanceName;
        $settings['instance']['description'] = $instanceDescription;
        $settings['instance']['contact_email'] = $contactEmail;
        $settings['moderation']['publish_domain_blocks'] = $bool(
            $input['publish_domain_blocks'] ?? null,
            $this->config->publishDomainBlocks,
        );
        $settings['jetstream'] = $jetstream;
        $settings['restart_required'] = true;

        return $settings;
    }

    /**
     * Recent background and communication failures, plus dead delivery jobs.
     *
     * @return array<string, mixed>
     */
    public function communications(int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $cutoff = Time::format($this->clock->now()->modify('-24 hours'));

        $rows = $this->db->fetchAll(
            'SELECT * FROM runtime_error ORDER BY created_at DESC, id DESC LIMIT ' . $limit,
        );
        $dead = $this->db->fetchAll(
            'SELECT * FROM social_job WHERE dead_at IS NOT NULL ORDER BY dead_at DESC, id DESC LIMIT 25',
        );

        return [
            'errors' => array_map(fn (array $row): array => [
                'id' => (string) $row['id'],
                'level' => (string) $row['level'],
                'source' => $row['source'] === null ? null : LogRedactor::text((string) $row['source'], 120),
                'message' => LogRedactor::text((string) $row['message'], 1000),
                'context' => LogRedactor::contextArray($this->decodeDetails($row['context'])),
                'created_at' => (string) $row['created_at'],
            ], $rows),
            'counts' => [
                'last_24h' => $this->intValue('SELECT COUNT(*) FROM runtime_error WHERE created_at >= :cutoff', ['cutoff' => $cutoff]),
                'warnings' => $this->intValue("SELECT COUNT(*) FROM runtime_error WHERE level = 'warning'"),
                'failures' => $this->intValue(
                    "SELECT COUNT(*) FROM runtime_error WHERE level IN ('error', 'critical', 'alert', 'emergency')",
                ),
                'dead_jobs' => $this->intValue('SELECT COUNT(*) FROM social_job WHERE dead_at IS NOT NULL'),
                'mail_outbox_pending' => $this->intValue("SELECT COUNT(*) FROM mail_outbox WHERE status = 'pending'"),
                'mail_outbox_failed' => $this->intValue("SELECT COUNT(*) FROM mail_outbox WHERE status = 'failed'"),
            ],
            'dead_jobs' => array_map(fn (array $row): array => $this->jobRow($row), $dead),
        ];
    }

    /**
     * Send a diagnostic mail to the calling administrator's own address.
     *
     * This bypasses the durable outbox on purpose: the operator needs the
     * transport's answer now, not after the next worker tick. Failures are
     * reported as a sanitized error class — never the recipient list or
     * message body.
     *
     * @return array{sent: bool, transport: string, error?: string}
     */
    public function sendTestMail(string $adminId): array
    {
        if ($this->mailer === null) {
            throw AdminException::invalid('Mail is not configured on this host.');
        }

        $account = $this->accounts->find($adminId);
        if ($account === null || $account->isDisabled()) {
            throw AdminException::notFound('This account was not found.');
        }

        $result = ['sent' => false, 'transport' => $this->config->mailTransport];
        try {
            $this->mailer->send(
                $account->email,
                'Aloha Social mail test',
                "This is a test message from " . $this->config->instanceName . " (" . $this->config->instanceUrl . ").\n"
                . "If you received it, the configured mail transport works.\n",
            );
            $result['sent'] = true;
        } catch (MailException $exception) {
            $result['error'] = substr(get_class($exception), (int) strrpos(get_class($exception), '\\') + 1) . ': send failed';
        }

        $this->record($adminId, 'mail.test', 'account', $adminId, [
            'sent' => $result['sent'],
            'transport' => $this->config->mailTransport,
        ]);

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function audit(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $rows = $this->db->fetchAll(
            'SELECT * FROM admin_audit ORDER BY created_at DESC, id DESC LIMIT ' . $limit,
        );

        return array_map(fn (array $row): array => [
            'id' => (string) $row['id'],
            'actor_id' => (string) $row['actor_id'],
            'action' => (string) $row['action'],
            'target_type' => $row['target_type'] === null ? null : (string) $row['target_type'],
            'target_id' => $row['target_id'] === null ? null : (string) $row['target_id'],
            'details' => $this->decodeDetails($row['details']),
            'created_at' => (string) $row['created_at'],
        ], $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function user(string $id): array
    {
        $row = $this->db->fetchOne(
            'SELECT a.id, a.handle, a.email, a.pending_email, a.email_verified_at, a.role, a.display_name, a.created_at, a.disabled_at,'
            . ' (SELECT COUNT(*) FROM social_post p WHERE p.author_id = a.id AND p.deleted_at IS NULL) AS post_count,'
            . ' (SELECT COUNT(*) FROM social_follower f WHERE f.local_user_id = a.id) AS follower_count,'
            . ' (SELECT COUNT(*) FROM social_following g WHERE g.local_user_id = a.id) AS following_count'
            . ' FROM account a WHERE a.id = :id',
            ['id' => $id],
        );
        if ($row === null) {
            throw AdminException::notFound('This account does not exist.');
        }

        return $this->userRow($row);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function userRow(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'handle' => (string) $row['handle'],
            'email' => (string) $row['email'],
            'pending_email' => $row['pending_email'] === null ? null : (string) $row['pending_email'],
            'email_verified_at' => $row['email_verified_at'] === null ? null : (string) $row['email_verified_at'],
            'role' => (string) $row['role'],
            'display_name' => $row['display_name'] === null ? null : (string) $row['display_name'],
            'created_at' => (string) $row['created_at'],
            'disabled_at' => $row['disabled_at'] === null ? null : (string) $row['disabled_at'],
            'posts' => (int) ($row['post_count'] ?? 0),
            'followers' => (int) ($row['follower_count'] ?? 0),
            'following' => (int) ($row['following_count'] ?? 0),
        ];
    }

    private function requireUser(string $id): Account
    {
        $account = $this->accounts->find($id);
        if ($account === null) {
            throw AdminException::notFound('This account does not exist.');
        }

        return $account;
    }

    private function activeAdminCount(): int
    {
        return $this->accounts->activeAdminCount();
    }

    /**
     * Normalizes a domain-block target to a bare lowercase host. Throws the
     * same validation error applyModeration reports for a bad domain.
     */
    private function normalizeDomain(string $target): string
    {
        $host = parse_url(str_contains($target, '://') ? $target : 'https://' . $target, PHP_URL_HOST);
        $domain = is_string($host) ? strtolower($host) : '';
        if ($domain === '' || preg_match('/^[a-z0-9.-]+$/', $domain) !== 1) {
            throw AdminException::invalid('Enter a valid domain.', ['target' => 'For example example.com.']);
        }

        return $domain;
    }

    private function domainBlockActive(string $domain): bool
    {
        return (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM social_moderation_action WHERE kind = 'block_domain'"
            . ' AND target = :target AND reversed_at IS NULL',
            ['target' => $domain],
        ) > 0;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function reportRow(array $row): array
    {
        $target = $this->reportTarget((string) $row['target_type'], (string) $row['target_id']);

        return [
            'id' => (string) $row['id'],
            'reporter_id' => (string) $row['reporter_id'],
            'reporter_name' => $row['reporter_name'] === null ? (string) $row['reporter_id'] : (string) $row['reporter_name'],
            'reporter_handle' => $row['reporter_handle'] === null ? (string) $row['reporter_id'] : (string) $row['reporter_handle'],
            'target_type' => (string) $row['target_type'],
            'target_id' => (string) $row['target_id'],
            'target' => $target,
            'category' => (string) $row['category'],
            'comment' => $row['comment'] === null ? null : (string) $row['comment'],
            'state' => (string) $row['state'],
            'resolution_note' => $row['resolution_note'] === null ? null : (string) $row['resolution_note'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'resolved_at' => $row['resolved_at'] === null ? null : (string) $row['resolved_at'],
            'resolved_by' => $row['resolved_by'] === null ? null : (string) $row['resolved_by'],
            'assigned_to' => $row['assigned_to'] === null ? null : (string) $row['assigned_to'],
            'assigned_at' => $row['assigned_at'] === null ? null : (string) $row['assigned_at'],
            'assignee_name' => $row['assignee_name'] === null ? null : (string) $row['assignee_name'],
            'assignee_handle' => $row['assignee_handle'] === null ? null : (string) $row['assignee_handle'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function report(string $id): array
    {
        $row = $this->db->fetchOne(
            'SELECT r.*, a.display_name AS reporter_name, a.handle AS reporter_handle,'
            . ' a.disabled_at AS reporter_disabled_at,'
            . ' admin.display_name AS assignee_name, admin.handle AS assignee_handle'
            . ' FROM moderation_report r'
            . ' LEFT JOIN account a ON a.id = r.reporter_id'
            . ' LEFT JOIN account admin ON admin.id = r.assigned_to WHERE r.id = :id',
            ['id' => $id],
        );
        if ($row === null) {
            throw AdminException::notFound('This report does not exist.');
        }

        return $this->reportRow($row);
    }

    /**
     * @return array<string, mixed>
     */
    private function reportTarget(string $type, string $id): array
    {
        if ($type === 'post') {
            $local = $this->db->fetchOne(
                'SELECT id, author_id, content, visibility, published_at FROM social_post'
                . ' WHERE id = :id AND deleted_at IS NULL',
                ['id' => $id],
            );
            if ($local !== null) {
                return [
                    'type' => 'post',
                    'id' => (string) $local['id'],
                    'exists' => true,
                    'scope' => 'local',
                    'author' => (string) $local['author_id'],
                    'author_name' => $this->localName((string) $local['author_id']),
                    'content' => $this->excerpt((string) $local['content'], 400),
                    'published_at' => (string) $local['published_at'],
                    'href' => '#/p/' . rawurlencode((string) $local['id']),
                ];
            }

            $remote = $this->db->fetchOne(
                'SELECT object_id, object_author_id, actor_id, content, published_at FROM social_remote_post'
                . ' WHERE object_id = :id AND deleted_at IS NULL LIMIT 1',
                ['id' => $id],
            );
            if ($remote !== null) {
                return [
                    'type' => 'post',
                    'id' => (string) $remote['object_id'],
                    'exists' => true,
                    'scope' => 'remote',
                    'author' => (string) ($remote['object_author_id'] ?? $remote['actor_id']),
                    'author_name' => (string) ($remote['object_author_id'] ?? $remote['actor_id']),
                    'content' => $this->excerpt((string) $remote['content'], 400),
                    'published_at' => (string) $remote['published_at'],
                    'href' => (string) $remote['object_id'],
                ];
            }

            return $this->isRemoteReference($id)
                ? [
                    'type' => 'post',
                    'id' => $id,
                    'exists' => true,
                    'scope' => 'remote',
                    'author' => $id,
                    'author_name' => $id,
                    'content' => null,
                    'published_at' => null,
                    'href' => str_starts_with($id, 'http') ? $id : null,
                ]
                : ['type' => 'post', 'id' => $id, 'exists' => false, 'scope' => 'unknown'];
        }

        $local = $this->accounts->find($id);
        if ($local !== null) {
            return [
                'type' => 'account',
                'id' => $local->id,
                'exists' => true,
                'scope' => 'local',
                'author' => $local->id,
                'author_name' => $local->name(),
                'content' => null,
                'published_at' => $local->createdAt,
                'href' => '#/u/' . rawurlencode($local->id),
                'disabled' => $local->isDisabled(),
            ];
        }

        return [
            'type' => 'account',
            'id' => $id,
            'exists' => $this->isRemoteReference($id),
            'scope' => $this->isRemoteReference($id) ? 'remote' : 'unknown',
            'author' => $id,
            'author_name' => $id,
            'content' => null,
            'published_at' => null,
            'href' => str_starts_with($id, 'http') ? $id : null,
        ];
    }

    /**
     * Remote posts and profiles are read live and may not be in the cache yet.
     * A report about one is still valid: it names the remote object by its
     * canonical URL or AT URI, and the moderator can open it in place.
     */
    private function isRemoteReference(string $id): bool
    {
        return str_starts_with($id, 'http://')
            || str_starts_with($id, 'https://')
            || str_starts_with($id, 'at://')
            || str_starts_with($id, 'did:');
    }

    private function postExists(string $id): bool
    {
        $local = $this->db->fetchValue(
            'SELECT COUNT(*) FROM social_post WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id],
        );
        if ((int) ($local ?? 0) > 0) {
            return true;
        }

        $remote = $this->db->fetchValue(
            'SELECT COUNT(*) FROM social_remote_post WHERE object_id = :id AND deleted_at IS NULL',
            ['id' => $id],
        );

        return (int) ($remote ?? 0) > 0;
    }

    private function accountTargetExists(string $id): bool
    {
        return $this->accounts->find($id) !== null || str_starts_with($id, 'http');
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function jobRow(array $row): array
    {
        $state = 'pending';
        if ($row['dead_at'] !== null) {
            $state = 'dead';
        } elseif ($row['reserved_at'] !== null) {
            $state = 'running';
        }

        return [
            'id' => (string) $row['id'],
            'type' => (string) $row['type'],
            'state' => $state,
            'attempts' => (int) $row['attempts'],
            'available_at' => (string) $row['available_at'],
            'reserved_at' => $row['reserved_at'] === null ? null : (string) $row['reserved_at'],
            'dead_at' => $row['dead_at'] === null ? null : (string) $row['dead_at'],
            'last_error' => ($row['last_error'] ?? null) === null ? null : (string) $row['last_error'],
            'retryable' => $row['dead_at'] !== null,
            'payload' => $this->decodeDetails($row['payload']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function job(string $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM social_job WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            throw AdminException::notFound('This job does not exist.');
        }

        return $this->jobRow($row);
    }

    /**
     * @return array<string, mixed>
     */
    private function queueCounts(): array
    {
        $oldest = $this->db->fetchValue(
            'SELECT MIN(available_at) FROM social_job WHERE dead_at IS NULL AND reserved_at IS NULL',
        );

        return [
            'pending' => $this->intValue('SELECT COUNT(*) FROM social_job WHERE dead_at IS NULL AND reserved_at IS NULL'),
            'running' => $this->intValue('SELECT COUNT(*) FROM social_job WHERE dead_at IS NULL AND reserved_at IS NOT NULL'),
            'dead' => $this->intValue('SELECT COUNT(*) FROM social_job WHERE dead_at IS NOT NULL'),
            'total' => $this->intValue('SELECT COUNT(*) FROM social_job'),
            'oldest_pending' => $oldest === null ? null : (string) $oldest,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduledCounts(): array
    {
        return [
            'pending' => $this->intValue("SELECT COUNT(*) FROM social_scheduled_post WHERE state IN ('scheduled', 'publishing')"),
            'published' => $this->intValue("SELECT COUNT(*) FROM social_scheduled_post WHERE state = 'published'"),
            'failed' => $this->intValue("SELECT COUNT(*) FROM social_scheduled_post WHERE state = 'failed'"),
            'cancelled' => $this->intValue("SELECT COUNT(*) FROM social_scheduled_post WHERE state = 'cancelled'"),
        ];
    }

    /**
     * @param array<string, mixed> $stats
     *
     * @return list<array<string, string>>
     */
    private function health(array $stats): array
    {
        $worker = $this->workerStatus();
        $queue = is_array($stats['queue'] ?? null) ? $stats['queue'] : [];
        $dead = (int) ($queue['dead'] ?? 0);

        $storageWritable = $this->storageAvailable();
        $freeRatio = $this->config->storageDriver === 'local'
            ? $this->freeDiskRatio($this->config->blobDirectory)
            : null;

        $adminsWithoutMfa = (int) ($stats['accounts']['admins_without_mfa'] ?? 0);
        $schema = $this->schemaStatus();
        $tasks = $this->workerTasks();
        $staleTasks = [];
        foreach ($tasks as $task) {
            if ($task['stale']) {
                $staleTasks[] = (string) $task['task'];
            }
        }

        return [
            [
                'id' => 'database',
                'label' => 'Database',
                'status' => $this->dbReachable() ? 'ok' : 'error',
                'detail' => $this->databaseDriver(),
            ],
            [
                'id' => 'schema',
                'label' => 'Database schema',
                'status' => $schema['status'],
                'detail' => $schema['detail'],
            ],
            [
                'id' => 'worker',
                'label' => 'Worker',
                'status' => $worker['online'] ? 'ok' : 'warn',
                'detail' => $worker['online']
                    ? 'Last heartbeat ' . $worker['last_beat']
                    : 'No recent heartbeat',
            ],
            [
                'id' => 'background-tasks',
                'label' => 'Background tasks',
                'status' => $staleTasks === [] ? 'ok' : 'warn',
                'detail' => $staleTasks === []
                    ? ($tasks === [] ? 'No task reports yet' : count($tasks) . ' pipeline(s) reporting')
                    : 'Stale: ' . implode(', ', $staleTasks),
            ],
            [
                'id' => 'queue',
                'label' => 'Job queue',
                'status' => $dead > 0 ? 'warn' : 'ok',
                'detail' => $dead > 0 ? $dead . ' failed jobs' : 'No failed jobs',
            ],
            [
                'id' => 'errors',
                'label' => 'Delivery and federation errors',
                'status' => (int) ($stats['errors']['failures'] ?? 0) > 0
                    ? 'error'
                    : ((int) ($stats['errors']['warnings'] ?? 0) > 0 ? 'warn' : 'ok'),
                'detail' => (int) ($stats['errors']['recent'] ?? 0) === 0
                    ? 'No errors in the last 24 hours'
                    : (int) ($stats['errors']['recent'] ?? 0) . ' logged in the last 24 hours',
            ],
            [
                'id' => 'storage',
                'label' => 'Media storage',
                'status' => $storageWritable ? ($freeRatio !== null && $freeRatio < 0.1 ? 'warn' : 'ok') : 'error',
                'detail' => $storageWritable
                    ? ($this->config->storageDriver === 's3'
                        ? 'S3-compatible bucket reachable'
                        : ($freeRatio === null ? 'Writable' : sprintf('Writable · %.0f%% free', $freeRatio * 100)))
                    : ($this->config->storageDriver === 's3' ? 'S3-compatible bucket unreachable' : 'Not writable'),
            ],
            [
                'id' => 'mail',
                'label' => 'E-Mail',
                'status' => $this->config->mailTransport === 'smtp' ? 'ok' : 'warn',
                'detail' => $this->config->mailTransport === 'smtp'
                    ? 'SMTP configured'
                    : 'Log transport: emails are only written to files',
            ],
            [
                'id' => 'admin-mfa',
                'label' => 'Admin two-factor sign-in',
                'status' => $adminsWithoutMfa > 0 ? 'warn' : 'ok',
                'detail' => $adminsWithoutMfa === 0
                    ? 'Every admin uses two-factor sign-in'
                    : $adminsWithoutMfa . ' admin account(s) without two-factor sign-in',
            ],
            [
                'id' => 'https',
                'label' => 'TLS',
                'status' => $this->httpsStatus(),
                'detail' => str_starts_with($this->config->instanceUrl, 'https://')
                    ? 'HTTPS active'
                    : (in_array(parse_url($this->config->instanceUrl, PHP_URL_HOST), ['localhost', '127.0.0.1', '::1'], true)
                        ? 'Local development over HTTP'
                        : 'Public instance without HTTPS'),
            ],
            [
                'id' => 'debug',
                'label' => 'Debug mode',
                'status' => $this->config->debug ? 'warn' : 'ok',
                'detail' => $this->config->debug ? 'Error details are exposed' : 'Disabled',
            ],
        ];
    }

    /**
     * Pending-migration and fingerprint state for the health grid. Versions
     * are operator-facing here (admin-only), unlike the public boot page.
     *
     * @return array{status: string, detail: string}
     */
    private function schemaStatus(): array
    {
        try {
            $all = Migrations::all();
            $applied = (new MigrationRunner($this->db, new SystemClock(), $all))->applied();
            $pending = count(array_diff(
                array_map(static fn ($migration): string => $migration->version(), $all),
                array_keys($applied),
            ));

            CoreSchemaFingerprint::assertCurrent($this->db);
        } catch (Throwable) {
            return ['status' => 'error', 'detail' => 'Schema check failed — run php bin/update.php'];
        }

        if ($pending > 0) {
            return ['status' => 'warn', 'detail' => $pending . ' pending — run php bin/update.php'];
        }

        $latest = $applied === [] ? 'none' : (string) max(array_keys($applied));

        return ['status' => 'ok', 'detail' => count($applied) . ' applied · latest ' . $latest];
    }

    /**
     * @return array<string, mixed>
     */
    private function workerStatus(): array
    {
        $row = $this->db->fetchOne(
            'SELECT value, updated_at FROM runtime_state WHERE name = :name',
            ['name' => self::WORKER_HEARTBEAT],
        );
        if ($row === null) {
            return ['online' => false, 'last_beat' => null, 'updated_at' => null, 'age_seconds' => null];
        }

        $timestamp = (int) $row['value'];
        $age = time() - $timestamp;

        return [
            'online' => $age <= 90,
            'last_beat' => $this->relativeSeconds($age),
            'updated_at' => (string) $row['updated_at'],
            'age_seconds' => $age,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentUsers(int $limit): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, handle, email, pending_email, email_verified_at, role, display_name, created_at, disabled_at,'
            . ' (SELECT COUNT(*) FROM social_post p WHERE p.author_id = account.id AND p.deleted_at IS NULL) AS post_count,'
            . ' (SELECT COUNT(*) FROM social_follower f WHERE f.local_user_id = account.id) AS follower_count,'
            . ' (SELECT COUNT(*) FROM social_following g WHERE g.local_user_id = account.id) AS following_count'
            . ' FROM account ORDER BY created_at DESC LIMIT ' . max(1, $limit),
        );

        return array_map(fn (array $row): array => $this->userRow($row), $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentPosts(int $limit): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, author_id, content, visibility, in_reply_to_id, in_reply_to_remote_object_id, published_at FROM social_post'
            . ' WHERE deleted_at IS NULL ORDER BY published_at DESC LIMIT ' . max(1, $limit),
        );

        return array_map(fn (array $row): array => [
            'id' => (string) $row['id'],
            'author' => (string) $row['author_id'],
            'author_name' => $this->localName((string) $row['author_id']),
            'content' => $this->excerpt((string) $row['content'], 220),
            'visibility' => (string) $row['visibility'],
            'reply' => $row['in_reply_to_id'] !== null || $row['in_reply_to_remote_object_id'] !== null,
            'published_at' => (string) $row['published_at'],
        ], $rows);
    }

    /**
     * @return list<array{domain: string, posts: int, actors: int}>
     */
    private function peerDomains(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT actor_id, COUNT(*) AS posts FROM social_remote_post WHERE deleted_at IS NULL GROUP BY actor_id LIMIT 20000',
        );

        $domains = [];
        foreach ($rows as $row) {
            $domain = $this->hostOf((string) $row['actor_id']);
            if ($domain === '') {
                continue;
            }

            $domains[$domain] ??= ['domain' => $domain, 'posts' => 0, 'actors' => 0];
            $domains[$domain]['posts'] += (int) $row['posts'];
            $domains[$domain]['actors']++;
        }

        $values = array_values($domains);
        usort($values, static fn (array $left, array $right): int => $right['posts'] <=> $left['posts']);

        return $values;
    }

    private function localName(string $id): string
    {
        $account = $this->accounts->find($id);

        return $account?->name() ?? $id;
    }

    private function hostOf(string $actor): string
    {
        $host = parse_url($actor, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : '';
    }

    private function excerpt(string $value, int $length): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return mb_strlen($value) <= $length ? $value : mb_substr($value, 0, $length - 1) . '…';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeDetails(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : ['raw' => mb_substr($value, 0, 500)];
    }

    /**
     * @param array<string, mixed> $details
     */
    private function record(string $actorId, string $action, ?string $targetType, ?string $targetId, array $details): void
    {
        $this->db->execute(
            'INSERT INTO admin_audit (id, actor_id, action, target_type, target_id, details, created_at)'
            . ' VALUES (:id, :actor, :action, :target_type, :target_id, :details, :created_at)',
            [
                'id' => $this->newId(),
                'actor' => $actorId,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'details' => $details === [] ? null : json_encode($details, JSON_THROW_ON_ERROR),
                'created_at' => Time::format($this->clock->now()),
            ],
        );
    }

    private function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function intValue(string $sql, array $parameters = []): int
    {
        $value = $this->db->fetchValue($sql, $parameters);

        return $value === null ? 0 : (int) $value;
    }

    private function dbReachable(): bool
    {
        try {
            $this->db->fetchValue('SELECT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function databaseDriver(): string
    {
        $dsn = $this->config->databaseDsn;
        $driver = explode(':', $dsn, 2)[0];

        if ($driver === 'sqlite') {
            return 'SQLite · ' . basename(substr($dsn, 7));
        }

        return strtoupper($driver);
    }

    private function blobCount(): ?int
    {
        if ($this->config->storageDriver === 's3') {
            return null;
        }

        if (!is_dir($this->config->blobDirectory)) {
            return 0;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->config->blobDirectory, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            $count = 0;
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $count++;
                }
            }

            return $count;
        } catch (Throwable) {
            return 0;
        }
    }

    private function blobBytes(): ?int
    {
        if ($this->config->storageDriver === 's3') {
            return null;
        }

        if (!is_dir($this->config->blobDirectory)) {
            return 0;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->config->blobDirectory, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            $bytes = 0;
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $bytes += (int) $file->getSize();
                }
            }

            return $bytes;
        } catch (Throwable) {
            return 0;
        }
    }

    private function freeDiskRatio(string $path): ?float
    {
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        if ($free === false || $total === false || $total <= 0) {
            return null;
        }

        return $free / $total;
    }

    private function storageAvailable(): bool
    {
        if ($this->config->storageDriver === 'local') {
            return is_dir($this->config->blobDirectory) && is_writable($this->config->blobDirectory);
        }

        if ($this->s3Client === null || $this->config->s3Bucket === null) {
            return false;
        }

        try {
            $prefix = $this->config->s3Prefix === '' ? '' : $this->config->s3Prefix . '/';
            $this->s3Client->listObjects($this->config->s3Bucket, $prefix, null, 1);

            return true;
        } catch (Throwable) {
            // Avoid returning SDK error details which could contain endpoint or
            // credential-provider information in an admin response.
            return false;
        }
    }

    private function httpsStatus(): string
    {
        if (str_starts_with($this->config->instanceUrl, 'https://')) {
            return 'ok';
        }

        $host = parse_url($this->config->instanceUrl, PHP_URL_HOST);

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true) ? 'ok' : 'warn';
    }

    /**
     * @return list<string>
     */
    private function configurationWarnings(): array
    {
        $warnings = [];
        if ($this->config->debug) {
            $warnings[] = 'Debug mode is active and exposes internal error details.';
        }
        if (!str_starts_with($this->config->instanceUrl, 'https://')
            && !in_array(parse_url($this->config->instanceUrl, PHP_URL_HOST), ['localhost', '127.0.0.1', '::1'], true)
        ) {
            $warnings[] = 'The instance runs over HTTP. Federation and sign-in should use HTTPS.';
        }
        if ($this->config->mailTransport !== 'smtp') {
            $warnings[] = 'With the log transport, emails are only written to files.';
        }
        if ($this->config->contactEmail === '') {
            $warnings[] = 'No contact address is configured for the instance.';
        }
        if ($this->config->instanceDescription === '') {
            $warnings[] = 'The instance description is empty.';
        }

        return $warnings;
    }

    private function relativeSeconds(int $age): string
    {
        if ($age < 5) {
            return 'just now';
        }
        if ($age < 60) {
            return $age . ' seconds ago';
        }
        if ($age < 3600) {
            return (int) floor($age / 60) . ' minutes ago';
        }

        return (int) floor($age / 3600) . ' hours ago';
    }
}
