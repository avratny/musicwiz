<?php

namespace App\Jobs;

use App\Models\Artist;
use App\Support\ArtistDirectory;
use App\Support\Lookup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/** Schreibweisen von MusicBrainz für alle Künstler mit MusicBrainz-ID holen (ca. 1 Abfrage/s, eigene Queue). */
class ImportMusicBrainzAliases implements ShouldQueue
{
    use Queueable;

    public const STATUS_KEY = 'mb-aliases:status';

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct()
    {
        $this->onQueue('research');
    }

    public function handle(ArtistDirectory $directory, Lookup $lookup): void
    {
        $artists = Artist::whereNotNull('mbid')->orderBy('name')->get();
        $status = ['running' => true, 'done' => 0, 'total' => $artists->count(), 'added' => 0, 'errors' => 0];
        Cache::forever(self::STATUS_KEY, $status);
        foreach ($artists as $artist) {
            try {
                $status['added'] += count($directory->importMusicBrainzAliases($artist, $lookup->musicbrainzArtistAliases($artist->mbid)));
            } catch (\Throwable $e) {
                report($e);
                $status['errors']++;
            }
            $status['done']++;
            Cache::forever(self::STATUS_KEY, $status);
        }
        Cache::forever(self::STATUS_KEY, ['running' => false, 'finished_at' => now()->toIso8601String()] + $status);
    }

    public function failed(\Throwable $e): void
    {
        Cache::forever(self::STATUS_KEY, ['running' => false, 'error' => $e->getMessage()] + (array) Cache::get(self::STATUS_KEY, []));
    }
}
