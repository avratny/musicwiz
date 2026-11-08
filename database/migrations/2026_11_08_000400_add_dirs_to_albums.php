<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mehrfach-CDs in der Inbox: ein Album kann aus mehreren Ordnern bestehen ("…, Disc 1", "…, Disc 2").
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('albums', fn (Blueprint $t) => $t->json('dirs')->nullable());
    }

    public function down(): void
    {
        Schema::table('albums', fn (Blueprint $t) => $t->dropColumn('dirs'));
    }
};
