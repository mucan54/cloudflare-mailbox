// Mailbox PWA service worker (root scope). Handles install/activate, a tiny
// app-shell cache, and Web Push notifications.
const CACHE = 'mailbox-v2';

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
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            for (const client of clients) {
                if ('focus' in client) {
                    client.navigate(url);
                    return client.focus();
                }
            }
            return self.clients.openWindow(url);
        }),
    );
});
