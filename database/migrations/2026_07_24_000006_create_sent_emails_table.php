<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sent_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cloudflare_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('mailbox_id')->nullable();
            $table->string('driver')->default('api'); // api|smtp
            $table->string('from_email');
            $table->json('to');
            $table->json('cc')->nullable();
            $table->json('bcc')->nullable();
            $table->string('reply_to')->nullable();
            $table->string('subject')->nullable();
            $table->longText('html_body')->nullable();
            $table->longText('text_body')->nullable();
            $table->string('status')->default('queued'); // queued|delivered|bounced|failed
            $table->json('cf_response')->nullable();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('in_reply_to_email_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['cloudflare_account_id', 'status']);
            $table->index('mailbox_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_emails');
    }
};
