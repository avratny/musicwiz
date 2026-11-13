<?php

use App\Support\ArtistCredits;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Interpreten einzeln (Tags ARTISTS/ALBUMARTISTS, mehrere Werte mit „; “ getrennt) – aus den schon gelesenen rohen
 * Tags übernommen (verbunden und zerlegt wird beim Einlesen). Dazu gemerkte Entscheidungen zu Kombis wie „Beam & Yanou“.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $t) {
            $t->string('artists', 1024)->nullable()->after('artist');
            $t->string('albumartists', 1024)->nullable()->after('albumartist');
        });
        Schema::create('artist_splits', function (Blueprint $t) {
            $t->id();
            $t->string('name', 255)->collation('utf8mb4_bin')->unique();
            $t->string('kind', 16);                     // split (Teile), whole (bleibt ganz), members (ganz + Mitglieder)
            $t->json('parts')->nullable();              // split: die Teile, members: die Mitglieder, whole: null
            $t->timestamps();
        });

        DB::table('tracks')->whereNotNull('tags')->select(['id', 'tags'])->chunkById(2000, function ($tracks) {
            foreach ($tracks as $t) {
                $values = ArtistCredits::fromRawTags(json_decode($t->tags, true) ?: []);
                if (array_filter($values)) {
                    DB::table('tracks')->where('id', $t->id)->update($values);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_splits');
        Schema::table('tracks', function (Blueprint $t) {
            $t->dropColumn(['artists', 'albumartists']);
        });
    }
};
