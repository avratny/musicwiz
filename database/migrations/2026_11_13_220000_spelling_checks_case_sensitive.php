<?php

use App\Support\ArtistMatcher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Schreibweise-Antworten von Claude gelten nur für genau diese Schreibweisen – Groß/klein zählt („PAUL VAN DYK“ ist
 * nicht „Paul van Dyk“). Gespeicherte Antworten mit einer dritten Schreibweise, die nicht derselbe Name ist, werden
 * als unsicher markiert.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mariadb' || DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE artist_spelling_checks MODIFY ours VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, MODIFY theirs VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
        }
        foreach (DB::table('artist_spelling_checks')->where('source', 'other')->get() as $row) {
            $keys = [ArtistMatcher::key($row->ours), ArtistMatcher::key($row->theirs)];
            if (! in_array(ArtistMatcher::key((string) $row->spelling), $keys, true)) {
                DB::table('artist_spelling_checks')->where('artist_id', $row->artist_id)->update(['source' => 'unsure']);
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mariadb' || DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE artist_spelling_checks MODIFY ours VARCHAR(255) NOT NULL, MODIFY theirs VARCHAR(255) NOT NULL');
        }
        DB::table('artist_spelling_checks')->where('source', 'unsure')->update(['source' => 'other']);
    }
};
