<?php

namespace App\Jobs;

use App\Models\Artist;
use App\Support\Lookup\ArtistLookup;
use App\Support\Lookup\Client;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Einen Interpreten sofort (neu) abgleichen – läuft vor dem nächsten Paket des großen Abgleichs. */
class LookupArtist implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(public int $artistId)
    {
        $this->onQueue('lookup-now');
    }

    public function handle(ArtistLookup $lookup, Client $client): void
    {
        // ausdrücklich „Neu abgleichen“: nicht aus dem Zwischenspeicher, sondern die Dienste wirklich fragen
        if ($artist = Artist::find($this->artistId)) {
            $client->fresh(fn () => $lookup->run($artist));
        }
    }
}
