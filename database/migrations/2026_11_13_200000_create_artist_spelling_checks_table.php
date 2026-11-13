<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interpreten › Schreibweise › „Claude fragen“: welche Schreibweise stimmt – unsere, die von MusicBrainz oder eine
 * dritte? Gilt nur, solange beide Schreibweisen noch dieselben sind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artist_spelling_checks', function (Blueprint $t) {
            $t->foreignId('artist_id')->primary()->constrained()->cascadeOnDelete();
            $t->string('ours', 255);                    // unser Name beim Fragen
            $t->string('theirs', 255);                  // Schreibweise von MusicBrainz beim Fragen
            $t->string('spelling', 255)->nullable();    // Claudes Antwort
            $t->string('source', 16)->nullable();       // ours, musicbrainz, other
            $t->text('reason')->nullable();
            $t->string('model', 64)->nullable();
            $t->string('error', 512)->nullable();
            $t->timestamp('asked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_spelling_checks');
    }
};
