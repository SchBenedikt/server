<script setup lang="ts">
import { ref } from 'vue';
import {
	NotificationList,
	PostComposer,
	PublicTimeline,
	type Post,
} from '@alohasocial/ui';
import { bridge, currentHandle, instanceName, signedIn } from './bridge';

const timelineKey = ref(0);
const lastCreated = ref<Post | null>(null);
const loggingOut = ref(false);

function onCreated(post: Post): void {
	lastCreated.value = post;
	timelineKey.value += 1;
}

async function logout(): Promise<void> {
	loggingOut.value = true;
	await bridge.logout();
}
</script>

<template>
	<div class="aloha-app">
		<header class="aloha-app__header">
			<div>
				<h2>{{ instanceName }}</h2>
				<p v-if="signedIn" class="aloha-app__identity">
					Angemeldet als <strong>@{{ currentHandle }}</strong>
				</p>
			</div>
			<nav class="aloha-app__nav">
				<a href="/">Dashboard</a>
				<button v-if="signedIn" type="button" :disabled="loggingOut" @click="logout">Abmelden</button>
				<a v-else :href="bridge.loginUrl('/app')">Anmelden</a>
			</nav>
		</header>

		<section v-if="signedIn" class="aloha-app__panel" aria-label="Beitrag schreiben">
			<h2>Neuer Beitrag</h2>
			<PostComposer @created="onCreated" />
			<p v-if="lastCreated" class="aloha-app__status">
				Gespeichert: <code>{{ lastCreated.id }}</code>
			</p>
		</section>
		<p v-else class="aloha-app__panel">
			<a :href="bridge.loginUrl('/app')">Melde dich an</a>, um zu schreiben.
		</p>

		<section aria-label="Öffentliche Timeline">
			<PublicTimeline :key="timelineKey" />
		</section>

		<section v-if="signedIn" class="aloha-app__panel" aria-label="Benachrichtigungen">
			<h2>Benachrichtigungen</h2>
			<NotificationList />
		</section>
	</div>
</template>
