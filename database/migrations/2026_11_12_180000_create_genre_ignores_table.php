<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Genre-Werte, die zu breit sind („Rock/Pop“): werden nicht zugeordnet, sondern aus den Liedern entfernt. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('genre_ignores', function (Blueprint $t) {
            $t->id();
            $t->string('value', 128)->unique();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('genre_ignores');
    }
};
