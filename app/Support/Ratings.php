<?php

namespace App\Support;

use App\Models\Album;
use App\Models\Operation;
use App\Models\Track;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Bewertungen (1–5 Sterne, 0 = keine) – stehen in den Dateien (MP3: POPM, FLAC: RATING) und werden als Aktion über den
 * OperationRunner geschrieben (Verlauf, rückgängig machbar). Ein Album bewerten setzt alle seine Titel.
 */
class Ratings
{
    public static function label(int $stars): string
    {
        return $stars ? str_repeat('★', $stars) : 'keine Bewertung';
    }

    public function track(Track $track, int $stars): ?Operation
    {
        return $this->write(collect([$track]), $stars, 'Bewertung '.self::label($this->clamp($stars)).': '.trim($track->artist.' – '.$track->title, ' –'));
    }

    public function album(Album $album, int $stars): ?Operation
    {
        return $this->write($album->tracks()->get(), $stars, 'Album bewertet '.self::label($this->clamp($stars)).': '.$album->title);
    }

    /** Durchschnitt der bewerteten Titel je Album (album_id => Sterne, z. B. 3.5). */
    public static function albumAverages(?Collection $albumIds = null, ?string $location = null): array
    {
        return Track::whereNotNull('rating')
            ->when($albumIds !== null, fn ($q) => $q->whereIn('album_id', $albumIds))
            ->when($location !== null, fn ($q) => $q->where('location', $location))
            ->selectRaw('album_id, avg(rating) as r')->groupBy('album_id')->pluck('r', 'album_id')
            ->map(fn ($r) => round((float) $r, 1))->all();
    }

    private function write(Collection $tracks, int $stars, string $description): ?Operation
    {
        $stars = $this->clamp($stars);
        $tracks = $tracks->filter(fn (Track $t) => TagTool::canWrite($t->path) && (int) $t->rating !== $stars);
        if ($tracks->isEmpty()) {
            return null;
        }
        // sofort anzeigen; der Neu-Scan nach der Aktion bestätigt (bzw. korrigiert bei einem Fehler)
        Track::whereIn('id', $tracks->pluck('id'))->update(['rating' => $stars ?: null]);

        return OperationRunner::dispatch('rating', $description,
            $tracks->map(fn (Track $t) => ['type' => 'apply', 'path' => $t->path, 'tags' => ['rating' => $stars]])->values()->all());
    }

    private function clamp(int $stars): int
    {
        if ($stars < 0 || $stars > 5) {
            throw new RuntimeException('Bewertung muss zwischen 0 und 5 liegen.');
        }

        return $stars;
    }
}
