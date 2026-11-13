<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Beleg über die eigenen Lieder (Interpreten › Wahrscheinlich › „Lieder prüfen“): bei welchem MusicBrainz-Eintrag
 * Aufnahmen mit unseren Titeln stehen – entscheidet zwischen Gleichnamigen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artist_recording_checks', function (Blueprint $t) {
            $t->foreignId('artist_id')->primary()->constrained()->cascadeOnDelete();
            $t->json('hits');                           // MusicBrainz-ID => Zahl der gefundenen Titel
            $t->unsignedSmallInteger('titles')->default(0);   // so viele Titel gefragt
            $t->timestamp('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_recording_checks');
    }
};
