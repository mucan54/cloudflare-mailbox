<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            // Profile photo: where the normalized image lives on the avatars
            // disk, plus a stable, unguessable token for its public URL
            // (/avatars/{token}) so links already sent in email keep
            // working after the photo is replaced.
            $table->string('avatar_path')->nullable()->after('signature');
            $table->string('avatar_token', 64)->nullable()->unique()->after('avatar_path');
            // Also show the photo next to the signature in outgoing mail.
            $table->boolean('avatar_in_signature')->default(false)->after('avatar_token');
        });

        Schema::table('emails', function (Blueprint $table) {
            // The mailbox on this server that sent the message — set only when
            // Cloudflare verified the From domain (DMARC pass), so a spoofed
            // From never borrows someone's photo.
            $table->foreignId('from_mailbox_id')->nullable()->after('mailbox_id')
                ->constrained('mailboxes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropConstrainedForeignId('from_mailbox_id');
        });

        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropUnique(['avatar_token']);
            $table->dropColumn(['avatar_path', 'avatar_token', 'avatar_in_signature']);
        });
    }
};
