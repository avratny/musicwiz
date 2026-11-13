<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jedes Lied eine Zeile: Grundfelder als Spalten (sortieren, filtern, bearbeiten), alle Tag-Felder der Datei roh als
 * JSON (Stand beim Einlesen), dazu Technik. Bearbeitet wird nur hier – was sich geändert hat, steht in tag_changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracks', function (Blueprint $t) {
            $t->id();
            $t->string('location', 16);                 // inbox, library, blackhole, trash
            $t->text('path');
            $t->char('path_hash', 40)->unique();        // sha1(path) – Pfade sind für einen Index zu lang
            $t->text('dir');
            $t->text('album_dir');                      // Album-Ordner (CD1/Disc 2 gehören zum Ordner darüber)
            $t->char('album_hash', 40);                 // Gruppierung nach Album
            $t->string('filename', 512);
            $t->string('ext', 16);
            $t->unsignedBigInteger('size');
            $t->unsignedInteger('mtime');

            $t->string('format', 16)->nullable();
            $t->float('duration')->nullable();
            $t->unsignedInteger('bitrate')->nullable();
            $t->unsignedInteger('sample_rate')->nullable();
            $t->unsignedTinyInteger('channels')->nullable();
            $t->unsignedTinyInteger('bits')->nullable();
            $t->boolean('has_cover')->default(false);
            $t->string('error', 512)->nullable();       // Datei nicht lesbar

            $t->string('title', 512)->nullable();
            $t->string('artist', 512)->nullable();
            $t->string('album', 512)->nullable();
            $t->string('albumartist', 512)->nullable();
            $t->unsignedSmallInteger('track')->nullable();
            $t->unsignedSmallInteger('track_total')->nullable();
            $t->unsignedSmallInteger('disc')->nullable();
            $t->unsignedSmallInteger('disc_total')->nullable();
            $t->string('year', 32)->nullable();
            $t->string('genre', 255)->nullable();
            $t->text('comment')->nullable();
            $t->boolean('compilation')->default(false);

            $t->json('tags')->nullable();               // alle Felder der Datei, roh
            $t->timestamp('scanned_at')->nullable();
            $t->timestamps();

            $t->index(['location', 'album_hash']);
            $t->index(['location', 'created_at']);
        });

        Schema::create('tag_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('track_id')->constrained()->cascadeOnDelete();
            $t->string('field', 64);
            $t->text('old_value')->nullable();
            $t->text('new_value')->nullable();
            $t->string('source', 16);                   // manual (Nutzer) oder system
            $t->timestamp('created_at')->useCurrent();

            $t->index(['track_id', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_changes');
        Schema::dropIfExists('tracks');
    }
};
