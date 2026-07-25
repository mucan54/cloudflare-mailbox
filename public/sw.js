// Mailbox PWA service worker (root scope). Handles install/activate, a tiny
// app-shell cache, and Web Push notifications.
const CACHE = 'mailbox-v8';

self.addEventListener('install', (event) => {
    self.skipWaiting();
    event.waitUntil(caches.open(CACHE).then((c) => c.addAll(['/'])).catch(() => {}));
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        // Drop previous caches so a new build never serves an old build's shell
        // or assets.
        const keys = await caches.keys();
        await Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)));
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;

    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return; // leave cross-origin alone
    if (url.pathname.startsWith('/api/')) return; // never cache the API

    // Page navigations: network-first so a fresh deploy loads immediately; fall
    // back to the cached shell only when genuinely offline.
    if (req.mode === 'navigate') {
        event.respondWith((async () => {
            try {
                return await fetch(req);
            } catch (_) {
                return (await caches.match('/')) || Response.error();
            }
        })());
        return;
    }

    // Assets (hashed JS/CSS/images): cache-first, then network. CRITICAL: on a
    // failed asset we return a network error, NEVER the HTML shell — returning
    // HTML for a <script>/<link> is exactly what caused the white screen.
    event.respondWith((async () => {
        const cached = await caches.match(req);
        if (cached) return cached;
        try {
            const res = await fetch(req);
            if (res && res.ok && res.type === 'basic') {
                const clone = res.clone();
                caches.open(CACHE).then((c) => c.put(req, clone)).catch(() => {});
            }
            return res;
        } catch (_) {
            return Response.error();
        }
    })());
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
