<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vorgemerkte Änderungen (digitaler Zwilling): die Track-Zeile trägt schon die gewünschten Tags, die Datei noch die alten.
 * Geschrieben wird erst per „Änderungen schreiben“; der Zielpfad wird dann aus den fertigen Tags berechnet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('track_id')->unique()->constrained()->cascadeOnDelete();
            $t->json('tags_before'); // Dateistand der geänderten Felder
            $t->json('tags');        // gewünschte Werte dieser Felder
            $t->boolean('relocate')->default(false); // an den Regelort, auch ohne Tag-Änderung
            $t->string('target')->nullable();        // ausdrückliches Ziel (z. B. Endung klein), falls der Regelort keins ergibt
            $t->string('cover')->nullable();         // neues Cover (JPEG unter storage/app/pending-covers) – einbetten + cover.jpg
            $t->boolean('remove_cover')->default(false);
            $t->boolean('embed_cover')->default(false); // fehlendes Cover aus dem Album einbetten
            $t->json('labels')->nullable();          // aus welchen Prüfungen
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_changes');
    }
};
