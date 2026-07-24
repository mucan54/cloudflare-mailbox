<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cloudflare_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('cf_id')->nullable();       // Cloudflare rule id (tag)
            $table->string('name')->nullable();
            $table->string('matcher')->nullable();     // local part / full address matched
            $table->json('actions')->nullable();       // forward/worker/drop
            $table->boolean('enabled')->default(true);
            $table->integer('priority')->default(0);
            $table->boolean('is_catch_all')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routing_rules');
    }
};
