// Mailbox PWA service worker (root scope). Handles install/activate, a tiny
// app-shell cache, and Web Push notifications.
const CACHE = 'mailbox-v10';
const SHELL = '/';

// On resume from the background, iOS may reload the PWA while the network is
// still coming back. A single fetch() fails instantly and would leave a white
// screen; retrying over a few seconds lets the just-woken network recover.
async function fetchWithRetry(req, tries = 4, delayMs = 700) {
    let lastErr;
    for (let i = 0; i < tries; i++) {
        try {
            const res = await fetch(req);
            // Success or a non-retryable (opaque / 4xx) response — return it.
            // Retry only on transient 5xx.
            if (res && (res.ok || res.type === 'opaque' || (res.status >= 400 && res.status < 500))) {
                return res;
            }
            lastErr = res;
        } catch (e) {
            lastErr = e;
        }
        if (i < tries - 1) await new Promise((r) => setTimeout(r, delayMs));
    }
    if (lastErr instanceof Response) return lastErr;
    throw lastErr || new Error('fetch failed');
}

// Precache every hashed asset of the current build (from the Vite manifest) so
// a resumed PWA always has a COMPLETE, consistent cache — shell + its assets —
// and can boot instantly offline. This is what stops the white screen when iOS
// reloads the app on resume from the background.
async function precacheBuild(cache) {
    try {
        const res = await fetch('/build/manifest.json', { cache: 'no-cache' });
        if (!res.ok) return false;
        const manifest = await res.json();
        const urls = new Set();
        for (const key in manifest) {
            const entry = manifest[key];
            if (entry && entry.file) urls.add('/build/' + entry.file);
            if (entry && Array.isArray(entry.css)) entry.css.forEach((c) => urls.add('/build/' + c));
        }
        if (urls.size) await cache.addAll([...urls]);
        return true;
    } catch (_) {
        // Ignore — assets are still cached on demand by the fetch handler.
        return false;
    }
}

self.addEventListener('install', (event) => {
    self.skipWaiting();
    event.waitUntil((async () => {
        const cache = await caches.open(CACHE);
        await cache.add(SHELL).catch(() => {});
        await precacheBuild(cache);
    })());
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

    // Page navigations: serve the cached shell IMMEDIATELY, revalidate in the
    // background. iOS reloads the PWA when it's resumed from the background, and
    // a network-first await here would hang on the flaky just-resumed network,
    // leaving a white screen until it resolved. Cache-first boots instantly; the
    // fresh index and its assets are refreshed in the background for next time.
    if (req.mode === 'navigate') {
        event.respondWith((async () => {
            const cached = await caches.match(SHELL);
            // Refresh the shell + assets in the background so next boot is fresh.
            const refresh = (async () => {
                try {
                    const res = await fetchWithRetry(req);
                    if (res && res.ok && res.type === 'basic') {
                        // Cache the new assets first, then the shell — so the
                        // stored shell always has matching assets (never a shell
                        // that points at assets we failed to cache).
                        const cache = await caches.open(CACHE);
                        if (await precacheBuild(cache)) cache.put(SHELL, res.clone()).catch(() => {});
                    }
                    return res;
                } catch (_) {
                    return null;
                }
            })();
            // Cached shell boots instantly; only wait on the network (with
            // retries) when there's nothing cached — so a just-resumed PWA whose
            // cache was evicted retries instead of flashing a white screen.
            if (cached) {
                event.waitUntil(refresh);
                return cached;
            }
            return (await refresh) || Response.error();
        })());
        return;
    }

    // Assets (hashed JS/CSS/images): cache-first, then network (with retries).
    // CRITICAL: on a failed asset we return a network error, NEVER the HTML
    // shell — returning HTML for a <script>/<link> is exactly what caused the
    // white screen. The retries cover the flaky just-resumed network so an
    // evicted asset still loads instead of leaving the app blank.
    event.respondWith((async () => {
        const cached = await caches.match(req);
        if (cached) return cached;
        try {
            const res = await fetchWithRetry(req);
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
