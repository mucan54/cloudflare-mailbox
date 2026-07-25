// Mailbox PWA service worker (root scope). Handles install/activate, a tiny
// app-shell cache, and Web Push notifications.
const CACHE = 'mailbox-v3';

self.addEventListener('install', (event) => {
    self.skipWaiting();
    event.waitUntil(caches.open(CACHE).then((c) => c.addAll(['/'])).catch(() => {}));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET' || new URL(req.url).pathname.startsWith('/api/')) return;
    event.respondWith(
        fetch(req).catch(() => caches.match(req).then((r) => r || caches.match('/'))),
    );
});

self.addEventListener('push', (event) => {
    let payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch (_) {
        payload = { title: 'Yeni mail' };
    }
    event.waitUntil(
        Promise.all([
            self.registration.showNotification(payload.title || 'Yeni mail', {
                body: payload.body || '',
                icon: payload.icon || '/icons/icon-192.png',
                badge: payload.badge || '/icons/icon-192.png',
                tag: payload.tag,
                data: payload.data || {},
            }),
            // Tell any open page to refresh instantly — real-time without polling.
            self.clients
                .matchAll({ type: 'window', includeUncontrolled: true })
                .then((clients) => {
                    clients.forEach((c) => c.postMessage({ type: 'new-mail', data: payload.data || {} }));
                }),
        ]),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/';
    event.waitUntil(
        (async () => {
            const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
            for (const client of clients) {
                if ('focus' in client) {
                    // App already open: navigate in-app (SPA router) for a smooth
                    // transition instead of a full reload.
                    client.postMessage({ type: 'open-mail', url });
                    try {
                        return await client.focus();
                    } catch (_) {
                        return;
                    }
                }
            }
            // App closed: open a new window straight at the message URL.
            return self.clients.openWindow(url);
        })(),
    );
});
