<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Namensprüfung für Kombis: kennt ein Dienst „Beam & Yanou“ als Ganzes bzw. „Beam“ und „Yanou“ einzeln? Je Name
 * (subject_type „name“) – oder bei Mitgliedern einer Gruppe je MusicBrainz-ID („mbid“, genau dieser Interpret) – und
 * Dienst der beste Treffer. Unabhängig vom Verzeichnis (die Teile gibt es dort oft noch nicht).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('name_checks', function (Blueprint $t) {
            $t->id();
            $t->string('subject_type', 8);              // name, mbid
            $t->string('subject', 255)->collation('utf8mb4_bin');
            $t->string('service', 16);
            $t->string('status', 16);                   // ok, nicht_gefunden, fehler, uebersprungen
            $t->string('found', 255)->nullable();       // so schreibt ihn der Dienst
            $t->string('match', 16)->nullable();        // exakt, gross_klein, zeichen, schreibweise, aehnlich
            $t->string('type', 32)->nullable();         // MusicBrainz: Person, Group …
            $t->unsignedInteger('releases')->nullable();            // MusicBrainz: Release-Groups, Discogs: Veröffentlichungen
            $t->unsignedTinyInteger('popularity')->nullable();      // Spotify 0–100
            $t->unsignedInteger('listeners')->nullable();           // Last.fm
            $t->unsignedSmallInteger('namesakes')->nullable();      // MusicBrainz: so viele heißen genau so
            $t->char('mbid', 36)->nullable();                       // MusicBrainz: ID des Treffers
            $t->json('members')->nullable();                        // MusicBrainz, Gruppe: [{mbid, name, type, ended}]
            $t->string('url', 255)->nullable();
            $t->string('message', 255)->nullable();     // Grund bei Fehler
            $t->timestamp('checked_at');
            $t->unique(['subject_type', 'subject', 'service']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('name_checks');
    }
};
