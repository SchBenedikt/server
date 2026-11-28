<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';
import Avatar from './Avatar.vue';
import FollowButton from './FollowButton.vue';
import { accountFor, displayNameFor, hasDistinctDisplayName, resolveAccount } from '../lib/account';
import { discover, discoveryPeople, loadDiscover, popularTags } from '../lib/discover';
import { formatCount, handleLabel } from '../lib/format';
import { navigate } from '../lib/router';
import { t } from '../lib/i18n';
import { appState, openComposer, setSearchSeed } from '../lib/state';

const instance = computed(() => appState.instance);
const signedIn = computed(() => appState.session.user !== null);
const self = computed(() => appState.session.user);
const selfName = computed(() => (
	self.value?.displayName
	|| appState.session.profile?.display_name
	|| (self.value !== null ? handleLabel(self.value.handle) : '')
));
const visibleTags = computed(() => popularTags.value.slice(0, 5));
const visiblePeople = computed(() => discoveryPeople.value.slice(0, 3));
const searchQuery = ref('');

watch(visiblePeople, (people) => {
	for (const person of people) {
		void resolveAccount(person.id);
	}
}, { immediate: true, deep: true });

onMounted(() => {
	void loadDiscover();
});

function compose(): void {
	if (!signedIn.value) {
		window.location.assign('/login');
		return;
	}

	openComposer();
}

function submitSearch(): void {
	const query = searchQuery.value.trim();
	if (query === '') {
		navigate('/search');
		return;
	}

	setSearchSeed(query);
	navigate('/search');
}
</script>

<template>
	<aside class="a-rail" :aria-label="t('rail.aria')">
		<form class="a-rail-search" role="search" @submit.prevent="submitSearch">
			<AppIcon name="search" :size="18" />
			<input
				v-model="searchQuery"
				type="search"
				:placeholder="t('rail.searchPlaceholder')"
				:aria-label="t('rail.searchPlaceholder')"
				autocomplete="off"
			/>
		</form>

		<section v-if="self" class="a-rail__card a-rail__me">
			<Avatar :id="self.handle" :name="selfName" :size="46" />
			<div>
				<strong>{{ selfName }}</strong>
				<span v-if="selfName !== handleLabel(self.handle)">{{ handleLabel(self.handle) }}</span>
			</div>
			<button type="button" class="a-icon-btn" :aria-label="t('common.newPost')" @click="compose">
				<AppIcon name="pen" :size="18" />
			</button>
		</section>

		<section class="a-rail__card">
			<header class="a-rail__card-head">
				<h2>{{ t('rail.instanceTitle') }}</h2>
				<a href="#/about" class="a-rail__more" :aria-label="t('rail.instanceMore')">{{ t('common.more') }}</a>
			</header>
			<template v-if="instance">
				<p class="a-rail__instance-name">{{ instance.name }}</p>
				<p class="a-rail__instance-description">
					{{ instance.description || t('rail.instanceDefaultDescription') }}
				</p>
				<dl class="a-stats">
					<div>
						<dt>{{ t('rail.people') }}</dt>
						<dd>{{ formatCount(instance.stats.user_count) }}</dd>
					</div>
					<div>
						<dt>{{ t('rail.posts') }}</dt>
						<dd>{{ formatCount(instance.stats.status_count) }}</dd>
					</div>
					<div>
						<dt>{{ t('rail.server') }}</dt>
						<dd>{{ formatCount(instance.stats.domain_count) }}</dd>
					</div>
				</dl>
			</template>
			<div v-else class="a-rail__skeleton">
				<span class="a-skeleton a-skeleton--line" />
				<span class="a-skeleton a-skeleton--line a-skeleton--short" />
			</div>
			<a v-if="!signedIn" href="/login" class="a-btn a-btn--primary a-rail__login">
				<AppIcon name="globe" :size="16" /> {{ t('common.signIn') }}
			</a>
		</section>

		<section class="a-rail__card">
			<header class="a-rail__card-head">
				<h2>Kokua</h2>
				<a href="#/kokua" class="a-rail__more">{{ t('common.open') }}</a>
			</header>
			<p class="a-rail__instance-description">
				{{ t('rail.kokuaText') }}
			</p>
			<a href="#/kokua" class="a-btn a-btn--ghost a-btn--small a-rail__login">
				<AppIcon name="handHeart" :size="16" /> {{ t('rail.kokuaVisit') }}
			</a>
		</section>

		<section class="a-rail__card">
			<header class="a-rail__card-head">
				<h2>{{ t('rail.tagsTitle') }}</h2>
				<a href="#/explore" class="a-rail__more">{{ t('common.all') }}</a>
			</header>
			<div v-if="discover.loading && visibleTags.length === 0" class="a-rail__skeleton">
				<span class="a-skeleton a-skeleton--line" />
				<span class="a-skeleton a-skeleton--line" />
			</div>
			<ul v-else-if="visibleTags.length > 0" class="a-tag-list">
				<li v-for="tag in visibleTags" :key="tag.tag">
					<a :href="`#/tag/${encodeURIComponent(tag.tag)}`" class="a-tag-row">
						<span class="a-tag-row__hash">#</span>
						<span class="a-tag-row__copy">
							<strong>{{ tag.tag }}</strong>
							<small>{{ tag.count }} {{ tag.count === 1 ? t('rail.post') : t('rail.posts') }}</small>
						</span>
						<AppIcon name="chevronDown" :size="15" class="a-tag-row__arrow" />
					</a>
				</li>
			</ul>
			<p v-else class="a-rail__muted">{{ t('rail.tagsEmpty') }}</p>
		</section>

		<section class="a-rail__card">
			<header class="a-rail__card-head">
				<h2>{{ t('rail.peopleTitle') }}</h2>
				<a href="#/explore" class="a-rail__more">{{ t('common.more') }}</a>
			</header>
			<ul v-if="visiblePeople.length > 0" class="a-people">
				<li v-for="person in visiblePeople" :key="person.id" class="a-person">
					<a :href="`#/u/${encodeURIComponent(person.id)}`" class="a-person__link">
						<Avatar :id="person.id" :name="displayNameFor(person.id)" :size="38" />
						<span class="a-person__copy">
							<strong>{{ displayNameFor(person.id) }}</strong>
							<small v-if="hasDistinctDisplayName(person.id)">{{ handleLabel(person.id) }}</small>
						</span>
					</a>
					<FollowButton :account="accountFor(person.id)" compact />
				</li>
			</ul>
			<p v-else class="a-rail__muted">
				{{ t('rail.peopleEmpty') }}
			</p>
		</section>

		<footer class="a-rail__footer">
			<a href="#/about">{{ t('rail.about') }}</a>
			<span>·</span>
			<a href="https://github.com/SchBenedikt/core" target="_blank" rel="noopener noreferrer">{{ t('rail.sourceCode') }}</a>
			<span>·</span>
			<a href="/api/v1/instance" target="_blank" rel="noopener noreferrer">API</a>
			<p>{{ t('rail.tagline') }}</p>
		</footer>
	</aside>
</template>
