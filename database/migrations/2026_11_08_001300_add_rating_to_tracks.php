<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Bewertung (1–5 Sterne) aus den Tags – MP3: POPM (wie MediaMonkey), FLAC: RATING.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $t) {
            $t->unsignedTinyInteger('rating')->nullable()->index();
        });
        // alle Dateien beim nächsten Scan neu einlesen, damit vorhandene Bewertungen (z. B. aus MediaMonkey) ankommen
        DB::table('tracks')->update(['mtime' => 0]);
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $t) {
            $t->dropIndex(['rating']);
            $t->dropColumn('rating');
        });
    }
};
