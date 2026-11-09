<?php

use App\Models\Artist;
use App\Support\ArtistDirectory;
use App\Support\ArtistNames;
use Illuminate\Database\Migrations\Migration;

/**
 * Einträge mit typografischen Satzzeichen aus MusicBrainz („Lexy & K‐Paul“, „Arpa’s Dream“) auf die üblichen Zeichen
 * umbenennen – der alte Name bleibt Schreibweise. Die Tags im Bestand haben ohnehin die normalen Zeichen.
 */
return new class extends Migration
{
    public function up(): void
    {
        $directory = app(ArtistDirectory::class);
        foreach (Artist::all() as $artist) {
            $plain = ArtistNames::plainPunctuation($artist->name);
            if ($plain !== $artist->name) {
                $directory->rename($artist, $plain);
            }
        }
    }

    public function down(): void {}
};
