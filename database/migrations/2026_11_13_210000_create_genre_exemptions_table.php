<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sonstiges › Genre: Alben, Sampler und Einzeltitel, deren Genre so passt („Passt so“). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('genre_exemptions', function (Blueprint $t) {
            $t->id();
            $t->string('unit', 48);                             // Album-Hash oder „t“ + Lied-ID (Einzeltitel)
            $t->string('check', 16);                            // mismatch, mixed
            $t->timestamp('created_at')->useCurrent();

            $t->unique(['unit', 'check']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('genre_exemptions');
    }
};
