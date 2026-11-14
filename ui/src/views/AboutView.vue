<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import PageHeader from '../components/PageHeader.vue';
import { formatCount, handleLabel } from '../lib/format';
import { appState } from '../lib/state';

const instance = computed(() => appState.instance);
const host = computed(() => typeof window === 'undefined' ? '' : window.location.host);
</script>

<template>
	<div class="a-page">
		<PageHeader
			title="Über diese Instanz"
			subtitle="Wer hier zusammenkommt und wie die Instanz mit dem Fediverse verbunden ist."
			back-href="#/"
			back-label="Zurück zur Startseite"
		/>

		<section class="a-about-hero">
			<div class="a-about-hero__mark" aria-hidden="true"><img :src="'/ui/brand/aloha-mark.svg'" alt="" /></div>
			<div>
				<p class="a-welcome__eyebrow"><AppIcon name="globe" :size="15" /> Aloha Social</p>
				<h2>{{ instance?.name ?? 'Aloha Social' }}</h2>
				<p class="a-about-hero__host">@{{ host }}</p>
				<p>
					{{ instance?.description || 'Eine unabhängige, offene Instanz im Fediverse. Beiträge, Bilder und Gespräche gehören den Menschen, die sie teilen.' }}
				</p>
			</div>
		</section>

		<dl v-if="instance" class="a-about-stats">
			<div>
				<dt>Menschen</dt>
				<dd>{{ formatCount(instance.stats.user_count) }}</dd>
			</div>
			<div>
				<dt>Beiträge</dt>
				<dd>{{ formatCount(instance.stats.status_count) }}</dd>
			</div>
			<div>
				<dt>Bekannte Server</dt>
				<dd>{{ formatCount(instance.stats.domain_count) }}</dd>
			</div>
			<div>
				<dt>API</dt>
				<dd>{{ instance.api }}</dd>
			</div>
		</dl>

		<section class="a-about-grid">
			<article class="a-rail__card">
				<h3><AppIcon name="globe" :size="18" /> Föderation</h3>
				<p>
					{{ instance?.federation
						? 'Diese Instanz spricht ActivityPub und kann mit anderen Servern im Fediverse kommunizieren.'
						: 'Die Föderation ist auf dieser Instanz derzeit deaktiviert.' }}
				</p>
				<p v-if="instance?.languages?.length" class="a-muted">
					Sprachen: {{ instance.languages.join(', ') }}
				</p>
			</article>

			<article class="a-rail__card">
				<h3><AppIcon name="users" :size="18" /> Mitmachen</h3>
				<p>
					{{ instance?.registrations
						? 'Neue Konten können sich registrieren.'
						: 'Diese Instanz nimmt neue Konten nur über Einladungen oder die Administration auf.' }}
				</p>
				<a v-if="appState.session.user === null" class="a-btn a-btn--primary a-btn--small" href="/login">Anmelden</a>
				<a v-else class="a-btn a-btn--ghost a-btn--small" href="#/explore">Menschen entdecken</a>
			</article>

			<article class="a-rail__card">
				<h3><AppIcon name="message" :size="18" /> Kontakt</h3>
				<p v-if="instance?.contact_account">
					<a :href="`#/u/${encodeURIComponent(instance.contact_account)}`">
						{{ handleLabel(instance.contact_account) }}
					</a>
				</p>
				<p v-if="instance?.email">
					<a :href="`mailto:${instance.email}`">{{ instance.email }}</a>
				</p>
				<p v-if="!instance?.contact_account && !instance?.email" class="a-muted">
					Die Administration hat noch keine öffentlichen Kontaktdaten hinterlegt.
				</p>
			</article>

			<article class="a-rail__card">
				<h3><AppIcon name="info" :size="18" /> Technik</h3>
				<p>
					Aloha Social {{ instance?.version ?? '' }} auf einem unabhängigen PHP-Host.
					Der Quellcode ist frei verfügbar.
				</p>
				<div class="a-about-links">
					<a href="https://github.com/SchBenedikt/server" target="_blank" rel="noopener noreferrer">
						Server <AppIcon name="external" :size="13" />
					</a>
					<a href="https://github.com/SchBenedikt/core" target="_blank" rel="noopener noreferrer">
						Core <AppIcon name="external" :size="13" />
					</a>
					<a href="/api/v1/instance" target="_blank" rel="noopener noreferrer">
						API <AppIcon name="external" :size="13" />
					</a>
				</div>
			</article>
		</section>

		<p class="a-about-footnote">
			Aloha Social ist freie Software unter der AGPL-3.0-or-later. Keine Werbung, kein algorithmischer Zwang – nur ein Ort für Menschen.
		</p>
	</div>
</template>
