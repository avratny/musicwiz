<?php

namespace App\Jobs;

use App\Models\Artist;
use App\Support\ArtistImages;
use App\Support\Covers;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Künstlerbild per URL laden und speichern – im Hintergrund, damit „Schnell erfassen“ nicht auf viele Downloads wartet.
 * Ein schon vorhandenes Bild wird nicht ersetzt; Fehler sind egal (Bild lässt sich im Verzeichnis nachtragen).
 */
class StoreArtistImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $artistId, public string $url)
    {
        $this->onQueue('research');
    }

    public function handle(Covers $covers): void
    {
        $artist = Artist::find($this->artistId);
        if ($artist && ! $artist->image) {
            ArtistImages::store($artist, $covers->download($this->url));
        }
    }
}
