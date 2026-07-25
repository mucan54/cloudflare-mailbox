<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One physical device (one push endpoint) must be registerable under EVERY
 * logged-in mailbox so mail to any account notifies it. The package ships a
 * GLOBAL unique index on `endpoint`, which forced a single owner — each
 * account's subscribe stole the endpoint from the previous one, so only the
 * last account could ever be pushed to. Drop the global unique; uniqueness per
 * (mailbox, endpoint) is enforced in application code via a scoped
 * updateOrCreate.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('webpush.table_name', 'push_subscriptions');
        $connection = config('webpush.database_connection');

        if (! Schema::connection($connection)->hasTable($table)) {
            return;
        }

        Schema::connection($connection)->table($table, function (Blueprint $table) {
            $table->dropUnique('push_subscriptions_endpoint_unique');
        });

        // A non-unique index still keeps endpoint lookups fast.
        Schema::connection($connection)->table($table, function (Blueprint $table) {
            $table->index('endpoint', 'push_subscriptions_endpoint_index');
        });
    }

    public function down(): void
    {
        $table = config('webpush.table_name', 'push_subscriptions');
        $connection = config('webpush.database_connection');

        if (! Schema::connection($connection)->hasTable($table)) {
            return;
        }

        Schema::connection($connection)->table($table, function (Blueprint $table) {
            $table->dropIndex('push_subscriptions_endpoint_index');
            $table->unique('endpoint', 'push_subscriptions_endpoint_unique');
        });
    }
};
