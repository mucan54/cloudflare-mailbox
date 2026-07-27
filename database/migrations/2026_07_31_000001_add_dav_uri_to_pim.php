<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The DAV object URI a client chose for an event/contact (e.g. <uid>.ics), so
// CalDAV/CardDAV GET/PUT/DELETE round-trips at the same path.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('dav_uri')->nullable()->index();
        });
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('dav_uri')->nullable()->index();
            $table->string('dav_etag')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('events', fn (Blueprint $t) => $t->dropColumn('dav_uri'));
        Schema::table('contacts', fn (Blueprint $t) => $t->dropColumn(['dav_uri', 'dav_etag']));
    }
};
