<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schreibweisen-Regeln für Interpreten: „various“ = wird zu „Various Artists“ („Verschiedene“, „V.A.“ …),
 * „word“ = Namensteil ersetzen („d.r.“ → „Dr.“), nur als eigenes Wort oder auch innerhalb eines Worts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('name_rules', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 16);
            $t->string('value', 128)->collation('utf8mb4_bin');      // Schreibweise bzw. Suchtext
            $t->string('replacement', 128)->nullable();               // nur „word“
            $t->boolean('whole_word')->default(true);                 // nur „word“: nur als eigenes Wort
            $t->timestamps();
            $t->unique(['kind', 'value']);
        });
        $now = now();
        $rows = [];
        foreach (['Various', 'Various Artist', 'V.A.', 'Verschiedene', 'Verschiedene Interpreten', 'Verschiedene Künstler',
            'Diverse', 'Diverse Interpreten', 'Div. Interpreten', 'Sampler', 'Compilation'] as $v) {
            $rows[] = ['kind' => 'various', 'value' => $v, 'replacement' => null, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (['DJ' => 'DJ', 'Dr' => 'Dr.', 'D.R.' => 'Dr.'] as $search => $replacement) {
            $rows[] = ['kind' => 'word', 'value' => $search, 'replacement' => $replacement, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('name_rules')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('name_rules');
    }
};
