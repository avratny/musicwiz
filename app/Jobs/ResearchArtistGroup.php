<?php

namespace App\Jobs;

use App\Support\ArtistGroupAnalyzer;
use App\Support\Lookup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** MusicBrainz-Recherche für eine Vorschlagsgruppe (eigene Queue, blockiert keine Dateiaktionen). */
class ResearchArtistGroup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public array $group)
    {
        $this->onQueue('research');
    }

    public function handle(ArtistGroupAnalyzer $analyzer, Lookup $lookup): void
    {
        $analyzer->runResearch($this->group, $lookup);
    }
}
