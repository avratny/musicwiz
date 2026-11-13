<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Serien: gewähltes bzw. eigenes Namensmuster je Serie („Viva Hits, Vol. {Nr}“) – gilt auch für später dazukommende Alben */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('series_patterns', function (Blueprint $t) {
            $t->id();
            $t->char('series_key', 40)->unique();      // AlbumSeries: sha1 des Gruppen-Schlüssels
            $t->string('pattern', 255);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('series_patterns');
    }
};
