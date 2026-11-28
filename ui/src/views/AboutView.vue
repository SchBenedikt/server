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
			title="About this instance"
			subtitle="Who gathers here and how the instance connects to the fediverse."
			back-href="#/"
			back-label="Back to home"
		/>

		<section class="a-about-hero">
			<div class="a-about-hero__mark" aria-hidden="true"><img :src="'/ui/brand/aloha-mark.svg'" alt="" /></div>
			<div>
				<p class="a-welcome__eyebrow"><AppIcon name="globe" :size="15" /> Aloha Social</p>
				<h2>{{ instance?.name ?? 'Aloha Social' }}</h2>
				<p class="a-about-hero__host">@{{ host }}</p>
				<p>
					{{ instance?.description || 'An independent, open instance in the fediverse. Posts, images and conversations belong to the people who share them.' }}
				</p>
			</div>
		</section>

		<dl v-if="instance" class="a-about-stats">
			<div>
				<dt>People</dt>
				<dd>{{ formatCount(instance.stats.user_count) }}</dd>
			</div>
			<div>
				<dt>Posts</dt>
				<dd>{{ formatCount(instance.stats.status_count) }}</dd>
			</div>
			<div>
				<dt>Known Server</dt>
				<dd>{{ formatCount(instance.stats.domain_count) }}</dd>
			</div>
			<div>
				<dt>API</dt>
				<dd>{{ instance.api }}</dd>
			</div>
		</dl>

		<section class="a-about-grid">
			<article class="a-rail__card">
				<h3><AppIcon name="globe" :size="18" /> Federation</h3>
				<p>
					{{ instance?.federation
						? 'This instance speaks ActivityPub and can communicate with other servers in the fediverse.'
						: 'Federation is currently disabled on this instance.' }}
				</p>
				<p v-if="instance?.languages?.length" class="a-muted">
					Languages: {{ instance.languages.join(', ') }}
				</p>
			</article>

			<article class="a-rail__card">
				<h3><AppIcon name="users" :size="18" /> Join in</h3>
				<p>
					{{ instance?.registrations
						? 'New accounts can register.'
						: 'This instance only accepts new accounts through invitations or the administration.' }}
				</p>
				<a v-if="appState.session.user === null" class="a-btn a-btn--primary a-btn--small" href="/login">Sign in</a>
				<a v-else class="a-btn a-btn--ghost a-btn--small" href="#/explore">Discover people</a>
			</article>

			<article class="a-rail__card">
				<h3><AppIcon name="message" :size="18" /> Contact</h3>
				<p v-if="instance?.contact_account">
					<a :href="`#/u/${encodeURIComponent(instance.contact_account)}`">
						{{ handleLabel(instance.contact_account) }}
					</a>
				</p>
				<p v-if="instance?.email">
					<a :href="`mailto:${instance.email}`">{{ instance.email }}</a>
				</p>
				<p v-if="!instance?.contact_account && !instance?.email" class="a-muted">
					The administration has not published any public contact details yet.
				</p>
			</article>

			<article v-if="pages.length > 0" class="a-rail__card">
				<h3><AppIcon name="pen" :size="18" /> Instance pages</h3>
				<ul class="a-about-pages">
					<li v-for="entry in pages" :key="entry.slug">
						<a :href="`#/pages/${encodeURIComponent(entry.slug)}`">{{ entry.title }}</a>
					</li>
				</ul>
			</article>

			<article class="a-rail__card">
				<h3><AppIcon name="info" :size="18" /> Technology</h3>
				<p>
					Aloha Social {{ instance?.version ?? '' }} on an independent PHP host.
					The source code is freely available.
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
			Aloha Social is free software under AGPL-3.0-or-later. No ads, no algorithmic pressure — just a place for people.
		</p>
	</div>
</template>
