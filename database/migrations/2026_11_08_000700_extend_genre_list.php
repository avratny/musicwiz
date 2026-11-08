<?php

use App\Support\GenreTemplate;
use Illuminate\Database\Migrations\Migration;

// Genre-Liste erweitern (Rock 'n' Roll, Hard Rock, Latin, Blasmusik, Euro Disco …) und Start-Zuordnungen anlegen.
// Vorhandene Genres werden nicht umbenannt – ihre Namen stehen schon in den Tags.
return new class extends Migration
{
    public function up(): void
    {
        GenreTemplate::addMissing();
        GenreTemplate::addMappings();
    }

    public function down(): void {}
};
