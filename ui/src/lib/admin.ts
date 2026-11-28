/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { HostBridge, UiMethod } from '@alohasocial/ui';
import { ApiError } from './api';

type JsonRecord = Record<string, unknown>;

export type AdminSection =
	| 'overview'
	| 'users'
	| 'reports'
	| 'federation'
	| 'communications'
	| 'queue'
	| 'settings'
	| 'announcements'
	| 'audit';

export interface AdminSectionDefinition {
	id: AdminSection;
	label: string;
	icon: string;
	hint: string;
}

export const ADMIN_SECTIONS: AdminSectionDefinition[] = [
	{ id: 'overview', label: 'Overview', icon: 'activity', hint: 'Metrics, health and recent activity' },
	{ id: 'users', label: 'Accounts', icon: 'users', hint: 'Create accounts, manage roles and access' },
	{ id: 'reports', label: 'Reports', icon: 'flag', hint: 'Review reported posts and profiles' },
	{ id: 'federation', label: 'Federation', icon: 'globe', hint: 'Peers, blocks and remote posts' },
	{ id: 'communications', label: 'Errors', icon: 'alert', hint: 'Delivery, federation and background failures' },
	{ id: 'queue', label: 'Queue', icon: 'server', hint: 'Jobs, retries and dead tasks' },
	{ id: 'settings', label: 'Instance', icon: 'settings', hint: 'Runtime, limits and configuration warnings' },
	{ id: 'announcements', label: 'Announcements', icon: 'bell', hint: 'Publish notices as a banner on the home page' },
	{ id: 'audit', label: 'Audit log', icon: 'shield', hint: 'Every administrative change, fully traceable' },
];

const SECTION_IDS = new Set<string>(ADMIN_SECTIONS.map((section) => section.id));

export function isAdminSection(value: string): value is AdminSection {
	return SECTION_IDS.has(value);
}

export function adminSectionDefinition(section: AdminSection): AdminSectionDefinition {
	return ADMIN_SECTIONS.find((entry) => entry.id === section) ?? ADMIN_SECTIONS[0];
}

export type HealthStatus = 'ok' | 'warn' | 'error';

export interface HealthCheck {
	id: string;
	label: string;
	status: HealthStatus;
	detail: string;
}

export interface WorkerStatus {
	online: boolean;
	last_beat: string | null;
	updated_at: string | null;
	age_seconds: number | null;
}

export interface QueueCounts {
	pending: number;
	running: number;
	dead: number;
	total: number;
	oldest_pending: string | null;
}

export interface ScheduledCounts {
	pending: number;
	published: number;
	failed: number;
	cancelled: number;
}

export interface AdminStats {
	accounts: {
		total: number;
		active: number;
		disabled: number;
		admins: number;
		new_7d: number;
	};
	posts: {
		total: number;
		public: number;
		replies: number;
		today: number;
	};
	media: {
		attachments: number;
		bytes: number;
		blobs: number | null;
		blob_bytes: number | null;
		storage_driver: 'local' | 's3' | string;
	};
	federation: {
		remote_posts: number;
		remote_actors: number;
		followers: number;
		following: number;
		blocked_actors: number;
		peer_domains: number;
	};
	notifications: {
		total: number;
		unread: number;
	};
	queue: QueueCounts;
	reports: {
		open: number;
		total: number;
	};
	scheduled: ScheduledCounts;
	errors: {
		recent: number;
		warnings: number;
		failures: number;
	};
}

export interface AdminUser {
	id: string;
	handle: string;
	email: string;
	role: 'admin' | 'user' | string;
	display_name: string | null;
	created_at: string;
	disabled_at: string | null;
	posts: number;
	followers: number;
	following: number;
}

export interface AdminUserPage {
	users: AdminUser[];
	total: number;
	page: number;
	per_page: number;
	pages: number;
}

export interface AdminRecentPost {
	id: string;
	author: string;
	author_name: string;
	content: string;
	visibility: string;
	reply: boolean;
	published_at: string;
}

export interface AuditEntry {
	id: string;
	actor_id: string;
	action: string;
	target_type: string | null;
	target_id: string | null;
	details: Record<string, unknown>;
	created_at: string;
}

export interface AdminOverview {
	generated_at: string;
	stats: AdminStats;
	health: HealthCheck[];
	worker: WorkerStatus;
	recent_users: AdminUser[];
	recent_posts: AdminRecentPost[];
	audit: AuditEntry[];
}

export type ReportCategory = 'spam' | 'abuse' | 'harassment' | 'misinformation' | 'other';
export type ReportState = 'open' | 'resolved' | 'dismissed';

export interface ReportTarget {
	type: 'post' | 'account' | string;
	id: string;
	exists: boolean;
	scope: 'local' | 'remote' | 'unknown' | string;
	author?: string;
	author_name?: string;
	content?: string | null;
	published_at?: string | null;
	href?: string | null;
	disabled?: boolean;
}

export interface AdminReport {
	id: string;
	reporter_id: string;
	reporter_name: string;
	reporter_handle: string;
	target_type: 'post' | 'account' | string;
	target_id: string;
	target: ReportTarget;
	category: ReportCategory | string;
	comment: string | null;
	state: ReportState | string;
	resolution_note: string | null;
	created_at: string;
	updated_at: string;
	resolved_at: string | null;
	resolved_by: string | null;
}

export interface ReportList {
	reports: AdminReport[];
	counts: Record<ReportState, number>;
	state: string;
}

export interface AdminJob {
	id: string;
	type: string;
	state: 'pending' | 'running' | 'dead' | string;
	attempts: number;
	available_at: string;
	reserved_at: string | null;
	dead_at: string | null;
	last_error: string | null;
	retryable: boolean;
	payload: Record<string, unknown>;
}

export interface JobList {
	jobs: AdminJob[];
	counts: QueueCounts;
	state: string;
	error_classes: Record<string, number>;
}

export interface PeerDomain {
	domain: string;
	posts: number;
	actors: number;
}

export interface FederationBlock {
	id: string;
	local_user_id: string;
	actor: string;
	domain: string;
	blocked_at: string;
}

export interface RecentRemotePost {
	id: string;
	author: string;
	actor: string;
	domain: string;
	content: string;
	published_at: string;
}

export interface FederationData {
	counts: {
		remote_posts: number;
		remote_actors: number;
		followers: number;
		following: number;
		blocks: number;
		peers: number;
	};
	peers: PeerDomain[];
	recent: RecentRemotePost[];
	blocks: FederationBlock[];
}

export interface CommunicationError {
	id: string;
	level: 'warning' | 'error' | 'critical' | 'alert' | 'emergency' | string;
	source: string | null;
	message: string;
	context: Record<string, unknown>;
	created_at: string;
}

export interface CommunicationData {
	errors: CommunicationError[];
	counts: {
		last_24h: number;
		warnings: number;
		failures: number;
		dead_jobs: number;
	};
	dead_jobs: AdminJob[];
}

export interface AdminInstance {
	instance: {
		name: string;
		url: string;
		description: string;
		contact: string;
		actor_domain: string;
	};
	versions: {
		application: string;
		core_reference?: string | null;
		php: string;
		database: string;
		environment: string;
	};
	features: Record<string, string | number | boolean>;
	limits: Record<string, string | number | boolean>;
	paths: Record<string, string>;
	worker: WorkerStatus;
	checks: Record<string, boolean>;
	warnings: string[];
}

export interface AdminSettingsData {
	core: {
		installed: string;
		reference?: string | null;
		channel: string;
		version: string;
		env_file: string;
		apply_command: string;
	};
	registrations: { enabled: boolean; approval: boolean };
	atproto: {
		publish: boolean;
		pds_enabled: boolean;
		pds_url: string;
		handle_domain: string;
		plc_url: string;
	};
	instance: { name: string; description: string; contact_email: string };
	jetstream: string;
	restart_required: boolean;
}

export interface AdminSettingsPayload {
	registrations?: boolean;
	registration_approval?: boolean;
	core_channel?: string;
	core_version?: string;
	atproto_pds?: boolean;
	atproto_pds_url?: string;
	atproto_pds_handle_domain?: string;
	atproto_plc_url?: string;
	instance_name?: string;
	instance_description?: string;
	contact_email?: string;
	atproto_jetstream?: string;
}

export interface CreateUserPayload {
	handle: string;
	email: string;
	password: string;
	role: 'admin' | 'user';
	display_name?: string;
}

export interface ReportPayload {
	target_type: 'post' | 'account';
	target_id: string;
	category: ReportCategory;
	comment?: string;
}

export interface AdminApi {
	overview(): Promise<AdminOverview>;
	users(query?: string, page?: number, perPage?: number): Promise<AdminUserPage>;
	createUser(payload: CreateUserPayload): Promise<AdminUser>;
	updateUser(id: string, payload: { display_name?: string; role?: 'admin' | 'user' }): Promise<AdminUser>;
	setUserDisabled(id: string, disabled: boolean): Promise<AdminUser>;
	resetPassword(id: string, password: string): Promise<number>;
	revokeSessions(id: string): Promise<number>;
	reports(state?: 'open' | 'resolved' | 'dismissed' | 'all'): Promise<ReportList>;
	resolveReport(id: string, state: 'resolved' | 'dismissed', note?: string): Promise<AdminReport>;
	deletePost(id: string): Promise<void>;
	jobs(state?: 'all' | 'pending' | 'running' | 'dead'): Promise<JobList>;
	retryJob(id: string): Promise<AdminJob>;
	deleteJob(id: string): Promise<void>;
	retryDeadJobs(): Promise<number>;
	purgeDeadJobs(): Promise<number>;
	runQueue(): Promise<number>;
	federation(): Promise<FederationData>;
	communications(limit?: number): Promise<CommunicationData>;
	instance(): Promise<AdminInstance>;
	settings(): Promise<AdminSettingsData>;
	updateSettings(payload: AdminSettingsPayload): Promise<AdminSettingsData>;
	audit(limit?: number): Promise<AuditEntry[]>;
	createReport(payload: ReportPayload): Promise<AdminReport>;
}

function isRecord(value: unknown): value is JsonRecord {
	return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function errorMessage(body: string, status: number): { code: string; message: string; fields: Record<string, string> } {
	try {
		const parsed: unknown = JSON.parse(body);
		if (isRecord(parsed) && isRecord(parsed.error)) {
			const fields: Record<string, string> = {};
			if (isRecord(parsed.error.fields)) {
				for (const [key, value] of Object.entries(parsed.error.fields)) {
					if (typeof value === 'string') {
						fields[key] = value;
					}
				}
			}

			return {
				code: typeof parsed.error.code === 'string' ? parsed.error.code : 'http_error',
				message: typeof parsed.error.message === 'string' && parsed.error.message !== ''
					? parsed.error.message
					: `The request failed (HTTP ${status}).`,
				fields,
			};
		}
	} catch {
		// The response is not JSON; the generic message below is enough.
	}

	return {
		code: 'http_error',
		message: `The request failed (HTTP ${status}).`,
		fields: {},
	};
}

/**
 * Client for the host-owned administration API.
 *
 * The admin surface is deliberately separate from the social client: these
 * routes need a session with the admin role, and mixing them into the timeline
 * client would make every page depend on privileges it does not have.
 */
export function createAdminApi(bridge: HostBridge): AdminApi {
	async function request<T>(
		method: UiMethod,
		path: string,
		query?: Record<string, string>,
		body?: unknown,
	): Promise<T> {
		const response = await bridge.request({ method, path, query, body });
		let parsed: unknown = undefined;

		if (response.body.trim() !== '') {
			try {
				parsed = JSON.parse(response.body);
			} catch {
				parsed = response.body;
			}
		}

		if (response.status < 200 || response.status >= 300) {
			const error = errorMessage(response.body, response.status);
			throw new ApiError(response.status, error.code, error.message, error.fields);
		}

		return parsed as T;
	}

	return {
		async overview(): Promise<AdminOverview> {
			return request<AdminOverview>('GET', '/api/v1/host/admin/overview');
		},

		async users(query = '', page = 1, perPage = 20): Promise<AdminUserPage> {
			return request<AdminUserPage>('GET', '/api/v1/host/admin/users', {
				query,
				page: String(page),
				per_page: String(perPage),
			});
		},

		async createUser(payload: CreateUserPayload): Promise<AdminUser> {
			const data = await request<{ user: AdminUser }>('POST', '/api/v1/host/admin/users', undefined, payload);

			return data.user;
		},

		async updateUser(id, payload): Promise<AdminUser> {
			const data = await request<{ user: AdminUser }>(
				'PATCH',
				`/api/v1/host/admin/users/${encodeURIComponent(id)}`,
				undefined,
				payload,
			);

			return data.user;
		},

		async setUserDisabled(id, disabled): Promise<AdminUser> {
			const data = await request<{ user: AdminUser }>(
				'POST',
				`/api/v1/host/admin/users/${encodeURIComponent(id)}/${disabled ? 'disable' : 'enable'}`,
			);

			return data.user;
		},

		async resetPassword(id, password): Promise<number> {
			const data = await request<{ revoked_sessions: number }>(
				'POST',
				`/api/v1/host/admin/users/${encodeURIComponent(id)}/password`,
				undefined,
				{ password },
			);

			return data.revoked_sessions;
		},

		async revokeSessions(id): Promise<number> {
			const data = await request<{ revoked_sessions: number }>(
				'POST',
				`/api/v1/host/admin/users/${encodeURIComponent(id)}/sessions`,
			);

			return data.revoked_sessions;
		},

		async reports(state = 'open'): Promise<ReportList> {
			return request<ReportList>('GET', '/api/v1/host/admin/reports', { state });
		},

		async resolveReport(id, state, note): Promise<AdminReport> {
			const data = await request<{ report: AdminReport }>(
				'POST',
				`/api/v1/host/admin/reports/${encodeURIComponent(id)}/resolve`,
				undefined,
				{ state, note: note ?? '' },
			);

			return data.report;
		},

		async deletePost(id): Promise<void> {
			await request<unknown>('DELETE', `/api/v1/host/admin/posts/${encodeURIComponent(id)}`);
		},

		async jobs(state = 'all'): Promise<JobList> {
			return request<JobList>('GET', '/api/v1/host/admin/jobs', { state });
		},

		async retryJob(id): Promise<AdminJob> {
			const data = await request<{ job: AdminJob }>(
				'POST',
				`/api/v1/host/admin/jobs/${encodeURIComponent(id)}/retry`,
			);

			return data.job;
		},

		async deleteJob(id): Promise<void> {
			await request<unknown>('DELETE', `/api/v1/host/admin/jobs/${encodeURIComponent(id)}`);
		},

		async retryDeadJobs(): Promise<number> {
			const data = await request<{ retried: number }>('POST', '/api/v1/host/admin/jobs/retry-dead');

			return data.retried;
		},

		async purgeDeadJobs(): Promise<number> {
			const data = await request<{ purged: number }>('POST', '/api/v1/host/admin/jobs/purge');

			return data.purged;
		},

		async runQueue(): Promise<number> {
			const data = await request<{ handled: number }>('POST', '/api/v1/host/admin/queue/run');

			return data.handled;
		},

		async federation(): Promise<FederationData> {
			return request<FederationData>('GET', '/api/v1/host/admin/federation');
		},

		async communications(limit = 100): Promise<CommunicationData> {
			return request<CommunicationData>('GET', '/api/v1/host/admin/communications', {
				limit: String(limit),
			});
		},

		async instance(): Promise<AdminInstance> {
			return request<AdminInstance>('GET', '/api/v1/host/admin/instance');
		},

		async settings(): Promise<AdminSettingsData> {
			return request<AdminSettingsData>('GET', '/api/v1/host/admin/settings');
		},

		async updateSettings(payload: AdminSettingsPayload): Promise<AdminSettingsData> {
			return request<AdminSettingsData>('POST', '/api/v1/host/admin/settings', undefined, payload);
		},

		async audit(limit = 100): Promise<AuditEntry[]> {
			const data = await request<{ entries: AuditEntry[] }>('GET', '/api/v1/host/admin/audit', {
				limit: String(limit),
			});

			return Array.isArray(data.entries) ? data.entries : [];
		},

		async createReport(payload): Promise<AdminReport> {
			const data = await request<{ report: AdminReport }>('POST', '/api/v1/host/reports', undefined, payload);

			return data.report;
		},
	};
}

let currentAdminApi: AdminApi | null = null;

export function configureAdminApi(api: AdminApi): void {
	currentAdminApi = api;
}

export function getAdminApi(): AdminApi {
	if (currentAdminApi === null) {
		throw new Error('The Aloha administration API has not been configured yet.');
	}

	return currentAdminApi;
}

export const REPORT_CATEGORIES: { id: ReportCategory; label: string; hint: string }[] = [
	{ id: 'spam', label: 'Spam or advertising', hint: 'Unsolicited advertising, repetition or bots.' },
	{ id: 'abuse', label: 'Abuse', hint: 'Insults, threats or discriminatory content.' },
	{ id: 'harassment', label: 'Harassment', hint: 'Targeted stalking, public shaming or intimidation.' },
	{ id: 'misinformation', label: 'Misinformation', hint: 'Demonstrably false or misleading claims.' },
	{ id: 'other', label: 'Other', hint: 'Another reason you describe below.' },
];

export function reportCategoryLabel(category: string): string {
	return REPORT_CATEGORIES.find((entry) => entry.id === category)?.label ?? category;
}

export function reportStateLabel(state: string): string {
	switch (state) {
		case 'open':
			return 'Open';
		case 'resolved':
			return 'Resolved';
		case 'dismissed':
			return 'Dismissed';
		default:
			return state;
	}
}

export function bytesLabel(bytes: number): string {
	if (bytes < 1024) {
		return `${bytes} B`;
	}
	if (bytes < 1024 * 1024) {
		return `${(bytes / 1024).toFixed(1)} KiB`;
	}
	if (bytes < 1024 * 1024 * 1024) {
		return `${(bytes / (1024 * 1024)).toFixed(1)} MiB`;
	}

	return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} GiB`;
}
