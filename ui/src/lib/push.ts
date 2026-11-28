/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Api } from './api';

const SERVICE_WORKER = '/sw.js';

export type PushSupport = 'unsupported' | 'default' | 'denied' | 'granted';

/**
 * Web Push needs three browser APIs plus a service worker at root scope.
 * iOS only exposes them inside an installed PWA, which `navigator.standalone`
 * and display-mode checks cannot detect reliably — so we simply report
 * unsupported and let the subscribe attempt surface the real error.
 */
export function pushSupport(): PushSupport {
	if (typeof window === 'undefined'
		|| !('serviceWorker' in navigator)
		|| !('PushManager' in window)
		|| !('Notification' in window)) {
		return 'unsupported';
	}

	if (Notification.permission === 'denied') {
		return 'denied';
	}

	return Notification.permission === 'granted' ? 'granted' : 'default';
}

async function registration(): Promise<ServiceWorkerRegistration> {
	return navigator.serviceWorker.register(SERVICE_WORKER);
}

/**
 * The browser's current subscription for this origin, or null. The server
 * may know about endpoints this browser no longer holds — the UI treats
 * "subscribed" as the browser-side truth and re-syncs on enable.
 */
export async function currentSubscription(): Promise<PushSubscription | null> {
	if (pushSupport() === 'unsupported') {
		return null;
	}

	const reg = await navigator.serviceWorker.getRegistration(SERVICE_WORKER);
	if (reg === undefined) {
		return null;
	}

	return reg.pushManager.getSubscription();
}

function urlBase64ToUint8Array(base64Url: string): Uint8Array {
	const padding = '='.repeat((4 - (base64Url.length % 4)) % 4);
	const base64 = (base64Url + padding).replace(/-/g, '+').replace(/_/g, '/');
	const raw = atob(base64);
	const output = new Uint8Array(raw.length);
	for (let i = 0; i < raw.length; ++i) {
		output[i] = raw.charCodeAt(i);
	}

	return output;
}

/**
 * Ask for permission, register the worker and subscribe this browser. The
 * resulting PushSubscription JSON is exactly what the subscribe endpoint
 * expects (`endpoint` + `keys.p256dh`/`keys.auth`).
 */
export async function enablePush(api: Api): Promise<void> {
	const permission = await Notification.requestPermission();
	if (permission !== 'granted') {
		throw new Error(permission === 'denied'
			? 'Notifications are blocked for this site. Allow them in the browser settings first.'
			: 'Notification permission was not granted.');
	}

	const reg = await registration();
	const { key } = await api.pushVapidKey();
	const subscription = await reg.pushManager.subscribe({
		userVisibleOnly: true,
		applicationServerKey: urlBase64ToUint8Array(key) as BufferSource,
	});

	// toJSON() yields exactly {endpoint, keys:{p256dh, auth}} in base64url —
	// the shape the subscribe endpoint validates.
	const json = subscription.toJSON();
	if (!json.endpoint || !json.keys?.p256dh || !json.keys.auth) {
		throw new Error('The browser returned an incomplete push subscription.');
	}

	await api.subscribePush(json.endpoint, {
		p256dh: json.keys.p256dh,
		auth: json.keys.auth,
	});
}

export async function disablePush(api: Api): Promise<void> {
	const subscription = await currentSubscription();
	if (subscription === null) {
		return;
	}

	const endpoint = subscription.endpoint;
	await subscription.unsubscribe();
	await api.unsubscribePush(endpoint);
}

/**
 * Whether push is effectively enabled on this browser. If the browser still
 * holds a subscription the server forgot (e.g. a dead-endpoint cleanup),
 * the row is re-registered so state stays honest.
 */
export async function subscribed(api: Api): Promise<boolean> {
	const subscription = await currentSubscription();
	if (subscription === null) {
		return false;
	}

	const json = subscription.toJSON();
	if (!json.endpoint || !json.keys?.p256dh || !json.keys.auth) {
		return false;
	}

	const known = await api.pushSubscriptions();
	if (known.some((entry) => entry.endpoint === json.endpoint)) {
		return true;
	}

	await api.subscribePush(json.endpoint, {
		p256dh: json.keys.p256dh,
		auth: json.keys.auth,
	});

	return true;
}
