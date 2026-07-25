<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
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
    @vite('resources/mailbox/main.js')
</head>
<body>
    <div id="mailbox-app"></div>
</body>
</html>
