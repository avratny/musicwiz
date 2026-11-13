<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Album unvollständig: vorgemerkte Datei-Aktionen (Lied in ein Album kopieren bzw. verschieben – ausgeführt erst beim
 * Schreiben), gewollt unvollständige Alben und die gewählte Titelliste je Album (aus MusicBrainz, Deezer oder iTunes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_plans', function (Blueprint $t) {
            $t->id();
            $t->string('action', 8);                            // copy, move
            $t->foreignId('track_id')->constrained()->cascadeOnDelete();
            $t->char('target_album_hash', 40);
            $t->string('target_location', 16);
            $t->json('values');                                 // Tags im Ziel-Album (Album, Nummer, CD …)
            $t->timestamps();

            $t->unique(['track_id', 'target_album_hash']);
            $t->index('target_album_hash');
        });

        Schema::create('album_exemptions', function (Blueprint $t) {
            $t->id();
            $t->char('album_hash', 40);
            $t->string('check', 32);                            // incomplete
            $t->timestamp('created_at')->useCurrent();

            $t->unique(['album_hash', 'check']);
        });

        Schema::create('album_tracklists', function (Blueprint $t) {
            $t->id();
            $t->char('album_hash', 40)->unique();
            $t->json('candidates');                             // gefundene Veröffentlichungen mit Titelliste und Übereinstimmung
            $t->unsignedSmallInteger('chosen')->nullable();     // gewählte (Index in candidates), sonst die beste sichere
            $t->text('errors')->nullable();
            $t->timestamp('fetched_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('album_tracklists');
        Schema::dropIfExists('album_exemptions');
        Schema::dropIfExists('file_plans');
    }
};
