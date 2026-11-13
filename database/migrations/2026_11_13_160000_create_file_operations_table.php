<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Protokoll der Datei-Aktionen beim Schreiben (verschieben, kopieren, Papierkorb, Bilder, leere Ordner) */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_operations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('track_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 16);                   // move, copy, trash, extra, rmdir, delete
            $t->text('from_path')->nullable();
            $t->text('to_path')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_operations');
    }
};
