<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Herkunft aus dem Tag SOURCE (YouTube-Downloads), z. B. für den Filter „Von YouTube“.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $t) {
            $t->string('source')->nullable()->index();
        });
        // Downloads sind MP3: diese beim nächsten Scan neu einlesen, damit die Herkunft auch für vorhandene Dateien da ist
        DB::table('tracks')->where('ext', 'mp3')->update(['mtime' => 0]);
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $t) {
            $t->dropIndex(['source']);
            $t->dropColumn('source');
        });
    }
};
