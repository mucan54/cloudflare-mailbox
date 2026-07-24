<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cloudflare_account_id')->constrained()->cascadeOnDelete();
            $table->string('zone_id')->nullable();
            $table->string('name');
            $table->string('status')->nullable();          // zone status
            $table->boolean('routing_enabled')->default(false);
            $table->boolean('sending_enabled')->default(false);
            $table->string('inbound_capture')->default('none'); // none|catch_all|per_address
            $table->json('dns_records')->nullable();        // expected/current MX/SPF/DKIM/DMARC + verified
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['cloudflare_account_id', 'zone_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
