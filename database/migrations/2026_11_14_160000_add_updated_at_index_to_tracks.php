<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Stand der Lieder (MAX(updated_at)) fragt jede Seite ab – ohne Index liest das die ganze Tabelle */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $t) {
            $t->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $t) {
            $t->dropIndex(['updated_at']);
        });
    }
};
