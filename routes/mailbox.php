<?php

use App\Http\Controllers\Mailbox\AuthController;
use App\Http\Controllers\Mailbox\InboxController;
use App\Http\Controllers\Mailbox\PushController;
use App\Http\Controllers\Mailbox\SendController;
use Illuminate\Support\Facades\Route;

/*
 * Headless mailbox API (Vue SPA + future mobile). Prefixed with /api/mailbox.
 * Auth is a Sanctum bearer token issued by POST /login.
 */

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/password', [AuthController::class, 'updatePassword']);

    Route::get('/emails', [InboxController::class, 'index']);
    Route::get('/emails/{email}', [InboxController::class, 'show']);
    Route::patch('/emails/{email}', [InboxController::class, 'update']);

    Route::get('/sent', [SendController::class, 'index']);
    Route::post('/send', [SendController::class, 'store']);

    Route::post('/push-subscribe', [PushController::class, 'subscribe']);
    Route::delete('/push-subscribe', [PushController::class, 'unsubscribe']);
});
