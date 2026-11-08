<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Vorlage für die Genre-Liste aus der Planung; wird nach dem ersten Scan abgeglichen.
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $list = [
            'Electronic' => ['Trance', 'House', 'Techno', 'Eurodance', 'Dance / Pop-Dance', 'Hard Dance / Hardstyle', 'Hardcore',
                'Drum & Bass', 'Breakbeat / Big Beat', 'Electro / Synthpop', 'EBM / Dark Electro', 'Chill / Ambient / Lounge', 'Downtempo / Trip-Hop'],
            'Pop' => [], 'Rock' => [], 'Alternative / Indie' => [], 'Metal' => [], 'Punk' => [], 'NDW / Deutschpop' => [], 'Austropop' => [],
            'Schlager' => [], 'Liedermacher' => [], 'Volksmusik' => [], 'Hip-Hop' => [], 'R&B / Soul' => [], 'Funk / Disco' => [], 'Reggae' => [],
            'Jazz' => [], 'Blues' => [], 'Country / Folk' => [], 'Klassik' => [], 'New Age / Instrumental' => [], 'Soundtrack' => [],
            'Musical' => [], 'Weihnachten' => [], 'Kinder' => [], 'Comedy' => [],
        ];
        $pos = 0;
        foreach ($list as $main => $subs) {
            $id = DB::table('genres')->insertGetId(['name' => $main, 'position' => $pos++, 'created_at' => $now, 'updated_at' => $now]);
            foreach ($subs as $i => $sub) {
                DB::table('genres')->insert(['name' => $sub, 'parent_id' => $id, 'position' => $i, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        DB::table('genres')->delete();
    }
};
