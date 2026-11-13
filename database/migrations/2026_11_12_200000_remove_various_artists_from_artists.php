<?php

use App\Support\NameRules;
use Illuminate\Database\Migrations\Migration;

/** „Various Artists“ ist kein Interpret: bisherige Einträge (samt Verknüpfungen und Abgleich) entfernen */
return new class extends Migration
{
    public function up(): void
    {
        NameRules::removeVariousArtists();
    }

    public function down(): void {}
};
