<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Existing subscriptions were stored with the legacy "aesgcm" content encoding,
 * which Apple's iOS/Safari Web Push cannot decrypt (it only supports the RFC
 * 8291 "aes128gcm"). Move them to aes128gcm so already-registered devices start
 * receiving notifications without waiting for a re-subscribe. Every current
 * browser supports aes128gcm.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('push_subscriptions')) {
            return;
        }

        DB::table('push_subscriptions')
            ->where('content_encoding', 'aesgcm')
            ->update(['content_encoding' => 'aes128gcm']);
    }

    public function down(): void
    {
        // No-op: reverting to the broken encoding is intentionally not supported.
    }
};
