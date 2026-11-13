<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cover vergleichen und prüfen: je Lied Maße und Fingerabdruck (dHash) des eingebetteten Covers, dazu die Bilder im
 * Ordner (cover/folder/front), die Navidrome als Album-Cover nimmt. read_version: Stand des Tag-Lesers – ältere
 * Lieder werden beim nächsten Einlesen einmal komplett neu gelesen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $t) {
            $t->unsignedSmallInteger('cover_width')->nullable()->after('has_cover');
            $t->unsignedSmallInteger('cover_height')->nullable()->after('cover_width');
            $t->char('cover_phash', 16)->nullable()->after('cover_height');
            $t->unsignedTinyInteger('read_version')->default(0)->after('scanned_at');
        });
        Schema::create('folder_images', function (Blueprint $t) {
            $t->id();
            $t->string('location', 16);
            $t->text('path');
            $t->char('path_hash', 40)->unique();
            $t->text('dir');
            $t->char('dir_hash', 40)->index();
            $t->unsignedBigInteger('size');
            $t->unsignedInteger('mtime');
            $t->unsignedSmallInteger('width')->nullable();
            $t->unsignedSmallInteger('height')->nullable();
            $t->char('phash', 16)->nullable();
            $t->timestamps();

            $t->index('location');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folder_images');
        Schema::table('tracks', function (Blueprint $t) {
            $t->dropColumn(['cover_width', 'cover_height', 'cover_phash', 'read_version']);
        });
    }
};
