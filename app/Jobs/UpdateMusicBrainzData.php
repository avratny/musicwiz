<?php

namespace App\Jobs;

use App\Support\Activity;
use App\Support\MusicBrainzData;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Lokale MusicBrainz-Daten im Hintergrund aktualisieren (Einstellungen › MusicBrainz) – dauert eine Weile. */
class UpdateMusicBrainzData implements ShouldQueue
{
    use Queueable;

    public int $timeout = 0;

    public function __construct(public bool $force = false) {}

    public static function start(bool $force = false): bool
    {
        if (Activity::running(MusicBrainzData::ACTIVITY)) {
            return false;
        }
        Activity::start(MusicBrainzData::ACTIVITY, 'MusicBrainz-Daten laden', 0, 'startet …');
        self::dispatch($force);

        return true;
    }

    public function handle(MusicBrainzData $data): void
    {
        Activity::finish(MusicBrainzData::ACTIVITY, $data->update($this->force));
    }

    public function failed(?Throwable $e): void
    {
        Activity::finish(MusicBrainzData::ACTIVITY, 'MusicBrainz-Daten: '.mb_substr((string) $e?->getMessage(), 0, 200));
    }
}
