<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Songtexte: als Tag-Feld der Lieder (FLAC LYRICS, MP3 USLT – synchronisiert im LRC-Format oder reiner Text) und die
 * Abfragen bei LRCLIB je Lied (genauer Treffer bzw. Kandidaten zur Auswahl).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->mediumText('lyrics')->nullable()->after('comment');
        });
        Schema::table('tag_changes', function (Blueprint $table) {
            $table->mediumText('old_value')->nullable()->change();
            $table->mediumText('new_value')->nullable()->change();
        });
        Schema::create('lyrics_lookups', function (Blueprint $table) {
            $table->foreignId('track_id')->primary()->constrained()->cascadeOnDelete();
            // exact: genauer Treffer · choice: Kandidaten · none: nichts gefunden · instrumental · dismissed: „keiner passt“
            $table->string('status', 16);
            $table->json('exact')->nullable();
            $table->json('candidates')->nullable();
            $table->timestamp('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lyrics_lookups');
        Schema::table('tracks', fn (Blueprint $table) => $table->dropColumn('lyrics'));
    }
};
