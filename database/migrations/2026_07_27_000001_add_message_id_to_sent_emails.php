<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sent_emails', function (Blueprint $table) {
            // RFC 5322 threading headers for our own outgoing mail, so replies
            // to messages we send thread back correctly (matching the headers
            // we store on received mail).
            $table->string('message_id')->nullable()->after('subject')->index();
            $table->string('in_reply_to')->nullable()->after('message_id');
            $table->json('references')->nullable()->after('in_reply_to');
        });
    }

    public function down(): void
    {
        Schema::table('sent_emails', function (Blueprint $table) {
            $table->dropColumn(['message_id', 'in_reply_to', 'references']);
        });
    }
};
