<?php

use App\Support\ReplayGain;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ReplayGain als Tag-Felder (aus den schon gelesenen rohen Tags übernommen) und die eigene Messung (LUFS, Peak linear) –
 * die Messung ist kein Tag, sie dient nur dem Album-Wert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $t) {
            foreach (ReplayGain::FIELDS as $field) {
                $t->string($field, 32)->nullable()->after('rating');
            }
            $t->float('loudness')->nullable()->after('rg_album_peak');
            $t->float('loudness_peak')->nullable()->after('loudness');
        });

        DB::table('tracks')->whereNotNull('tags')->select(['id', 'tags'])->chunkById(2000, function ($tracks) {
            foreach ($tracks as $t) {
                $values = array_filter(ReplayGain::fromRawTags(json_decode($t->tags, true) ?: []));
                if ($values) {
                    DB::table('tracks')->where('id', $t->id)->update($values);
                }
            }
        });
    }

    public function down(): void
    {
        DB::table('tag_changes')->whereIn('field', ReplayGain::FIELDS)->delete();
        Schema::table('tracks', function (Blueprint $t) {
            $t->dropColumn([...ReplayGain::FIELDS, 'loudness', 'loudness_peak']);
        });
    }
};
