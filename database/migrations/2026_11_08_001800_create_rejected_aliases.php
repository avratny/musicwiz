<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Gelöschte Schreibweisen je Künstler: werden beim Holen von MusicBrainz nicht wieder eingetragen. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rejected_aliases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $t->string('alias');
            $t->string('alias_key'); // klein geschrieben, Unicode-Form vereinheitlicht
            $t->timestamps();
            $t->unique(['artist_id', 'alias_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rejected_aliases');
    }
};
