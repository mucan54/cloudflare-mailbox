<?php

use App\Http\Controllers\IncomingEmailController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Inbound Email Worker -> Laravel webhook (HMAC-authenticated, CSRF-exempt).
Route::post('/api/cf/incoming', IncomingEmailController::class)
    ->middleware('throttle:120,1');
