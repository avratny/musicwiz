<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Blackhole wird ignoriert: keine Verbindungen zum Künstlerverzeichnis, keine vorgemerkten Tag-Änderungen. Von dort geht
 * es nur zurück in die Inbox – vorgemerkt in move_to, verschoben wird erst beim Schreiben.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $t) {
            $t->string('move_to', 16)->nullable()->after('category');   // inbox: aus Blackhole zurück in die Inbox
        });

        $blackhole = DB::table('tracks')->where('location', 'blackhole')->select('id');
        DB::table('track_artist')->whereIn('track_id', $blackhole)->delete();
        // vorgemerkte Änderungen verwerfen: die Felder wieder auf den Stand der Datei (alter Wert der ersten Änderung)
        $first = DB::table('tag_changes')->whereIn('track_id', $blackhole)->selectRaw('MIN(id) AS id')->groupBy('track_id', 'field');
        foreach (DB::table('tag_changes')->whereIn('id', $first)->get(['track_id', 'field', 'old_value']) as $c) {
            $value = match (true) {
                $c->field === 'compilation' => $c->old_value === '1',
                in_array($c->field, ['track', 'track_total', 'disc', 'disc_total'], true) => $c->old_value === null ? null : (int) $c->old_value,
                default => $c->old_value,
            };
            DB::table('tracks')->where('id', $c->track_id)->update([$c->field => $value]);
        }
        DB::table('tag_changes')->whereIn('track_id', $blackhole)->delete();
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $t) {
            $t->dropColumn('move_to');
        });
    }
};
