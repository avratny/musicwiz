<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Eigenes Kategorie-Feld (MUSICWIZ_CATEGORY, zusätzlich zum Kommentar) und Sortiernamen (ARTISTSORT/ALBUMARTISTSORT) im
 * Index, dazu ein eigener Sortiername je Künstler. mtime 0: der nächste Scan liest alle Dateien einmal neu (nur lesen).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $t) {
            $t->string('category')->nullable();
            $t->string('artistsort')->nullable();
            $t->string('albumartistsort')->nullable();
        });
        Schema::table('artists', function (Blueprint $t) {
            $t->string('sort_name')->nullable();
        });
        DB::table('tracks')->update(['mtime' => 0]);
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $t) {
            $t->dropColumn(['category', 'artistsort', 'albumartistsort']);
        });
        Schema::table('artists', function (Blueprint $t) {
            $t->dropColumn('sort_name');
        });
    }
};
