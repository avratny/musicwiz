<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Original-Datum, Veröffentlichungsdatum und MusicBrainz-Album-ID im Index: danach trennt Navidrome Alben – „Album
 * uneinheitlich“ meldet Abweichungen. mtime 0: der nächste Scan liest alle Dateien einmal neu (nur lesen).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $t) {
            $t->string('originaldate')->nullable();
            $t->string('releasedate')->nullable();
            $t->string('mb_albumid')->nullable();
        });
        DB::table('tracks')->update(['mtime' => 0]);
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $t) {
            $t->dropColumn(['originaldate', 'releasedate', 'mb_albumid']);
        });
    }
};
