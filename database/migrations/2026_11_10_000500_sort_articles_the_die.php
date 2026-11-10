<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Artikel für Sortiernamen: Standard jetzt nur „The, Die“. Wurde der frühere Standard („The, Die, Der, Das“) schon
 * gespeichert, gilt wieder der Standard.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'sort_articles')->where('value', json_encode('The, Die, Der, Das'))->delete();
    }

    public function down(): void {}
};
