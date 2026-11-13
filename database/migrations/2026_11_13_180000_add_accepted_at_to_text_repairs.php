<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Schreibweise prüfen (Klammer offen, Punkt am Ende, Bindestrich): „Passt so“ – der Wert wird nicht mehr angezeigt. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('text_repairs', function (Blueprint $t) {
            $t->timestamp('accepted_at')->nullable()->after('asked_at');
        });
    }

    public function down(): void
    {
        Schema::table('text_repairs', function (Blueprint $t) {
            $t->dropColumn('accepted_at');
        });
    }
};
