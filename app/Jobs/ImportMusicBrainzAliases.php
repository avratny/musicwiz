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

    /** Künstler je Job – danach neu einreihen, damit Aktionen und Aufräumen (Warteschlange default) dazwischen drankommen */
    private const CHUNK = 15;

    /** @param  int  $afterId  Fortsetzung: Künstler mit größerer ID */
    public function __construct(public int $afterId = 0)
    {
        $this->onQueue('research');
    }

    public function handle(ArtistDirectory $directory, Lookup $lookup): void
    {
        $query = Artist::whereNotNull('mbid')->orderBy('id');
        $artists = (clone $query)->where('id', '>', $this->afterId)->limit(self::CHUNK)->get();
        $status = $this->afterId === 0
            ? ['running' => true, 'done' => 0, 'total' => (clone $query)->count(), 'added' => 0, 'errors' => 0]
            : (array) Cache::get(self::STATUS_KEY, []) + ['done' => 0, 'added' => 0, 'errors' => 0];
        $status['running'] = true;
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
        if ($artists->count() === self::CHUNK) {
            self::dispatch($artists->last()->id); // nächstes Häppchen

            return;
        }
        Cache::forever(self::STATUS_KEY, ['running' => false, 'finished_at' => now()->toIso8601String()] + $status);
    }

    public function failed(\Throwable $e): void
    {
        Cache::forever(self::STATUS_KEY, ['running' => false, 'error' => $e->getMessage()] + (array) Cache::get(self::STATUS_KEY, []));
    }
}
