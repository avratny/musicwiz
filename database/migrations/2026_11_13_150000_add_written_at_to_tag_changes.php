<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Geschriebene Tag-Änderungen bleiben als Verlauf erhalten: written_at = in die Datei geschrieben (null = offen). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tag_changes', function (Blueprint $t) {
            $t->timestamp('written_at')->nullable()->after('reason');
            $t->index(['written_at', 'track_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tag_changes', function (Blueprint $t) {
            $t->dropIndex(['written_at', 'track_id']);
            $t->dropColumn('written_at');
        });
    }
};
