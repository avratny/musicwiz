<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nur noch MP3 und FLAC: Einträge anderer Formate (m4a, ogg, wma …) samt Vormerkungen und Verlauf aus der Datenbank
 * entfernen – die Dateien bleiben unberührt. Ohne das gälten sie beim nächsten Einlesen als „fehlt“ (30-%-Sperre).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tracks')->whereNotIn('ext', ['mp3', 'flac'])->delete();
    }

    public function down(): void
    {
        // die Einträge kommen nicht zurück – Formate wieder erlauben (config) und neu einlesen
    }
};
