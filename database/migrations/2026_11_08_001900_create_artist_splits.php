<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Gemerkte Zusammenarbeiten: „Yanou & Liz“ wird überall in „Yanou“ und „Liz“ getrennt (ARTISTS). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artist_splits', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('name_key')->unique(); // klein geschrieben, Unicode-Form vereinheitlicht
            $t->json('parts');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_splits');
    }
};
