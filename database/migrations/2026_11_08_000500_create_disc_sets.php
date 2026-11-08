<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Von Hand zusammengeführte Mehrfach-CDs (Ordner ohne erkennbare CD-Angabe) und die CD-Nummer je Ordner.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disc_sets', function (Blueprint $t) {
            $t->id();
            $t->json('members');   // [{dir, disc}]
            $t->timestamps();
        });
        Schema::table('albums', fn (Blueprint $t) => $t->json('disc_map')->nullable());
    }

    public function down(): void
    {
        Schema::dropIfExists('disc_sets');
        Schema::table('albums', fn (Blueprint $t) => $t->dropColumn('disc_map'));
    }
};
