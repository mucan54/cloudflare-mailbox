<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cloudflare_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('mailbox_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ingest_key')->unique();      // sha256(account+message_id+to) — idempotency
            $table->string('message_id')->nullable();
            $table->string('in_reply_to')->nullable();
            $table->json('references')->nullable();
            $table->string('from_name')->nullable();
            $table->string('from_email')->nullable();
            $table->string('to_email')->nullable();
            $table->json('cc')->nullable();
            $table->string('subject')->nullable();
            $table->longText('text_body')->nullable();
            $table->longText('html_body')->nullable();
            $table->json('headers')->nullable();
            $table->unsignedBigInteger('raw_size')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->boolean('starred')->default(false);
            $table->string('folder')->default('inbox');
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index(['mailbox_id', 'received_at']);
            $table->index(['cloudflare_account_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emails');
    }
};
