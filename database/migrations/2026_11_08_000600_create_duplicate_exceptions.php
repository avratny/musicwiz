<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Kein Duplikat": Fassungen, die bewusst nebeneinander bleiben (z. B. Single- und Album-Version).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duplicate_exceptions', function (Blueprint $t) {
            $t->id();
            $t->string('song_key')->index();   // Interpret|Titel (normalisiert)
            $t->json('members');               // Fingerabdrücke: Format|Bitrate|Länge
            $t->string('label')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_exceptions');
    }
};
