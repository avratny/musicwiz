<?php

use App\Support\SortNames;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sortiernamen: ARTISTSORT/ALBUMARTISTSORT als Spalten (aus den schon gelesenen rohen Tags übernommen) und ein eigener
 * Sortiername je Interpret.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $t) {
            $t->string('artistsort', 1024)->nullable()->after('albumartists');
            $t->string('albumartistsort', 1024)->nullable()->after('artistsort');
        });
        Schema::table('artists', function (Blueprint $t) {
            $t->string('sort_name', 512)->nullable()->after('name');   // eigener Sortiername (sonst nach der Artikel-Regel)
        });

        DB::table('tracks')->whereNotNull('tags')->select(['id', 'tags'])->chunkById(2000, function ($tracks) {
            foreach ($tracks as $t) {
                $values = array_filter(SortNames::fromRawTags(json_decode($t->tags, true) ?: []));
                if ($values) {
                    DB::table('tracks')->where('id', $t->id)->update($values);
                }
            }
        });
    }

    public function down(): void
    {
        DB::table('tag_changes')->whereIn('field', ['artistsort', 'albumartistsort'])->delete();
        Schema::table('tracks', function (Blueprint $t) {
            $t->dropColumn(['artistsort', 'albumartistsort']);
        });
        Schema::table('artists', function (Blueprint $t) {
            $t->dropColumn('sort_name');
        });
    }
};
