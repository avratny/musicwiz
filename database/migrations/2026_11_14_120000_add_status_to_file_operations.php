<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datei-Aktionen werden vor der Ausführung als „pending“ eingetragen und danach „done“ – bricht der Lauf dazwischen ab
 * (Neustart, Absturz), gleicht FileMover::recover Datei und Datenbank ab. Dazu der Ziel-Bereich und eine Hilfsdatei.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('file_operations', function (Blueprint $t) {
            $t->string('status', 8)->default('done')->after('to_path');   // pending, done
            $t->string('location', 16)->nullable()->after('status');      // Ziel-Bereich
            $t->text('tmp_path')->nullable()->after('location');          // Hilfsdatei bzw. -ordner
            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('file_operations', function (Blueprint $t) {
            $t->dropIndex(['status']);
            $t->dropColumn(['status', 'location', 'tmp_path']);
        });
    }
};
