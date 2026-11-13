<?php

use App\Support\Duplicates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * „Kein Duplikat“ als Paare von Liedern statt als Fingerabdruck der ganzen Gruppe – kommt eine weitere Fassung dazu,
 * bleibt die Ausnahme bestehen. Bestehende Gruppen-Ausnahmen werden in Paare umgewandelt (die Gruppen sind beim
 * Umwandeln noch dieselben: es gibt ja noch keine Paare).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duplicate_exemption_pairs', function (Blueprint $t) {
            $t->foreignId('track_a')->constrained('tracks')->cascadeOnDelete();
            $t->foreignId('track_b')->constrained('tracks')->cascadeOnDelete();   // track_a < track_b
            $t->timestamp('created_at')->useCurrent();
            $t->primary(['track_a', 'track_b']);
        });
        $keys = DB::table('duplicate_exemptions')->pluck('group_key')->flip();
        if ($keys->isNotEmpty()) {
            foreach (app(Duplicates::class)->groups() as $key => $tracks) {
                if (isset($keys[$key])) {
                    DB::table('duplicate_exemption_pairs')->insertOrIgnore(Duplicates::pairs($tracks->pluck('id')->all()));
                }
            }
        }
        Schema::drop('duplicate_exemptions');
    }

    public function down(): void
    {
        Schema::create('duplicate_exemptions', function (Blueprint $t) {
            $t->id();
            $t->char('group_key', 40)->unique();
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::drop('duplicate_exemption_pairs');
    }
};
