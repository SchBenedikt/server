<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref } from 'vue';
import AppIcon from '../../components/AppIcon.vue';
import { bytesLabel, getAdminApi, type AdminInstance, type AdminSettingsData, type PreflightReport } from '../../lib/admin';
import { absoluteTime } from '../../lib/format';

const data = ref<AdminInstance | null>(null);
const loading = ref(false);
const error = ref('');
const settings = ref<AdminSettingsData | null>(null);
const saving = ref(false);
const saved = ref('');
const saveError = ref('');
const form = ref({
	instance_name: '',
	instance_description: '',
	contact_email: '',
	registrations: false,
	registration_approval: true,
	core_channel: 'main',
	core_version: '',
	atproto_pds: false,
	atproto_pds_url: '',
	atproto_pds_handle_domain: '',
	atproto_plc_url: 'https://plc.directory',
	atproto_jetstream: '',
	publish_domain_blocks: false,
});

const preflight = ref<PreflightReport | null>(null);
const preflightLoading = ref(false);
const preflightError = ref('');

const preflightCounts = computed(() => {
	const counts = { ok: 0, warn: 0, fail: 0, skip: 0 };
	for (const check of preflight.value?.checks ?? []) {
		counts[check.status] += 1;
	}
	return counts;
});

async function runPreflight(): Promise<void> {
	if (preflightLoading.value) {
		return;
	}
	preflightLoading.value = true;
	preflightError.value = '';
	try {
		preflight.value = await getAdminApi().preflight();
	} catch (caught) {
		preflightError.value = caught instanceof Error ? caught.message : 'The preflight could not be run.';
	} finally {
		preflightLoading.value = false;
	}
}

const checks = computed(() => {
	const source = data.value?.checks ?? {};
	return Object.entries(source).map(([id, ok]) => ({
		id,
		label: checkLabel(id),
		ok,
	}));
});

const features = computed(() => Object.entries(data.value?.features ?? {}));
const limits = computed(() => Object.entries(data.value?.limits ?? {}));
const paths = computed(() => Object.entries(data.value?.paths ?? {}));

const FEATURE_LABELS: Record<string, string> = {
	federation: 'ActivityPub federation',
	remote_media: 'Load remote media',
	mastodon_api: 'Mastodon-compatible API',
	mastodon_prefix: 'API prefix',
	mail_transport: 'Mail-Transport',
	secure_cookies: 'Secure Cookies',
	atproto: 'AT Protocol (Bluesky)',
};

const LIMIT_LABELS: Record<string, string> = {
	session_lifetime_seconds: 'Session duration',
	max_post_characters: 'Characters per post',
	max_attachments: 'Attachments per post',
	max_attachment_bytes: 'Size per attachment',
};

const PATH_LABELS: Record<string, string> = {
	var: 'Runtime directory',
	blobs: 'Media storage',
	mail: 'Mail-Storage',
	secret_key: 'Key file',
};

function checkLabel(id: string): string {
	switch (id) {
		case 'database':
			return 'Database reachable';
		case 'blob_directory':
			return data.value?.features.storage_driver === 's3' ? 'S3 bucket reachable' : 'Media storage writable';
		case 'secret_key':
			return 'Encryption key';
		case 'worker':
			return 'Worker is responsive';
		default:
			return id;
	}
}

function valueLabel(key: string, value: string | number | boolean): string {
	if (typeof value === 'boolean') {
		return value ? 'Active' : 'Inactive';
	}
	if (key === 'max_attachment_bytes') {
		return bytesLabel(Number(value));
	}
	if (key === 'session_lifetime_seconds') {
		return `${Math.round(Number(value) / 86400)} days`;
	}

	return String(value);
}

function featureLabel(key: string): string {
	return FEATURE_LABELS[key] ?? key;
}

function limitLabel(key: string): string {
	return LIMIT_LABELS[key] ?? key;
}

function pathLabel(key: string): string {
	return PATH_LABELS[key] ?? key;
}

function featureTone(value: string | number | boolean): string {
	if (typeof value === 'boolean') {
		return value ? 'ok' : 'muted';
	}

	return 'muted';
}

async function load(): Promise<void> {
	loading.value = true;
	error.value = '';
	try {
		data.value = await getAdminApi().instance();
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Instance data could not be loaded.';
	} finally {
		loading.value = false;
	}
}

async function loadSettings(): Promise<void> {
	try {
		const loaded = await getAdminApi().settings();
		settings.value = loaded;
		form.value = {
			instance_name: loaded.instance.name,
			instance_description: loaded.instance.description,
			contact_email: loaded.instance.contact_email,
			registrations: loaded.registrations.enabled,
			registration_approval: loaded.registrations.approval,
			core_channel: loaded.core.channel,
			core_version: loaded.core.version,
			atproto_pds: loaded.atproto.pds_enabled,
			atproto_pds_url: loaded.atproto.pds_url,
			atproto_pds_handle_domain: loaded.atproto.handle_domain,
			atproto_plc_url: loaded.atproto.plc_url,
			atproto_jetstream: loaded.jetstream,
			publish_domain_blocks: loaded.moderation.publish_domain_blocks,
		};
	} catch (caught) {
		saveError.value = caught instanceof Error ? caught.message : 'Settings could not be loaded.';
	}
}

async function saveSettings(): Promise<void> {
	if (saving.value) {
		return;
	}

	saving.value = true;
	saved.value = '';
	saveError.value = '';
	try {
		settings.value = await getAdminApi().updateSettings({ ...form.value });
		saved.value = 'Saved. The worker applies ingestion changes on its next start.';
	} catch (caught) {
		saveError.value = caught instanceof Error ? caught.message : 'The settings could not be saved.';
	} finally {
		saving.value = false;
	}
}

onMounted(() => {
	void load();
	void loadSettings();
});
</script>

<template>
	<section class="a-admin-section">
		<div class="a-admin-section__head">
			<div>
				<p class="a-admin-eyebrow">Runtime</p>
				<h2>Instance and limits</h2>
				<p class="a-admin-lead">
					Configuration is read at startup from environment variables and
					<code>config/server.php</code>. This view checks what is actually active.
				</p>
			</div>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="loading" @click="load">
				<AppIcon name="refresh" :size="15" :class="{ 'a-spin': loading }" />
				Check again
			</button>
		</div>

		<p v-if="error !== ''" class="a-form-error" role="alert">{{ error }}</p>

		<form class="a-admin-card a-admin-form" @submit.prevent="saveSettings">
			<header class="a-admin-card__head">
				<div>
					<h3>Instance setup</h3>
					<p>Saved to the configured env file; no manual editing required.</p>
				</div>
				<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="saving || settings === null">
					{{ saving ? 'Saving…' : 'Save' }}
				</button>
			</header>

			<p v-if="saveError !== ''" class="a-form-error" role="alert">{{ saveError }}</p>
			<p v-if="saved !== ''" class="a-admin-muted" role="status">{{ saved }}</p>

			<div class="a-admin-form__grid">
				<label>
					<span>Instance name</span>
					<input v-model="form.instance_name" class="a-input" maxlength="80" />
				</label>
				<label>
					<span>Contact email</span>
					<input v-model="form.contact_email" class="a-input" type="email" />
				</label>
				<label>
					<span>Core channel</span>
					<select v-model="form.core_channel" class="a-input">
						<option value="main">main</option>
						<option value="release">release</option>
					</select>
				</label>
				<label>
					<span>Core version (release channel)</span>
					<input v-model="form.core_version" class="a-input" placeholder="0.8.0" />
				</label>
				<label>
					<span>Jetstream relay</span>
					<input v-model="form.atproto_jetstream" class="a-input" placeholder="wss://jetstream2.us-east.bsky.network" />
				</label>
				<label>
					<span>PDS public URL</span>
					<input v-model="form.atproto_pds_url" class="a-input" placeholder="https://social.example" />
				</label>
				<label>
					<span>PDS handle domain</span>
					<input v-model="form.atproto_pds_handle_domain" class="a-input" placeholder="social.example" />
				</label>
				<label>
					<span>PLC directory</span>
					<input v-model="form.atproto_plc_url" class="a-input" />
				</label>
			</div>

			<label>
				<span>Instance description</span>
				<textarea v-model="form.instance_description" class="a-input" rows="3" maxlength="500" />
			</label>

			<div class="a-admin-form__grid">
				<label class="a-admin-form__check">
					<input v-model="form.registrations" type="checkbox" />
					<span>Allow open registration</span>
				</label>
				<label class="a-admin-form__check">
					<input v-model="form.registration_approval" type="checkbox" />
					<span>New accounts need approval</span>
				</label>
				<label class="a-admin-form__check">
					<input v-model="form.atproto_pds" type="checkbox" />
					<span>Enable the native PDS</span>
				</label>
				<label class="a-admin-form__check">
					<input v-model="form.publish_domain_blocks" type="checkbox" />
					<span>Publish domain blocks to Mastodon clients</span>
				</label>
			</div>

			<div class="a-admin-form__actions">
				<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="saving">
					Save configuration
				</button>
				<small v-if="settings" class="a-admin-muted">
					Env file: <code>{{ settings.core.env_file }}</code>
				</small>
			</div>
		</form>

		<div v-if="loading && data === null" class="a-admin-skeleton-grid a-admin-skeleton-grid--rows">
			<span v-for="index in 4" :key="index" class="a-admin-skeleton" />
		</div>

		<template v-else-if="data">
			<div v-if="data.warnings.length > 0" class="a-admin-warning-panel">
				<AppIcon name="alert" :size="20" />
				<div>
					<strong>Configuration notes</strong>
					<ul>
						<li v-for="warning in data.warnings" :key="warning">{{ warning }}</li>
					</ul>
				</div>
			</div>

			<div class="a-admin-check-grid">
				<article v-for="check in checks" :key="check.id" class="a-admin-check" :class="check.ok ? 'a-admin-check--ok' : 'a-admin-check--error'">
					<span class="a-admin-dot" :class="check.ok ? 'a-admin-dot--ok' : 'a-admin-dot--error'" aria-hidden="true" />
					<div>
						<strong>{{ check.label }}</strong>
						<small>{{ check.ok ? 'Passed' : 'Check' }}</small>
					</div>
				</article>
			</div>

			<section class="a-admin-card">
				<header class="a-admin-card__head">
					<div>
						<h3>Federation readiness</h3>
						<p>
							Verifies this install locally and, on demand, fetches the public
							origin, NodeInfo and WebFinger. External checks use bounded
							requests and never expose secrets.
						</p>
					</div>
					<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="preflightLoading" @click="runPreflight">
						<AppIcon name="refresh" :size="15" :class="{ 'a-spin': preflightLoading }" />
						{{ preflight === null ? 'Run checks' : 'Run again' }}
					</button>
				</header>

				<p v-if="preflightError !== ''" class="a-form-error" role="alert">{{ preflightError }}</p>

				<template v-if="preflight">
					<span class="a-admin-pill" :class="preflightCounts.fail > 0 ? 'a-admin-pill--error' : preflightCounts.warn > 0 ? 'a-admin-pill--warn' : 'a-admin-pill--ok'">
						{{ preflightCounts.ok }} OK<span v-if="preflightCounts.warn > 0"> · {{ preflightCounts.warn }} Warning</span><span v-if="preflightCounts.fail > 0"> · {{ preflightCounts.fail }} Failed</span><span v-if="preflightCounts.skip > 0"> · {{ preflightCounts.skip }} Skipped</span>
					</span>
					<ul class="a-admin-health">
						<li v-for="check in preflight.checks" :key="check.id" class="a-admin-health__item">
							<span class="a-admin-dot" :class="`a-admin-dot--${check.status === 'fail' ? 'error' : check.status}`" aria-hidden="true" />
							<div>
								<strong>{{ check.summary }}</strong>
								<small>
									{{ check.group === 'external' ? 'External · ' : '' }}{{ check.detail ?? '' }}
									<template v-if="check.remediation"> — {{ check.remediation }}</template>
								</small>
							</div>
							<span class="a-admin-health__state">{{ check.status === 'ok' ? 'OK' : check.status === 'warn' ? 'Warning' : check.status === 'fail' ? 'Failed' : 'Skipped' }}</span>
						</li>
					</ul>
				</template>
				<p v-else class="a-admin-muted">Run the checks to verify database, worker, mail, storage and public federation reachability.</p>
			</section>

			<div class="a-admin-columns">
				<section class="a-admin-card">
					<header class="a-admin-card__head">
						<div>
							<h3>Identity</h3>
							<p>How the instance presents itself to the outside world.</p>
						</div>
					</header>
					<dl class="a-admin-facts">
						<div>
							<dt>Name</dt>
							<dd>{{ data.instance.name }}</dd>
						</div>
						<div>
							<dt>Public URL</dt>
							<dd><a :href="data.instance.url" target="_blank" rel="noopener noreferrer">{{ data.instance.url }}</a></dd>
						</div>
						<div>
							<dt>Actor-Domain</dt>
							<dd>{{ data.instance.actor_domain }}</dd>
						</div>
						<div>
							<dt>Contact</dt>
							<dd>{{ data.instance.contact !== '' ? data.instance.contact : 'Not provided' }}</dd>
						</div>
						<div>
							<dt>Description</dt>
							<dd>{{ data.instance.description !== '' ? data.instance.description : 'Empty' }}</dd>
						</div>
					</dl>
				</section>

				<section class="a-admin-card">
					<header class="a-admin-card__head">
						<div>
							<h3>Versions</h3>
							<p>Application, runtime and database.</p>
						</div>
					</header>
					<dl class="a-admin-facts">
						<div>
							<dt>Application</dt>
							<dd>{{ data.versions.application }}</dd>
						</div>
						<div>
							<dt>Core commit</dt>
							<dd>{{ data.versions.core_reference ?? 'Unavailable' }}</dd>
						</div>
						<div>
							<dt>PHP</dt>
							<dd>{{ data.versions.php }}</dd>
						</div>
						<div>
							<dt>Database</dt>
							<dd>{{ data.versions.database }}</dd>
						</div>
						<div>
							<dt>Environment</dt>
							<dd>{{ data.versions.environment }}</dd>
						</div>
						<div>
							<dt>Worker</dt>
							<dd>
								{{ data.worker.online ? 'Online' : 'No signal' }}
								<small v-if="data.worker.updated_at" class="a-admin-muted">
									· {{ absoluteTime(data.worker.updated_at) }}
								</small>
							</dd>
						</div>
					</dl>
				</section>
			</div>

			<div class="a-admin-columns">
				<section class="a-admin-card">
					<header class="a-admin-card__head">
						<div>
							<h3>Features</h3>
							<p>What this instance offers to the outside world.</p>
						</div>
					</header>
					<ul class="a-admin-flag-list">
						<li v-for="[key, value] in features" :key="key">
							<span>{{ featureLabel(key) }}</span>
							<span class="a-admin-badge" :class="`a-admin-badge--${featureTone(value)}`">
								{{ valueLabel(key, value) }}
							</span>
						</li>
					</ul>
				</section>

				<section class="a-admin-card">
					<header class="a-admin-card__head">
						<div>
							<h3>Limits</h3>
							<p>The values that limit posts and sessions.</p>
						</div>
					</header>
					<ul class="a-admin-flag-list">
						<li v-for="[key, value] in limits" :key="key">
							<span>{{ limitLabel(key) }}</span>
							<strong>{{ valueLabel(key, value) }}</strong>
						</li>
					</ul>
				</section>
			</div>

			<section class="a-admin-card">
				<header class="a-admin-card__head">
					<div>
						<h3>Paths</h3>
						<p>Absolute paths used by the process. Changes require a restart.</p>
					</div>
				</header>
				<ul class="a-admin-flag-list">
					<li v-for="[key, value] in paths" :key="key">
						<span>{{ pathLabel(key) }}</span>
						<code class="a-admin-mono">{{ value }}</code>
					</li>
				</ul>
			</section>

			<section class="a-admin-card">
				<header class="a-admin-card__head">
					<div>
						<h3>Operations notes</h3>
						<p>What is still missing for reliable operation.</p>
					</div>
				</header>
				<ul class="a-admin-tips">
					<li>
						<AppIcon name="shield" :size="15" />
						<code>ALOHASOCIAL_SECRET_KEY</code> keep outside the project directory
						and never commit to a repository.
					</li>
					<li>
						<AppIcon name="clock" :size="15" />
						Run the worker permanently via systemd or a cron entry for <code>--once</code>
						in production.
					</li>
					<li>
						<AppIcon name="mail" :size="15" />
						Configure SMTP instead of the log transport for password resets.
					</li>
					<li>
						<AppIcon name="globe" :size="15" />
						A public instance needs HTTPS; the check above flags HTTP as a warning.
					</li>
				</ul>
			</section>
		</template>
	</section>
</template>
