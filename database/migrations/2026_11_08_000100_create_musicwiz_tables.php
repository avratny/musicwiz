<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->json('value')->nullable();
            $t->timestamps();
        });

        Schema::create('albums', function (Blueprint $t) {
            $t->id();
            $t->string('location')->index();          // inbox | library
            $t->string('dir')->unique();
            $t->string('category')->nullable()->index();
            $t->string('title')->nullable();
            $t->string('albumartist')->nullable();
            $t->string('folder_artist')->nullable()->index();
            $t->string('letter', 8)->nullable()->index();
            $t->string('year', 16)->nullable();
            $t->json('genres')->nullable();
            $t->boolean('is_compilation')->default(false);
            $t->unsignedInteger('track_count')->default(0);
            $t->unsignedInteger('duration')->default(0);
            $t->string('cover_file')->nullable();
            $t->boolean('cover_embedded')->default(false);
            $t->unsignedInteger('cover_size')->nullable();   // kleinere Kantenlänge in px
            $t->timestamps();
        });

        Schema::create('tracks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('album_id')->nullable()->constrained()->nullOnDelete();
            $t->string('location')->index();
            $t->string('path')->unique();
            $t->string('dir')->index();
            $t->string('filename');
            $t->string('ext', 16);
            $t->unsignedBigInteger('size')->default(0);
            $t->unsignedBigInteger('mtime')->default(0);
            $t->float('duration')->nullable();
            $t->unsignedInteger('bitrate')->nullable();
            $t->string('format', 16)->nullable();
            $t->string('title')->nullable()->index();
            $t->string('artist')->nullable()->index();
            $t->json('artists')->nullable();
            $t->string('album')->nullable()->index();
            $t->string('albumartist')->nullable();
            $t->json('albumartists')->nullable();
            $t->string('track', 16)->nullable();
            $t->string('tracktotal', 16)->nullable();
            $t->string('disc', 16)->nullable();
            $t->string('disctotal', 16)->nullable();
            $t->string('year', 32)->nullable();
            $t->json('genres')->nullable();
            $t->string('comment')->nullable();
            $t->boolean('compilation')->default(false);
            $t->boolean('cover_embedded')->default(false);
            $t->unsignedInteger('cover_size')->nullable();
            $t->boolean('writable')->default(true);
            $t->text('error')->nullable();
            $t->timestamps();
        });

        Schema::create('leftovers', function (Blueprint $t) {
            $t->id();
            $t->string('path')->unique();
            $t->string('kind');                       // junk | empty_dir | bad_dir
            $t->timestamps();
        });

        Schema::create('operations', function (Blueprint $t) {
            $t->id();
            $t->string('type');
            $t->string('description');
            $t->string('status')->default('pending')->index(); // pending|running|done|failed|undone
            $t->json('payload')->nullable();
            $t->unsignedInteger('total')->default(0);
            $t->unsignedInteger('done')->default(0);
            $t->unsignedInteger('failed')->default(0);
            $t->text('error')->nullable();
            $t->foreignId('undo_of')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });

        Schema::create('history_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('operation_id')->constrained()->cascadeOnDelete();
            $t->string('kind');                       // tags | move | file | trash | delete
            $t->string('old_path')->nullable();
            $t->string('new_path')->nullable();
            $t->json('old_tags')->nullable();
            $t->json('new_tags')->nullable();
            $t->string('backup')->nullable();         // gesichertes altes Cover / Datei
            $t->boolean('cover_changed')->default(false);
            $t->text('error')->nullable();
            $t->timestamps();
        });

        Schema::create('artists', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('compare_key')->index();
            $t->string('mbid')->nullable();
            $t->string('discogs_id')->nullable();
            $t->string('default_genre')->nullable();
            $t->string('image')->nullable();
            $t->timestamps();
        });

        Schema::create('artist_aliases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $t->string('alias')->unique();
            $t->string('compare_key')->index();
            $t->timestamps();
        });

        Schema::create('genres', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->foreignId('parent_id')->nullable()->constrained('genres')->cascadeOnDelete();
            $t->unsignedInteger('position')->default(0);
            $t->timestamps();
        });

        Schema::create('genre_mappings', function (Blueprint $t) {
            $t->id();
            $t->string('source')->unique();
            $t->string('target')->nullable();   // Name aus der Genre-Liste (Unterstil impliziert Hauptgenre)
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['genre_mappings', 'genres', 'artist_aliases', 'artists', 'history_entries', 'operations', 'leftovers', 'tracks', 'albums', 'settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
