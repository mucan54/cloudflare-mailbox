// Mailbox PWA service worker (root scope). Handles install/activate, a tiny
// app-shell cache, and Web Push notifications.
const CACHE = 'mailbox-v7';

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

    event.waitUntil((async () => {
        // Show the notification FIRST and await it. iOS penalises/revokes push
        // permission when a push doesn't yield a user-visible notification, so
        // this must always run and must never be blocked (or made to reject) by
        // the best-effort work below.
        try {
            await self.registration.showNotification(payload.title || 'Yeni mail', {
                body: payload.body || '',
                icon: payload.icon || '/icons/icon-192.png',
                badge: payload.badge || '/icons/icon-192.png',
                tag: payload.tag,
                data: payload.data || {},
            });
        } catch (_) {
            // ignore
        }

        // App-icon badge (best-effort).
        const unread = payload.data && payload.data.unread;
        if (typeof unread === 'number' && self.navigator) {
            try {
                if (unread > 0 && self.navigator.setAppBadge) await self.navigator.setAppBadge(unread);
                else if (self.navigator.clearAppBadge) await self.navigator.clearAppBadge();
            } catch (_) {
                // ignore
            }
        }

        // Ping open pages to refresh instantly (best-effort).
        try {
            const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
            clients.forEach((c) => c.postMessage({ type: 'new-mail', data: payload.data || {} }));
        } catch (_) {
            // ignore
        }
    })());
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/';
    event.waitUntil(
        (async () => {
            const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
            for (const client of clients) {
                // App already open: tell it to navigate in-app (SPA router) for a
                // smooth transition, then focus the window.
                client.postMessage({ type: 'open-mail', url });
                if ('focus' in client) {
                    try {
                        await client.focus();
                    } catch (_) {
                        // ignore
                    }
                    return;
                }
            }
            // App closed: open a new window straight at the message URL.
            if (self.clients.openWindow) return self.clients.openWindow(url);
        })(),
    );
});
