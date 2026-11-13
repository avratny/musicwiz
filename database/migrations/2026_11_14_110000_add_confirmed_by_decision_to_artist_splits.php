<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Kombi-Entscheidung „bleibt ganz“: hat erst sie den Interpreten bestätigt? Dann nimmt „Aufheben“ das zurück. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artist_splits', function (Blueprint $t) {
            $t->boolean('confirmed_artist')->default(false)->after('parts');
        });
    }

    public function down(): void
    {
        Schema::table('artist_splits', function (Blueprint $t) {
            $t->dropColumn('confirmed_artist');
        });
    }
};
