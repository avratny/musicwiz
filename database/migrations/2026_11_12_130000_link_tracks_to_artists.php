<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lieder fest mit dem Künstlerverzeichnis verbinden (Interpret und Album-Interpret, genaue Schreibweise) und bei
 * Tag-Änderungen einen Grund festhalten (z. B. „Künstler umbenannt: Cher → CHER“).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('track_artist', function (Blueprint $t) {
            $t->foreignId('track_id')->constrained()->cascadeOnDelete();
            $t->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $t->string('role', 16);                     // artist (Interpret) oder albumartist
            $t->primary(['track_id', 'artist_id', 'role']);
            $t->index(['artist_id', 'role']);
        });
        Schema::table('tag_changes', function (Blueprint $t) {
            $t->string('reason', 255)->nullable()->after('source');
            $t->index('created_at');
        });
        // verbunden wird in add_artist_credits (dort mit den einzelnen Interpreten)
    }

    public function down(): void
    {
        Schema::dropIfExists('track_artist');
        Schema::table('tag_changes', function (Blueprint $t) {
            $t->dropColumn('reason');
        });
    }
};
