<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destination_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cloudflare_account_id')->constrained()->cascadeOnDelete();
            $table->string('cf_id')->nullable();   // Cloudflare destination address id
            $table->string('email');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['cloudflare_account_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destination_addresses');
    }
};
