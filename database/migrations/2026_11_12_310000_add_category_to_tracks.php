<?php

use App\Support\Categories;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kategorie (LIB, LIB COMPILATION, BLACKHOLE, BLACKHOLE COMPILATION) als eigene Spalte – aus den schon gelesenen rohen
 * Tags übernommen (MUSICWIZ_CATEGORY aus 0.3.x), fehlende werden vorbelegt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $t) {
            $t->string('category', 32)->nullable()->after('compilation');
        });

        DB::table('tracks')->whereNotNull('tags')->select(['id', 'tags'])->chunkById(2000, function ($tracks) {
            foreach ($tracks as $t) {
                if ($category = Categories::fromRawTags(json_decode($t->tags, true) ?: [])) {
                    DB::table('tracks')->where('id', $t->id)->update(['category' => $category]);
                }
            }
        });
        app(Categories::class)->fill();
    }

    public function down(): void
    {
        DB::table('tag_changes')->where('field', 'category')->delete();
        Schema::table('tracks', function (Blueprint $t) {
            $t->dropColumn('category');
        });
    }
};
