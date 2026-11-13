<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datei-ID („Gerät:Inode“) je Lied: bleibt beim Umbenennen bzw. Verschieben innerhalb eines Shares gleich – so erkennt
 * das Einlesen einen außerhalb umbenannten Ordner wieder (gleiche ID, Verlauf bleibt). Wird beim nächsten Einlesen
 * nachgetragen, ohne die Dateien neu zu lesen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $t) {
            $t->string('file_id', 48)->nullable()->after('mtime');
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $t) {
            $t->dropColumn('file_id');
        });
    }
};
