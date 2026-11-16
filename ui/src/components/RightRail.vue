<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, watch } from 'vue';
import AppIcon from './AppIcon.vue';
import Avatar from './Avatar.vue';
import FollowButton from './FollowButton.vue';
import { accountFor, displayNameFor, hasDistinctDisplayName, resolveAccount } from '../lib/account';
import { discover, discoveryPeople, loadDiscover, popularTags } from '../lib/discover';
import { formatCount, handleLabel } from '../lib/format';
import { appState, openComposer } from '../lib/state';

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
</script>

<template>
	<aside class="a-rail" aria-label="Explore">
		<section v-if="self" class="a-rail__card a-rail__me">
			<Avatar :id="self.handle" :name="selfName" :size="46" />
			<div>
				<strong>{{ selfName }}</strong>
				<span v-if="selfName !== handleLabel(self.handle)">{{ handleLabel(self.handle) }}</span>
			</div>
			<button type="button" class="a-icon-btn" aria-label="Write a new post" @click="compose">
				<AppIcon name="pen" :size="18" />
			</button>
		</section>

		<section class="a-rail__card">
			<header class="a-rail__card-head">
				<h2>This instance</h2>
				<a href="#/about" class="a-rail__more" aria-label="More about this instance">More</a>
			</header>
			<template v-if="instance">
				<p class="a-rail__instance-name">{{ instance.name }}</p>
				<p class="a-rail__instance-description">
					{{ instance.description || 'A small, open instance in the fediverse.' }}
				</p>
				<dl class="a-stats">
					<div>
						<dt>People</dt>
						<dd>{{ formatCount(instance.stats.user_count) }}</dd>
					</div>
					<div>
						<dt>Posts</dt>
						<dd>{{ formatCount(instance.stats.status_count) }}</dd>
					</div>
					<div>
						<dt>Server</dt>
						<dd>{{ formatCount(instance.stats.domain_count) }}</dd>
					</div>
				</dl>
			</template>
			<div v-else class="a-rail__skeleton">
				<span class="a-skeleton a-skeleton--line" />
				<span class="a-skeleton a-skeleton--line a-skeleton--short" />
			</div>
			<a v-if="!signedIn" href="/login" class="a-btn a-btn--primary a-rail__login">
				<AppIcon name="globe" :size="16" /> Sign in
			</a>
		</section>

		<section class="a-rail__card">
			<header class="a-rail__card-head">
				<h2>Popular Tags</h2>
				<a href="#/explore" class="a-rail__more">All</a>
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
							<small>{{ tag.count }} {{ tag.count === 1 ? 'Post' : 'Posts' }}</small>
						</span>
						<AppIcon name="chevronDown" :size="15" class="a-tag-row__arrow" />
					</a>
				</li>
			</ul>
			<p v-else class="a-rail__muted">As soon as posts include hashtags, they appear here.</p>
		</section>

		<section class="a-rail__card">
			<header class="a-rail__card-head">
				<h2>People you should know</h2>
				<a href="#/explore" class="a-rail__more">More</a>
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
				No other voices loaded yet.
			</p>
		</section>

		<footer class="a-rail__footer">
			<a href="#/about">About</a>
			<span>·</span>
			<a href="https://github.com/SchBenedikt/core" target="_blank" rel="noopener noreferrer">Source code</a>
			<span>·</span>
			<a href="/api/v1/instance" target="_blank" rel="noopener noreferrer">API</a>
			<p>Aloha Social · open, federated, ad-free.</p>
		</footer>
	</aside>
</template>
