<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // ICS UID for dedup when the same invite arrives more than once, and
            // a stamp so the 30-minute push reminder is sent at most once.
            $table->string('source_uid')->nullable()->after('mailbox_id');
            $table->timestamp('reminded_at')->nullable();
            $table->index(['mailbox_id', 'source_uid']);
        });

        Schema::table('mailboxes', function (Blueprint $table) {
            // Secret token for the read-only .ics subscription feed.
            $table->string('calendar_token', 64)->nullable()->unique()->after('signature');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['mailbox_id', 'source_uid']);
            $table->dropColumn(['source_uid', 'reminded_at']);
        });
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn('calendar_token');
        });
    }
};
