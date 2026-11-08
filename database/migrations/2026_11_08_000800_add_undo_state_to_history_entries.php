<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Verlauf als Absichts-Protokoll: Eintrag wird vor der Dateiaktion geschrieben (pending) und danach bestätigt.
// undone_at: einzeln rückgängig gemachte Einträge, damit ein abgebrochenes Rückgängig wiederholt werden kann.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('history_entries', function (Blueprint $t) {
            $t->boolean('pending')->default(false);
            $t->timestamp('undone_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('history_entries', function (Blueprint $t) {
            $t->dropColumn(['pending', 'undone_at']);
        });
    }
};
