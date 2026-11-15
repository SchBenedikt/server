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
	federation: 'ActivityPub-Föderation',
	remote_media: 'Entfernte Medien laden',
	mastodon_api: 'Mastodon-kompatible API',
	mastodon_prefix: 'API-Präfix',
	mail_transport: 'Mail-Transport',
	secure_cookies: 'Sichere Cookies',
	atproto: 'AT Protocol (Bluesky)',
};

const LIMIT_LABELS: Record<string, string> = {
	session_lifetime_seconds: 'Sitzungsdauer',
	max_post_characters: 'Zeichen pro Beitrag',
	max_attachments: 'Anhänge pro Beitrag',
	max_attachment_bytes: 'Größe pro Anhang',
};

const PATH_LABELS: Record<string, string> = {
	var: 'Laufzeitverzeichnis',
	blobs: 'Medienspeicher',
	mail: 'Mail-Ablage',
	secret_key: 'Schlüsseldatei',
};

function checkLabel(id: string): string {
	switch (id) {
		case 'database':
			return 'Datenbank erreichbar';
		case 'blob_directory':
			return 'Medienspeicher schreibbar';
		case 'secret_key':
			return 'Verschlüsselungsschlüssel';
		case 'worker':
			return 'Worker meldet sich';
		default:
			return id;
	}
}

function valueLabel(key: string, value: string | number | boolean): string {
	if (typeof value === 'boolean') {
		return value ? 'Aktiv' : 'Inaktiv';
	}
	if (key === 'max_attachment_bytes') {
		return bytesLabel(Number(value));
	}
	if (key === 'session_lifetime_seconds') {
		return `${Math.round(Number(value) / 86400)} Tage`;
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
		error.value = caught instanceof Error ? caught.message : 'Die Instanzdaten konnten nicht geladen werden.';
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
				<p class="a-admin-eyebrow">Laufzeit</p>
				<h2>Instanz und Grenzen</h2>
				<p class="a-admin-lead">
					Die Konfiguration wird beim Start aus Umgebungsvariablen und
					<code>config/server.php</code> gelesen. Diese Ansicht prüft, was tatsächlich aktiv ist.
				</p>
			</div>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="loading" @click="load">
				<AppIcon name="refresh" :size="15" :class="{ 'a-spin': loading }" />
				Erneut prüfen
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
					<strong>Konfigurationshinweise</strong>
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
						<small>{{ check.ok ? 'Bestanden' : 'Prüfen' }}</small>
					</div>
				</article>
			</div>

			<div class="a-admin-columns">
				<section class="a-admin-card">
					<header class="a-admin-card__head">
						<div>
							<h3>Identität</h3>
							<p>Wie die Instanz nach außen auftritt.</p>
						</div>
					</header>
					<dl class="a-admin-facts">
						<div>
							<dt>Name</dt>
							<dd>{{ data.instance.name }}</dd>
						</div>
						<div>
							<dt>Öffentliche URL</dt>
							<dd><a :href="data.instance.url" target="_blank" rel="noopener noreferrer">{{ data.instance.url }}</a></dd>
						</div>
						<div>
							<dt>Actor-Domain</dt>
							<dd>{{ data.instance.actor_domain }}</dd>
						</div>
						<div>
							<dt>Kontakt</dt>
							<dd>{{ data.instance.contact !== '' ? data.instance.contact : 'Nicht hinterlegt' }}</dd>
						</div>
						<div>
							<dt>Beschreibung</dt>
							<dd>{{ data.instance.description !== '' ? data.instance.description : 'Leer' }}</dd>
						</div>
					</dl>
				</section>

				<section class="a-admin-card">
					<header class="a-admin-card__head">
						<div>
							<h3>Versionen</h3>
							<p>Anwendung, Laufzeit und Datenbank.</p>
						</div>
					</header>
					<dl class="a-admin-facts">
						<div>
							<dt>Anwendung</dt>
							<dd>{{ data.versions.application }}</dd>
						</div>
						<div>
							<dt>PHP</dt>
							<dd>{{ data.versions.php }}</dd>
						</div>
						<div>
							<dt>Datenbank</dt>
							<dd>{{ data.versions.database }}</dd>
						</div>
						<div>
							<dt>Umgebung</dt>
							<dd>{{ data.versions.environment }}</dd>
						</div>
						<div>
							<dt>Worker</dt>
							<dd>
								{{ data.worker.online ? 'Online' : 'Kein Signal' }}
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
							<h3>Funktionen</h3>
							<p>Was diese Instanz nach außen anbietet.</p>
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
							<h3>Grenzen</h3>
							<p>Die Werte, die Beiträge und Sitzungen begrenzen.</p>
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
						<h3>Pfade</h3>
						<p>Absolute Pfade, die der Prozess verwendet. Änderungen erfordern einen Neustart.</p>
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
						<h3>Betriebshinweise</h3>
						<p>Was für einen zuverlässigen Betrieb noch fehlt.</p>
					</div>
				</header>
				<ul class="a-admin-tips">
					<li>
						<AppIcon name="shield" :size="15" />
						<code>ALOHASOCIAL_SECRET_KEY</code> außerhalb des Projektverzeichnisses aufbewahren
						und in kein Repository aufnehmen.
					</li>
					<li>
						<AppIcon name="clock" :size="15" />
						Den Worker dauerhaft über systemd oder einen Cron-Eintrag für <code>--once</code>
						betreiben.
					</li>
					<li>
						<AppIcon name="mail" :size="15" />
						Für Passwort-Zurücksetzungen SMTP statt des Log-Transports konfigurieren.
					</li>
					<li>
						<AppIcon name="globe" :size="15" />
						Eine öffentliche Instanz braucht HTTPS; die Prüfung oben meldet HTTP als Hinweis.
					</li>
				</ul>
			</section>
		</template>
	</section>
</template>
