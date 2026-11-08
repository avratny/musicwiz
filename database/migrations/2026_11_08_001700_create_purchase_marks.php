<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** FLAC-Kandidaten: gemerkt bzw. ausgeblendet – über Interpret + Album gemerkt, damit es Verschieben übersteht. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_marks', function (Blueprint $t) {
            $t->id();
            $t->string('item_key')->unique();
            $t->string('state', 16); // watch | hidden
            $t->string('label');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_marks');
    }
};
