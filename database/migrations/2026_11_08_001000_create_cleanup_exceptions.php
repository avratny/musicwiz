<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Aufräum-Funde, die bewusst so bleiben (z. B. „Electronic reicht hier“ – kein Unterstil nötig).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleanup_exceptions', function (Blueprint $t) {
            $t->id();
            $t->string('check');               // Prüfung, z. B. electronic_sub
            $t->string('item_key');            // bei Alben: Album-Interpret|Album (normalisiert, übersteht Verschieben)
            $t->string('label')->nullable();
            $t->timestamps();
            $t->unique(['check', 'item_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleanup_exceptions');
    }
};
