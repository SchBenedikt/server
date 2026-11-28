<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import PageHeader from '../components/PageHeader.vue';
import { getApi } from '../lib/api';
import { formatCount, handleLabel } from '../lib/format';
import { t } from '../lib/i18n';
import { appState } from '../lib/state';
import type { InstancePageSummary } from '../lib/types';

const instance = computed(() => appState.instance);
const host = computed(() => typeof window === 'undefined' ? '' : window.location.host);
const pages = ref<InstancePageSummary[]>([]);

onMounted(async () => {
	try {
		pages.value = await getApi().instancePages();
	} catch {
		pages.value = [];
	}
});
</script>

<template>
	<div class="a-page">
		<PageHeader
			:title="t('about.title')"
			:subtitle="t('about.subtitle')"
			back-href="#/"
			:back-label="t('common.backHome')"
		/>

		<section class="a-about-hero">
			<div class="a-about-hero__mark" aria-hidden="true"><img :src="'/ui/brand/aloha-mark.svg'" alt="" /></div>
			<div>
				<p class="a-welcome__eyebrow"><AppIcon name="globe" :size="15" /> Aloha Social</p>
				<h2>{{ instance?.name ?? 'Aloha Social' }}</h2>
				<p class="a-about-hero__host">@{{ host }}</p>
				<p>
					{{ instance?.description || t('about.descDefault') }}
				</p>
			</div>
		</section>

		<dl v-if="instance" class="a-about-stats">
			<div>
				<dt>{{ t('about.people') }}</dt>
				<dd>{{ formatCount(instance.stats.user_count) }}</dd>
			</div>
			<div>
				<dt>{{ t('about.posts') }}</dt>
				<dd>{{ formatCount(instance.stats.status_count) }}</dd>
			</div>
			<div>
				<dt>{{ t('about.knownServers') }}</dt>
				<dd>{{ formatCount(instance.stats.domain_count) }}</dd>
			</div>
			<div>
				<dt>{{ t('about.api') }}</dt>
				<dd>{{ instance.api }}</dd>
			</div>
		</dl>

		<section class="a-about-grid">
			<article class="a-rail__card">
				<h3><AppIcon name="globe" :size="18" /> Federation</h3>
				<p>
					{{ t(instance?.federation ? 'about.federationOn' : 'about.federationOff') }}
				</p>
				<p v-if="instance?.languages?.length" class="a-muted">
					{{ t('about.languages', { langs: instance.languages.join(', ') }) }}
				</p>
			</article>

			<article class="a-rail__card">
				<h3><AppIcon name="users" :size="18" /> {{ t('about.joinTitle') }}</h3>
				<p>
					{{ t(instance?.registrations ? 'about.regOpen' : 'about.regClosed') }}
				</p>
				<a v-if="appState.session.user === null" class="a-btn a-btn--primary a-btn--small" href="/login">{{ t('about.signIn') }}</a>
				<a v-else class="a-btn a-btn--ghost a-btn--small" href="#/explore">{{ t('about.discover') }}</a>
			</article>

			<article class="a-rail__card">
				<h3><AppIcon name="message" :size="18" /> {{ t('about.contactTitle') }}</h3>
				<p v-if="instance?.contact_account">
					<a :href="`#/u/${encodeURIComponent(instance.contact_account)}`">
						{{ handleLabel(instance.contact_account) }}
					</a>
				</p>
				<p v-if="instance?.email">
					<a :href="`mailto:${instance.email}`">{{ instance.email }}</a>
				</p>
				<p v-if="!instance?.contact_account && !instance?.email" class="a-muted">
					{{ t('about.noContact') }}
				</p>
			</article>

			<article v-if="pages.length > 0" class="a-rail__card">
				<h3><AppIcon name="pen" :size="18" /> {{ t('about.pagesTitle') }}</h3>
				<ul class="a-about-pages">
					<li v-for="entry in pages" :key="entry.slug">
						<a :href="`#/pages/${encodeURIComponent(entry.slug)}`">{{ entry.title }}</a>
					</li>
				</ul>
			</article>

			<article class="a-rail__card">
				<h3><AppIcon name="info" :size="18" /> {{ t('about.techTitle') }}</h3>
				<p>
					{{ t('about.techBody', { version: instance?.version ?? '' }) }}
				</p>
				<div class="a-about-links">
					<a href="https://github.com/SchBenedikt/server" target="_blank" rel="noopener noreferrer">
						{{ t('about.serverLink') }} <AppIcon name="external" :size="13" />
					</a>
					<a href="https://github.com/SchBenedikt/core" target="_blank" rel="noopener noreferrer">
						{{ t('about.coreLink') }} <AppIcon name="external" :size="13" />
					</a>
					<a href="/api/v1/instance" target="_blank" rel="noopener noreferrer">
						{{ t('about.apiLink') }} <AppIcon name="external" :size="13" />
					</a>
				</div>
			</article>
		</section>

		<p class="a-about-footnote">
			{{ t('about.footnote') }}
		</p>
	</div>
</template>
