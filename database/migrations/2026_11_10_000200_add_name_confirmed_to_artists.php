<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** „Name passt“: Eintrag ist geprüft – von Hand (manual) oder von der App in eindeutigen Fällen (auto). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artists', function (Blueprint $t) {
            $t->string('name_confirmed')->nullable();        // manual | auto
            $t->string('name_confirmed_reason')->nullable();
            $t->timestamp('name_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('artists', function (Blueprint $t) {
            $t->dropColumn(['name_confirmed', 'name_confirmed_reason', 'name_confirmed_at']);
        });
    }
};
