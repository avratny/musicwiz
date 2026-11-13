<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akustischer Fingerabdruck (Chromaprint, komprimiert wie im Tag ACOUSTID_FINGERPRINT) je Lied – Grundlage, ob zwei
 * Dateien wirklich dieselbe Aufnahme sind (Duplikate über Albumgrenzen). Vergleiche werden zwischengespeichert,
 * solange beide Fingerabdrücke gleich bleiben.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $t) {
            $t->text('fingerprint')->nullable()->after('loudness_peak');
            $t->timestamp('fingerprint_failed_at')->nullable()->after('fingerprint');   // fpcalc konnte nicht (nicht erneut versuchen, bis die Datei sich ändert)
        });
        Schema::create('fingerprint_scores', function (Blueprint $t) {
            $t->foreignId('track_a')->constrained('tracks')->cascadeOnDelete();
            $t->foreignId('track_b')->constrained('tracks')->cascadeOnDelete();   // track_a < track_b
            $t->char('fingerprints', 40);                                          // sha1 beider Fingerabdrücke
            $t->float('score')->nullable();
            $t->primary(['track_a', 'track_b']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fingerprint_scores');
        Schema::table('tracks', function (Blueprint $t) {
            $t->dropColumn(['fingerprint', 'fingerprint_failed_at']);
        });
    }
};
