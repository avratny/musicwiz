<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Künstler mit genauer Schreibweise (Groß/klein zählt: „ABBA“ ≠ „Abba“), „bestätigt“ = Schreibweise stimmt, Bild;
 * dazu alternative Schreibweisen, unter denen der Künstler in Tags vorkommt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artists', function (Blueprint $t) {
            $t->id();
            $t->string('name', 255)->collation('utf8mb4_bin')->unique();
            $t->boolean('confirmed')->default(false);
            $t->string('image', 255)->nullable();      // Dateiname im Bilderordner
            $t->timestamps();
        });

        Schema::create('artist_aliases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $t->string('alias', 255)->collation('utf8mb4_bin')->unique();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_aliases');
        Schema::dropIfExists('artists');
    }
};
