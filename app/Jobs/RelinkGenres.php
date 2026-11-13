<?php

namespace App\Jobs;

use App\Support\ArtistGenres;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Nach Änderungen am Genrebaum alle Interpreten neu mit Genres verknüpfen (nur einmal in der Warteschlange). */
class RelinkGenres implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 60;

    public function handle(ArtistGenres $genres): void
    {
        $genres->syncAll();
    }
}
