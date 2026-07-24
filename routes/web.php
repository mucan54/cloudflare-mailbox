<?php

use App\Http\Controllers\IncomingEmailController;
use Illuminate\Support\Facades\Route;

// Inbound Email Worker -> Laravel webhook (HMAC-authenticated, CSRF-exempt).
Route::post('/api/cf/incoming', IncomingEmailController::class)
    ->middleware('throttle:120,1');

// Mailbox portal — the Vue SPA (PWA). Everything not owned by the admin panel,
// the API, Livewire, static assets, or health is handled client-side.
Route::view('/{any?}', 'mailbox')
    ->where('any', '^(?!admin|api|livewire|build|storage|up|sw\.js|manifest\.webmanifest|icons|favicon).*$');
