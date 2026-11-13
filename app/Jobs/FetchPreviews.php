<?php

namespace App\Jobs;

use App\Models\Artist;
use App\Support\Lookup\ArtistPreviews;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Vorschaubilder eines Interpreten im Hintergrund laden – eigener Worker („images“), damit der Abgleich nicht wartet. */
class FetchPreviews implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(public int $artistId)
    {
        $this->onQueue('images');
    }

    /** je Interpret nur ein Auftrag in der Warteschlange */
    public function uniqueId(): string
    {
        return (string) $this->artistId;
    }

    public function handle(ArtistPreviews $previews): void
    {
        if ($artist = Artist::find($this->artistId)) {
            $previews->forArtist($artist);
        }
    }
}
