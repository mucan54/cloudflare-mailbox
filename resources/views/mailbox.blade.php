<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    {{-- Lock scale so tapping/double-tapping never zooms — native app feel. The
         16px input rule already blocks focus-zoom; this covers pinch/double-tap. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#171a21" media="(prefers-color-scheme: dark)">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    {{-- "default" keeps app content below the status bar so the top bar/menu stay tappable. --}}
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Mailbox">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" type="image/svg+xml" href="/icons/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32.png">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    <meta name="vapid-key" content="{{ config('webpush.vapid.public_key') }}">
    <title>Mailbox</title>
    <script>
        // Boot recovery for the first-launch white screen. A freshly installed
        // iOS PWA has its own empty storage — no service worker yet — so the
        // very first launch loads straight from the network on a cold standalone
        // web view, whose first asset fetch often fails or hangs. Retry a few
        // times with backoff (letting the network warm up) instead of once, so
        // the user doesn't have to keep force-quitting and reopening. Two
        // triggers: a <script>/<link> that fails to load, and — belt & braces —
        // the app root still being empty a few seconds in (script loaded but
        // boot silently failed). Capped so it can never loop.
        (function () {
            var KEY = 'bootReloads', MAX = 4;
            function tries() { return parseInt(sessionStorage.getItem(KEY) || '0', 10); }
            function retry() {
                var n = tries();
                if (n >= MAX) return;
                sessionStorage.setItem(KEY, String(n + 1));
                setTimeout(function () { location.reload(); }, 600 * (n + 1));
            }
            window.addEventListener('error', function (e) {
                var t = e && e.target;
                if (t && (t.tagName === 'SCRIPT' || t.tagName === 'LINK')) retry();
            }, true);
            window.addEventListener('DOMContentLoaded', function () {
                setTimeout(function () {
                    var el = document.getElementById('mailbox-app');
                    if (el && el.childElementCount === 0) retry();
                }, 4000);
            });
            // Booted successfully → clear the counter so later failures get a
            // fresh set of retries.
            window.addEventListener('load', function () {
                setTimeout(function () {
                    if (document.getElementById('mailbox-app').childElementCount > 0) {
                        sessionStorage.removeItem(KEY);
                    }
                }, 4500);
            });
        })();
    </script>
    @vite('resources/mailbox/main.js')
</head>
<body>
    <div id="mailbox-app"></div>
</body>
</html>
