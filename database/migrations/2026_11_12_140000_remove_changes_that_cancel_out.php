<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Einmal aufräumen: Änderungen, die sich aufheben (Feld wieder auf dem Stand vor der ersten Änderung), entfernen. */
return new class extends Migration
{
    public function up(): void
    {
        $groups = DB::table('tag_changes')->selectRaw('track_id, field, MIN(id) AS first_id, MAX(id) AS last_id')->groupBy('track_id', 'field')->get();
        foreach ($groups as $g) {
            $first = DB::table('tag_changes')->where('id', $g->first_id)->value('old_value');
            $last = DB::table('tag_changes')->where('id', $g->last_id)->value('new_value');
            if ($first === $last) {
                DB::table('tag_changes')->where('track_id', $g->track_id)->where('field', $g->field)->delete();
            }
        }
    }

    public function down(): void {}
};
