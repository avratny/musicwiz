<?php

use App\Support\ArtistGenres;
use App\Support\GenreTree;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Genrebaum (Hauptgenre › Familie › Style) mit Synonymen und Beatport-/Discogs-Zuordnung – Startstand aus
 * database/data/genres.csv, danach in der App gepflegt. Dazu die Verknüpfung Interpret ↔ Genre (jede Ebene, mit Quelle).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('genres', function (Blueprint $t) {
            $t->id();
            $t->foreignId('parent_id')->nullable()->constrained('genres')->restrictOnDelete();
            $t->string('name', 128);
            $t->unsignedTinyInteger('level');           // 1 Hauptgenre, 2 Familie, 3 Style
            $t->unsignedSmallInteger('position')->default(0);
            $t->string('beatport', 128)->nullable();
            $t->string('discogs', 128)->nullable();
            $t->timestamps();
            $t->unique(['parent_id', 'name']);
        });

        Schema::create('genre_synonyms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('genre_id')->constrained()->cascadeOnDelete();
            $t->string('synonym', 128);
            $t->unique(['genre_id', 'synonym']);
            $t->index('synonym');
        });

        Schema::create('artist_genre', function (Blueprint $t) {
            $t->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $t->foreignId('genre_id')->constrained()->cascadeOnDelete();
            $t->string('source', 16);                   // manual, tags, musicbrainz, spotify, discogs, audiodb
            $t->unsignedInteger('weight')->default(1);  // z. B. Anzahl Lieder mit diesem Genre
            $t->primary(['artist_id', 'genre_id', 'source']);
            $t->index('genre_id');
        });

        app(GenreTree::class)->importCsv(database_path('data/genres.csv'));
        app(ArtistGenres::class)->syncAll();
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_genre');
        Schema::dropIfExists('genre_synonyms');
        Schema::dropIfExists('genres');
    }
};
