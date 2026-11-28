<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref } from 'vue';
import AppIcon from '../../components/AppIcon.vue';
import { bytesLabel, getAdminApi, type AdminInstance, type AdminSettingsData, type PreflightReport } from '../../lib/admin';
import { absoluteTime } from '../../lib/format';
import { t } from '../../lib/i18n';

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
		preflightError.value = caught instanceof Error ? caught.message : t('aset.preflightFailed');
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

function labelFor(prefix: string, key: string): string {
	const i18nKey = `${prefix}.${key}`;
	const translated = t(i18nKey);
	return translated === i18nKey ? key : translated;
}

function checkLabel(id: string): string {
	if (id === 'blob_directory' && data.value?.features.storage_driver === 's3') {
		return t('aset.check.blob_directory_s3');
	}

	return labelFor('aset.check', id);
}

function valueLabel(key: string, value: string | number | boolean): string {
	if (typeof value === 'boolean') {
		return t(value ? 'aset.active' : 'aset.inactive');
	}
	if (key === 'max_attachment_bytes') {
		return bytesLabel(Number(value));
	}
	if (key === 'session_lifetime_seconds') {
		return t('aset.days', { count: Math.round(Number(value) / 86400) });
	}

	return String(value);
}

function featureLabel(key: string): string {
	return labelFor('aset.feature', key);
}

function limitLabel(key: string): string {
	return labelFor('aset.limit', key);
}

function pathLabel(key: string): string {
	return labelFor('aset.path', key);
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
		error.value = caught instanceof Error ? caught.message : t('aset.loadFailed');
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
		saveError.value = caught instanceof Error ? caught.message : t('aset.settingsLoadFailed');
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
		saved.value = t('aset.saved');
	} catch (caught) {
		saveError.value = caught instanceof Error ? caught.message : t('aset.saveFailed');
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
				<p class="a-admin-eyebrow">{{ t('aset.eyebrow') }}</p>
				<h2>{{ t('aset.title') }}</h2>
				<p class="a-admin-lead">{{ t('aset.lead') }}</p>
			</div>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="loading" @click="load">
				<AppIcon name="refresh" :size="15" :class="{ 'a-spin': loading }" />
				{{ t('aset.checkAgain') }}
			</button>
		</div>

		<p v-if="error !== ''" class="a-form-error" role="alert">{{ error }}</p>

		<form class="a-admin-card a-admin-form" @submit.prevent="saveSettings">
			<header class="a-admin-card__head">
				<div>
					<h3>{{ t('aset.setup') }}</h3>
					<p>{{ t('aset.setupHint') }}</p>
				</div>
				<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="saving || settings === null">
					{{ saving ? t('common.saving') : t('common.save') }}
				</button>
			</header>

			<p v-if="saveError !== ''" class="a-form-error" role="alert">{{ saveError }}</p>
			<p v-if="saved !== ''" class="a-admin-muted" role="status">{{ saved }}</p>

			<div class="a-admin-form__grid">
				<label>
					<span>{{ t('aset.instanceName') }}</span>
					<input v-model="form.instance_name" class="a-input" maxlength="80" />
				</label>
				<label>
					<span>{{ t('aset.contactEmail') }}</span>
					<input v-model="form.contact_email" class="a-input" type="email" />
				</label>
				<label>
					<span>{{ t('aset.coreChannel') }}</span>
					<select v-model="form.core_channel" class="a-input">
						<option value="main">main</option>
						<option value="release">release</option>
					</select>
				</label>
				<label>
					<span>{{ t('aset.coreVersion') }}</span>
					<input v-model="form.core_version" class="a-input" placeholder="0.8.0" />
				</label>
				<label>
					<span>{{ t('aset.jetstream') }}</span>
					<input v-model="form.atproto_jetstream" class="a-input" placeholder="wss://jetstream2.us-east.bsky.network" />
				</label>
				<label>
					<span>{{ t('aset.pdsUrl') }}</span>
					<input v-model="form.atproto_pds_url" class="a-input" placeholder="https://social.example" />
				</label>
				<label>
					<span>{{ t('aset.pdsDomain') }}</span>
					<input v-model="form.atproto_pds_handle_domain" class="a-input" placeholder="social.example" />
				</label>
				<label>
					<span>{{ t('aset.plc') }}</span>
					<input v-model="form.atproto_plc_url" class="a-input" />
				</label>
			</div>

			<label>
				<span>{{ t('aset.description') }}</span>
				<textarea v-model="form.instance_description" class="a-input" rows="3" maxlength="500" />
			</label>

			<div class="a-admin-form__grid">
				<label class="a-admin-form__check">
					<input v-model="form.registrations" type="checkbox" />
					<span>{{ t('aset.openRegistration') }}</span>
				</label>
				<label class="a-admin-form__check">
					<input v-model="form.registration_approval" type="checkbox" />
					<span>{{ t('aset.approval') }}</span>
				</label>
				<label class="a-admin-form__check">
					<input v-model="form.atproto_pds" type="checkbox" />
					<span>{{ t('aset.enablePds') }}</span>
				</label>
				<label class="a-admin-form__check">
					<input v-model="form.publish_domain_blocks" type="checkbox" />
					<span>{{ t('aset.publishBlocks') }}</span>
				</label>
			</div>

			<div class="a-admin-form__actions">
				<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="saving">
					{{ t('aset.saveConfig') }}
				</button>
				<small v-if="settings" class="a-admin-muted">
					{{ t('aset.envFile') }} <code>{{ settings.core.env_file }}</code>
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
					<strong>{{ t('aset.warnings') }}</strong>
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
						<small>{{ t(check.ok ? 'aset.checkPassed' : 'aset.checkFailed') }}</small>
					</div>
				</article>
			</div>

			<section class="a-admin-card">
				<header class="a-admin-card__head">
					<div>
						<h3>{{ t('aset.preflightTitle') }}</h3>
						<p>{{ t('aset.preflightHint') }}</p>
					</div>
					<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="preflightLoading" @click="runPreflight">
						<AppIcon name="refresh" :size="15" :class="{ 'a-spin': preflightLoading }" />
						{{ t(preflight === null ? 'aset.runChecks' : 'aset.runAgain') }}
					</button>
				</header>

				<p v-if="preflightError !== ''" class="a-form-error" role="alert">{{ preflightError }}</p>

				<template v-if="preflight">
					<span class="a-admin-pill" :class="preflightCounts.fail > 0 ? 'a-admin-pill--error' : preflightCounts.warn > 0 ? 'a-admin-pill--warn' : 'a-admin-pill--ok'">
						{{ t('aset.statusOk') }} {{ preflightCounts.ok }}<span v-if="preflightCounts.warn > 0"> · {{ t('aset.statusWarn') }} {{ preflightCounts.warn }}</span><span v-if="preflightCounts.fail > 0"> · {{ t('aset.pillFail', { count: preflightCounts.fail }) }}</span><span v-if="preflightCounts.skip > 0"> · {{ t('aset.pillSkip', { count: preflightCounts.skip }) }}</span>
					</span>
					<ul class="a-admin-health">
						<li v-for="check in preflight.checks" :key="check.id" class="a-admin-health__item">
							<span class="a-admin-dot" :class="`a-admin-dot--${check.status === 'fail' ? 'error' : check.status}`" aria-hidden="true" />
							<div>
								<strong>{{ check.summary }}</strong>
								<small>
									{{ check.group === 'external' ? t('aset.external') : '' }}{{ check.detail ?? '' }}
									<template v-if="check.remediation"> — {{ check.remediation }}</template>
								</small>
							</div>
							<span class="a-admin-health__state">{{ t(check.status === 'ok' ? 'aset.statusOk' : check.status === 'warn' ? 'aset.statusWarn' : check.status === 'fail' ? 'aset.statusFail' : 'aset.statusSkip') }}</span>
						</li>
					</ul>
				</template>
				<p v-else class="a-admin-muted">{{ t('aset.preflightEmpty') }}</p>
			</section>

			<div class="a-admin-columns">
				<section class="a-admin-card">
					<header class="a-admin-card__head">
						<div>
							<h3>{{ t('aset.identity') }}</h3>
							<p>{{ t('aset.identityHint') }}</p>
						</div>
					</header>
					<dl class="a-admin-facts">
						<div>
							<dt>{{ t('aset.name') }}</dt>
							<dd>{{ data.instance.name }}</dd>
						</div>
						<div>
							<dt>{{ t('aset.publicUrl') }}</dt>
							<dd><a :href="data.instance.url" target="_blank" rel="noopener noreferrer">{{ data.instance.url }}</a></dd>
						</div>
						<div>
							<dt>{{ t('aset.actorDomain') }}</dt>
							<dd>{{ data.instance.actor_domain }}</dd>
						</div>
						<div>
							<dt>{{ t('aset.contact') }}</dt>
							<dd>{{ data.instance.contact !== '' ? data.instance.contact : t('aset.notProvided') }}</dd>
						</div>
						<div>
							<dt>{{ t('aset.description') }}</dt>
							<dd>{{ data.instance.description !== '' ? data.instance.description : t('aset.empty') }}</dd>
						</div>
					</dl>
				</section>

				<section class="a-admin-card">
					<header class="a-admin-card__head">
						<div>
							<h3>{{ t('aset.versions') }}</h3>
							<p>{{ t('aset.versionsHint') }}</p>
						</div>
					</header>
					<dl class="a-admin-facts">
						<div>
							<dt>{{ t('aset.application') }}</dt>
							<dd>{{ data.versions.application }}</dd>
						</div>
						<div>
							<dt>{{ t('aset.coreCommit') }}</dt>
							<dd>{{ data.versions.core_reference ?? t('aset.unavailable') }}</dd>
						</div>
						<div>
							<dt>{{ t('aset.php') }}</dt>
							<dd>{{ data.versions.php }}</dd>
						</div>
						<div>
							<dt>{{ t('aset.database') }}</dt>
							<dd>{{ data.versions.database }}</dd>
						</div>
						<div>
							<dt>{{ t('aset.environment') }}</dt>
							<dd>{{ data.versions.environment }}</dd>
						</div>
						<div>
							<dt>{{ t('aset.worker') }}</dt>
							<dd>
								{{ t(data.worker.online ? 'common.online' : 'common.noSignal') }}
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
							<h3>{{ t('aset.features') }}</h3>
							<p>{{ t('aset.featuresHint') }}</p>
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
							<h3>{{ t('aset.limits') }}</h3>
							<p>{{ t('aset.limitsHint') }}</p>
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
						<h3>{{ t('aset.paths') }}</h3>
						<p>{{ t('aset.pathsHint') }}</p>
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
						<h3>{{ t('aset.opsNotes') }}</h3>
						<p>{{ t('aset.opsNotesHint') }}</p>
					</div>
				</header>
				<ul class="a-admin-tips">
					<li>
						<AppIcon name="shield" :size="15" />
						<code>ALOHASOCIAL_SECRET_KEY</code> {{ t('aset.tip1') }}
					</li>
					<li>
						<AppIcon name="clock" :size="15" />
						{{ t('aset.tip2') }}
					</li>
					<li>
						<AppIcon name="mail" :size="15" />
						{{ t('aset.tip3') }}
					</li>
					<li>
						<AppIcon name="globe" :size="15" />
						{{ t('aset.tip4') }}
					</li>
				</ul>
			</section>
		</template>
	</section>
</template>
