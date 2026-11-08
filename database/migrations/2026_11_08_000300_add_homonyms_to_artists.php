<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Gleichnamige Künstler: "Adam [DE]" = base_name "Adam" + suffix "DE". Navidrome unterscheidet Künstler nur am Namen,
// deshalb steht der Zusatz in ARTISTS/ALBUMARTISTS; der Anzeigetext ARTIST bleibt "Adam".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artists', function (Blueprint $t) {
            $t->string('base_name')->nullable()->index();
            $t->string('suffix')->nullable();
            $t->string('disambiguation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('artists', function (Blueprint $t) {
            $t->dropColumn(['base_name', 'suffix', 'disambiguation']);
        });
    }
};
