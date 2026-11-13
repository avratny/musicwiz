<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abgleich der Interpreten mit MusicBrainz, Spotify, Discogs, TheAudioDB und Fanart.tv (Zwischenspeicher):
 * Kandidaten je Dienst, Schreibweisen laut MusicBrainz/Discogs, Bilder (Vorschau lokal). Am Interpreten die gewählten IDs.
 * Dazu Hintergrundvorgänge für die Leiste über dem Player.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artists', function (Blueprint $t) {
            $t->string('mbid', 36)->nullable()->after('image');
            $t->string('discogs_id', 32)->nullable()->after('mbid');
            $t->string('spotify_id', 32)->nullable()->after('discogs_id');
            $t->timestamp('looked_up_at')->nullable()->after('spotify_id');
        });

        Schema::create('artist_sources', function (Blueprint $t) {
            $t->id();
            $t->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $t->string('service', 16);                  // musicbrainz, spotify, discogs, audiodb, fanart
            $t->string('external_id', 64);
            $t->string('name', 255)->nullable();        // wie der Dienst ihn schreibt
            $t->string('match', 16)->nullable();        // exakt, gross_klein, aehnlich, verknuepft (über MusicBrainz-Link)
            $t->unsignedTinyInteger('score')->default(0);
            $t->string('mbid', 36)->nullable();         // zu welchem MusicBrainz-Eintrag (Gruppierung)
            $t->boolean('chosen')->default(false);
            $t->json('data')->nullable();               // Genres, Land, Typ, Zusatz, Popularität, Bio, Links …
            $t->timestamp('fetched_at')->nullable();
            $t->timestamps();
            $t->unique(['artist_id', 'service', 'external_id']);
            $t->index(['service', 'external_id']);
        });

        Schema::create('artist_source_aliases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('artist_source_id')->constrained()->cascadeOnDelete();
            $t->string('alias', 255);
            $t->string('type', 32)->nullable();         // Artist name, Search hint, Legal name, Name variation …
            $t->string('locale', 16)->nullable();
            $t->boolean('primary')->default(false);
            $t->index('alias');
        });

        Schema::create('artist_images', function (Blueprint $t) {
            $t->id();
            $t->foreignId('artist_source_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 16)->default('portrait'); // portrait, background, logo
            $t->text('url');                             // Original
            $t->text('preview_url')->nullable();
            $t->unsignedSmallInteger('width')->nullable();
            $t->unsignedSmallInteger('height')->nullable();
            $t->unsignedTinyInteger('rank')->default(0);
            $t->string('preview', 255)->nullable();      // lokale Vorschau (storage/app/artist-previews)
        });

        // Status je Interpret und Dienst (auch „nicht gefunden“ / Fehler), damit nicht ständig neu gefragt wird
        Schema::create('artist_lookups', function (Blueprint $t) {
            $t->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $t->string('service', 16);
            $t->string('status', 16);                   // ok, nicht_gefunden, fehler, uebersprungen
            $t->string('message', 255)->nullable();
            $t->timestamp('fetched_at');
            $t->primary(['artist_id', 'service']);
        });

        Schema::create('activities', function (Blueprint $t) {
            $t->string('key', 32)->primary();           // scan, lookup …
            $t->string('label', 255);
            $t->unsignedInteger('done')->default(0);
            $t->unsignedInteger('total')->default(0);
            $t->boolean('running')->default(true);
            $t->string('detail', 255)->nullable();      // z. B. „MusicBrainz 1/s · noch ca. 40 min“
            $t->string('message', 255)->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
        Schema::dropIfExists('artist_lookups');
        Schema::dropIfExists('artist_images');
        Schema::dropIfExists('artist_source_aliases');
        Schema::dropIfExists('artist_sources');
        Schema::table('artists', fn (Blueprint $t) => $t->dropColumn(['mbid', 'discogs_id', 'spotify_id', 'looked_up_at']));
    }
};
