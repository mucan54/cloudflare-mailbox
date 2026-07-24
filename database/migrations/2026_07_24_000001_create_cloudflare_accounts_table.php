<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloudflare_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('account_id')->nullable();      // Cloudflare account id
            $table->text('api_token')->nullable();          // encrypted
            $table->text('webhook_secret')->nullable();     // encrypted
            $table->string('sending_driver')->default('api'); // api|smtp
            $table->timestamp('worker_deployed_at')->nullable();
            $table->string('worker_config_hash')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloudflare_accounts');
    }
};
