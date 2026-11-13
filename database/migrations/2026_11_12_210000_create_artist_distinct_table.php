<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * „Verschieden“: zwei Interpreten, die ähnlich heißen, aber nicht derselbe sind – werden nicht mehr als ähnlich
 * vorgeschlagen. Ein Paar steht einmal drin, kleinere ID zuerst.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artist_distinct', function (Blueprint $t) {
            $t->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $t->foreignId('other_id')->constrained('artists')->cascadeOnDelete();
            $t->timestamp('created_at')->nullable();
            $t->primary(['artist_id', 'other_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_distinct');
    }
};
