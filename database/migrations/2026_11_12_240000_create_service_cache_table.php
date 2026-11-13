<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Antworten der Dienste zwischenspeichern (Abgleich, Namensprüfung … teilen sie sich): dieselbe Abfrage wird erst nach
 * der Frist für einen neuen Abgleich wieder gestellt – außer bei „Neu abgleichen“.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_cache', function (Blueprint $t) {
            $t->char('key', 40)->primary();             // sha1(Dienst, Adresse, Parameter)
            $t->string('service', 16);
            $t->text('url');
            $t->unsignedSmallInteger('status');
            $t->mediumText('body');
            $t->timestamp('fetched_at');
            $t->index('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_cache');
    }
};
