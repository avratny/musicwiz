<?php

use App\Support\Scanner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** CD-Ordner („Hits, Disc 1“ / „Hits, Disc 2“) bilden ein Album: Album-Ordner und Gruppierung neu berechnen. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tracks')->select(['id', 'location', 'dir'])->orderBy('id')->chunkById(1000, function ($rows) {
            foreach ($rows as $row) {
                $albumDir = Scanner::albumDir($row->dir);
                DB::table('tracks')->where('id', $row->id)->update(['album_dir' => $albumDir, 'album_hash' => Scanner::albumHash($row->location, $albumDir)]);
            }
        });
    }

    public function down(): void {}
};
