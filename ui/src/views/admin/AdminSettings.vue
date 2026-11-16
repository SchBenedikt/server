<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref } from 'vue';
import AppIcon from '../../components/AppIcon.vue';
import { bytesLabel, getAdminApi, type AdminInstance } from '../../lib/admin';
import { absoluteTime } from '../../lib/format';

const data = ref<AdminInstance | null>(null);
const loading = ref(false);
const error = ref('');

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
			return 'Media storage writable';
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

onMounted(() => {
	void load();
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
