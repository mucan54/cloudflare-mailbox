<?php

use App\Http\Controllers\AutodiscoverController;
use App\Http\Controllers\CalendarFeedController;
use App\Http\Controllers\DavController;
use App\Http\Controllers\IncomingEmailController;
use App\Http\Controllers\MobileConfigController;
use Illuminate\Support\Facades\Route;

// Inbound Email Worker -> Laravel webhook (HMAC-authenticated, CSRF-exempt).
Route::post('/api/cf/incoming', IncomingEmailController::class)
    ->middleware('throttle:120,1');

// Read-only calendar subscription feed (Apple/Google Calendar). Token-authed.
Route::get('/calendar/{token}.ics', CalendarFeedController::class)
    ->where('token', '[A-Za-z0-9]+');

// Mail-client auto-configuration (optional native-mail feature). Defined before
// the SPA catch-all so these exact paths win; the SPA's /mail/{id} still works.
Route::get('/mail/config-v1.1.xml', [AutodiscoverController::class, 'mozilla']);
Route::get('/.well-known/autoconfig/mail/config-v1.1.xml', [AutodiscoverController::class, 'mozilla']);
Route::match(['get', 'post'], '/autodiscover/autodiscover.xml', [AutodiscoverController::class, 'outlook']);
Route::match(['get', 'post'], '/Autodiscover/Autodiscover.xml', [AutodiscoverController::class, 'outlook']);

// CalDAV + CardDAV server (sabre/dav). Optional: only mounted when the DAV
// feature is enabled, so a plain Laravel deploy is unaffected. All WebDAV
// methods route to one handler.
if (config('cloudflare.mail_client.dav')) {
    $davMethods = ['GET', 'POST', 'PUT', 'DELETE', 'HEAD', 'OPTIONS', 'PROPFIND', 'PROPPATCH', 'MKCOL', 'MKCALENDAR', 'COPY', 'MOVE', 'REPORT', 'ACL', 'LOCK', 'UNLOCK'];
    Route::match($davMethods, '/dav', [DavController::class, 'handle']);
    Route::match($davMethods, '/dav/{path}', [DavController::class, 'handle'])->where('path', '.*');

    // RFC 6764 auto-discovery: email + password is enough for iOS / DAVx5.
    Route::match(['get', 'propfind'], '/.well-known/caldav', fn () => redirect('/dav/', 301));
    Route::match(['get', 'propfind'], '/.well-known/carddav', fn () => redirect('/dav/', 301));

    // Per-mailbox Apple configuration profile (Mail + Calendar + Contacts).
    Route::get('/mail/profile/{mailbox}.mobileconfig', [MobileConfigController::class, 'show'])
        ->where('mailbox', '[^/]+')
        ->name('mobileconfig');
}

// UI language switch (admin panel).
Route::get('/locale/{locale}', function (string $locale) {
    if (array_key_exists($locale, (array) config('app.available_locales'))) {
        session(['locale' => $locale]);
    }

    return back();
})->middleware('web')->name('locale.switch');

// Mailbox portal — the Vue SPA (PWA). Everything not owned by the admin panel,
// the API, Livewire, static assets, or health is handled client-side.
Route::view('/{any?}', 'mailbox')
    ->where('any', '^(?!admin|api|livewire|build|storage|up|locale|calendar|sw\.js|manifest\.webmanifest|icons|favicon).*$');
