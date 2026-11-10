<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bis zu zwei Standard-Genres je Künstler (wie beim Album im Editor). default_genre bleibt als erstes Genre bestehen –
 * alles, was es liest, funktioniert weiter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artists', function (Blueprint $t) {
            $t->json('default_genres')->nullable();
        });
        foreach (DB::table('artists')->whereNotNull('default_genre')->get(['id', 'default_genre']) as $a) {
            DB::table('artists')->where('id', $a->id)->update(['default_genres' => json_encode([$a->default_genre])]);
        }
    }

    public function down(): void
    {
        Schema::table('artists', function (Blueprint $t) {
            $t->dropColumn('default_genres');
        });
    }
};
