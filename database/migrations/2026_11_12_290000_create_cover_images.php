<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Neu gesetzte Cover: das Bild im Original im Cover-Speicher (Name = Inhalt), hier Maße, Fingerabdruck und Herkunft.
 * Am Lied nur der Verweis (tracks.cover) – eine Tag-Änderung wie jede andere; in die Datei kommt es erst beim Schreiben.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cover_images', function (Blueprint $t) {
            $t->char('hash', 40)->primary();             // sha1 des Bilds
            $t->string('ext', 8);                        // jpg, png
            $t->unsignedInteger('size');
            $t->unsignedSmallInteger('width')->nullable();
            $t->unsignedSmallInteger('height')->nullable();
            $t->char('phash', 16)->nullable();
            $t->string('source', 1024)->nullable();      // URL bzw. „eingebettet aus …“
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::table('tracks', function (Blueprint $t) {
            $t->char('cover', 40)->nullable()->after('cover_phash');
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $t) {
            $t->dropColumn('cover');
        });
        Schema::dropIfExists('cover_images');
    }
};
