<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ReplayGain und Songtext im Index (für „ReplayGain fehlt“ / „Songtext fehlt“). Vorhandene Dateien kennt der Index nur
 * ohne diese Werte – mtime 0 sorgt dafür, dass der nächste Scan alle Dateien einmal neu liest (nur lesen).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $t) {
            $t->string('replaygain', 32)->nullable();
            $t->string('replaygain_album', 32)->nullable();
            $t->boolean('has_lyrics')->default(false);
        });
        DB::table('tracks')->update(['mtime' => 0]);
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $t) {
            $t->dropColumn(['replaygain', 'replaygain_album', 'has_lyrics']);
        });
    }
};
