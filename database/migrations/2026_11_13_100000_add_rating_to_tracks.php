<?php

use App\Support\Ratings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Bewertung (1–5 Sterne) als eigene Spalte – aus den schon gelesenen rohen Tags übernommen. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $t) {
            $t->unsignedTinyInteger('rating')->nullable()->after('compilation');
        });

        DB::table('tracks')->whereNotNull('tags')->select(['id', 'tags'])->chunkById(2000, function ($tracks) {
            foreach ($tracks as $t) {
                if ($rating = Ratings::fromRawTags(json_decode($t->tags, true) ?: [])) {
                    DB::table('tracks')->where('id', $t->id)->update(['rating' => $rating]);
                }
            }
        });
    }

    public function down(): void
    {
        DB::table('tag_changes')->where('field', 'rating')->delete();
        Schema::table('tracks', function (Blueprint $t) {
            $t->dropColumn('rating');
        });
    }
};
