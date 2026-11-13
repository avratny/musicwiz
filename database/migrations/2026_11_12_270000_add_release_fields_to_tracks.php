<?php

use App\Support\SplitAlbums;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MusicBrainz-Album-ID und Veröffentlichungsdatum als eigene Spalten – Navidrome trennt Alben danach, also müssen sie
 * sich angleichen lassen. Aus den schon gelesenen rohen Tags übernommen (beim Einlesen kommen sie gleich mit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $t) {
            $t->string('releasedate', 32)->nullable()->after('year');
            $t->string('mb_albumid', 64)->nullable()->after('releasedate');
        });

        DB::table('tracks')->whereNotNull('tags')->select(['id', 'tags'])->chunkById(2000, function ($tracks) {
            foreach ($tracks as $t) {
                $values = SplitAlbums::fromRawTags(json_decode($t->tags, true) ?: []);
                if (array_filter($values)) {
                    DB::table('tracks')->where('id', $t->id)->update($values);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $t) {
            $t->dropColumn(['releasedate', 'mb_albumid']);
        });
    }
};
