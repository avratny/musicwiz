<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Duplikate: eine bessere Fassung kann ein Lied im Album ersetzen (file_plans.replaces_track_id – das ersetzte Lied
 * wandert beim Schreiben in den Papierkorb), Gruppen lassen sich als „kein Duplikat“ ausnehmen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('file_plans', function (Blueprint $t) {
            $t->foreignId('replaces_track_id')->nullable()->after('track_id')->constrained('tracks')->cascadeOnDelete();
        });

        Schema::create('duplicate_exemptions', function (Blueprint $t) {
            $t->id();
            $t->char('group_key', 40)->unique();               // sha1 der sortierten Lied-IDs
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_exemptions');
        Schema::table('file_plans', function (Blueprint $t) {
            $t->dropConstrainedForeignId('replaces_track_id');
        });
    }
};
