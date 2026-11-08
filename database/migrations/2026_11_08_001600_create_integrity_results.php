<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ergebnis der Audiodaten-Prüfung (komplett dekodiert) je Datei; gilt, solange Größe und Änderungszeit gleich bleiben. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrity_results', function (Blueprint $t) {
            $t->id();
            $t->string('path')->unique();
            $t->unsignedBigInteger('mtime');
            $t->unsignedBigInteger('size');
            $t->boolean('ok');
            $t->text('error')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrity_results');
    }
};
