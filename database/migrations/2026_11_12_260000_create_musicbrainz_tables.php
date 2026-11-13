<?php

use App\Support\MusicBrainzData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Lokale MusicBrainz-Daten (Einstellungen › MusicBrainz): alle Interpreten mit Typ, Land, Zusatz, Genres, Verweisen
 * (Spotify, Discogs, Wikidata) und Zahl der Veröffentlichungen; dazu Namen und Schreibweisen mit Vergleichsschlüssel
 * und die Mitglieder von Gruppen.
 * Wird beim Aktualisieren in Zwischentabellen geladen und dann in einem Schritt ausgetauscht.
 */
return new class extends Migration
{
    public function up(): void
    {
        MusicBrainzData::createTables('');
    }

    public function down(): void
    {
        Schema::dropIfExists('mb_members');
        Schema::dropIfExists('mb_names');
        Schema::dropIfExists('mb_artists');
    }
};
