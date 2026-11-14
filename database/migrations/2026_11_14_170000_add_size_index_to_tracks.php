<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Einlesen: in einen anderen Bereich verschobene Dateien über Größe und Namen wiederfinden */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $t) {
            $t->index('size');
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $t) {
            $t->dropIndex(['size']);
        });
    }
};
