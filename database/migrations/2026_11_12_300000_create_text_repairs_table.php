<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Encoding-Fehler: Vorschläge je kaputtem Wert (von Claude, bis zu 10) – bleiben gespeichert, bis neu gefragt wird. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('text_repairs', function (Blueprint $t) {
            $t->char('hash', 40)->primary();             // sha1 des kaputten Werts
            $t->text('value');
            $t->json('suggestions')->nullable();         // [{text, reason}]
            $t->string('model', 64)->nullable();
            $t->string('error', 512)->nullable();        // letzte Anfrage fehlgeschlagen
            $t->timestamp('asked_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('text_repairs');
    }
};
