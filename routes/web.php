<?php

use App\Http\Controllers\CalendarFeedController;
use App\Http\Controllers\IncomingEmailController;
use Illuminate\Support\Facades\Route;

// Inbound Email Worker -> Laravel webhook (HMAC-authenticated, CSRF-exempt).
Route::post('/api/cf/incoming', IncomingEmailController::class)
    ->middleware('throttle:120,1');

// Read-only calendar subscription feed (Apple/Google Calendar). Token-authed.
Route::get('/calendar/{token}.ics', CalendarFeedController::class)
    ->where('token', '[A-Za-z0-9]+');

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
