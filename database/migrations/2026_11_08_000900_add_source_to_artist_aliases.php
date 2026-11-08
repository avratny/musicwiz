<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Herkunft einer Schreibweise: null = von Hand, "musicbrainz" = als Alias bei MusicBrainz hinterlegt
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artist_aliases', function (Blueprint $t) {
            $t->string('source')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('artist_aliases', function (Blueprint $t) {
            $t->dropColumn('source');
        });
    }
};
