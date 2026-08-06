// Mailbox PWA service worker (root scope). Handles install/activate, an
// app-shell cache, and Web Push notifications.
const CACHE = 'mailbox-v12';
const SHELL = '/';

// Shown ONLY when a navigation has neither network nor a cached shell: a
// spinner that reloads itself, so a cold/evicted PWA on a momentarily dead
// network shows "reconnecting" and self-heals instead of a permanent white
// screen requiring a force-quit.
const RECONNECT_HTML = '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mailbox</title><style>html,body{height:100%;margin:0}body{display:flex;align-items:center;justify-content:center;background:#faf9f8;color:#605e5c;font-family:-apple-system,system-ui,sans-serif}.s{width:30px;height:30px;border:3px solid currentColor;border-top-color:transparent;border-radius:50%;animation:r 1s linear infinite}@keyframes r{to{transform:rotate(360deg)}}@media(prefers-color-scheme:dark){body{background:#1b1a19;color:#a19f9d}}</style></head><body><div class="s" role="status" aria-label="Yukleniyor"></div><script>setTimeout(function(){location.reload()},2000)</script></body></html>';

function reconnectResponse() {
    return new Response(RECONNECT_HTML, {
        status: 200,
        headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' },
    });
}

// Retry a request over a few seconds — the just-woken network on resume fails
// the first fetch instantly, so a single try would white-screen; retries let it
// recover. Used for assets (hashed → immutable, so re-fetching is always safe).
async function fetchWithRetry(req, tries = 4, delayMs = 700) {
    let lastErr;
    for (let i = 0; i < tries; i++) {
        try {
            const res = await fetch(req);
            // Success or a non-retryable (opaque / 4xx) response — return it.
            // Retry only on transient 5xx or a thrown network error.
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
// a fresh install can boot offline with a COMPLETE, consistent cache.
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
    if (url.pathname.startsWith('/api/')) return;     // never cache the API

    // Page navigations: CACHE-FIRST for the app shell.
    //
    // This is what makes a resumed PWA boot reliably. When iOS jettisons the web
    // view, reopening does a fresh navigation while the SW is cold-starting and
    // the network stack is still waking up — a network-first fetch there hangs
    // or fails and leaves a white screen. Serving the cached shell instantly
    // boots the app with ZERO network dependency (offline-capable), then we
    // revalidate in the background so the next launch is fresh. The shell and
    // its hashed assets are cached together atomically (assets first, then the
    // shell), so the cached shell always has matching cached assets. Only when
    // there's no cached shell at all (first run / evicted) do we hit the network
    // — with retries, then a self-reloading spinner rather than a dead blank.
    if (req.mode === 'navigate') {
        event.respondWith((async () => {
            const cached = await caches.match(SHELL);
            const revalidate = (async () => {
                try {
                    const res = await fetch(req);
                    if (res && res.ok && res.type === 'basic') {
                        const cache = await caches.open(CACHE);
                        if (await precacheBuild(cache)) await cache.put(SHELL, res.clone());
                    }
                } catch (_) {
                    // Offline / cold network — keep serving the cached shell.
                }
            })();
            if (cached) {
                event.waitUntil(revalidate);
                return cached;
            }
            // Nothing cached yet: network with retries, then the spinner.
            try {
                const res = await fetchWithRetry(req);
                if (res && res.ok && res.type === 'basic') {
                    const cache = await caches.open(CACHE);
                    if (await precacheBuild(cache)) cache.put(SHELL, res.clone()).catch(() => {});
                }
                return res;
            } catch (_) {
                return reconnectResponse();
            }
        })());
        return;
    }

    // Assets (hashed JS/CSS/images): cache-first, then network (with retries).
    // CRITICAL: on a failed asset return a network error, NEVER the HTML shell —
    // returning HTML for a <script>/<link> is itself a white-screen cause. The
    // retries cover the flaky just-resumed network so an evicted asset still
    // loads instead of leaving the app blank.
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
