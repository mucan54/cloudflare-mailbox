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
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    <meta name="vapid-key" content="{{ config('webpush.vapid.public_key') }}">
    <title>Mailbox</title>
    <script>
        // Safety net for the occasional white screen: if a script or stylesheet
        // fails to load (network blip / stale asset after deploy), reload once
        // per session — the retry loads the fresh assets. Guarded so it can
        // never loop.
        (function () {
            window.addEventListener('error', function (e) {
                var t = e && e.target;
                if (!t || (t.tagName !== 'SCRIPT' && t.tagName !== 'LINK')) return;
                if (sessionStorage.getItem('bootReload')) return;
                sessionStorage.setItem('bootReload', '1');
                location.reload();
            }, true);
            window.addEventListener('load', function () {
                setTimeout(function () { sessionStorage.removeItem('bootReload'); }, 3000);
            });
        })();
    </script>
    @vite('resources/mailbox/main.js')
</head>
<body>
    <div id="mailbox-app"></div>
</body>
</html>
