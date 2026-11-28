// Aloha Social service worker — Web Push delivery only.
//
// It deliberately caches nothing: the app shell is versioned by the PHP
// template and must never be served stale. The worker exists so the
// browser can receive push events while the tab is closed.

self.addEventListener('push', (event) => {
	let data = {};
	try {
		data = event.data ? event.data.json() : {};
	} catch {
		data = {};
	}

	const title = typeof data.title === 'string' && data.title !== '' ? data.title : 'Aloha Social';
	const options = {
		body: typeof data.body === 'string' ? data.body : 'You have a new notification.',
		tag: typeof data.tag === 'string' && data.tag !== '' ? data.tag : 'notifications',
		icon: '/ui/brand/aloha-mark.svg',
		badge: '/ui/brand/aloha-mark.svg',
		data: { url: typeof data.url === 'string' ? data.url : '#/notifications' },
	};

	event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
	event.notification.close();
	const url = (event.notification.data && event.notification.data.url) || '#/notifications';

	event.waitUntil(
		self.clients
			.matchAll({ type: 'window', includeUncontrolled: true })
			.then((windowClients) => {
				for (const client of windowClients) {
					if ('focus' in client) {
						client.navigate(url);
						return client.focus();
					}
				}

				return self.clients.openWindow(url);
			}),
	);
});
