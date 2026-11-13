<?php

namespace App\Support;

use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Kaputte Lieder (Inbox und Lib): die Datei ließ sich lesen, enthält aber kein Audio – Länge 0 bzw. unter einer Sekunde
 * (z. B. abgebrochene Downloads, nur Kopf und Cover). Abhilfe: in den Papierkorb vormerken (move_to = trash, ausgeführt
 * beim Schreiben) – gibt es im selben Album ein intaktes Lied mit demselben Titel, wird es angezeigt.
 * Nicht lesbare Dateien (error) gehören nicht dazu, die stehen unter „Nicht lesbar“.
 */
class BrokenTracks
{
    /** kürzer gilt als kein Audio (Sekunden) */
    public const MIN_DURATION = 1.0;

    public static function query(): Builder
    {
        return Track::whereIn('location', SplitAlbums::LOCATIONS)->whereNull('error')
            ->where(fn ($q) => $q->whereNull('duration')->orWhere('duration', '<', self::MIN_DURATION));
    }

    public static function is(Track $track): bool
    {
        return $track->error === null && ($track->duration === null || $track->duration < self::MIN_DURATION);
    }

    /** offen: noch nicht für den Papierkorb vorgemerkt */
    public function count(): int
    {
        return self::query()->where(fn ($q) => $q->whereNull('move_to')->orWhere('move_to', '!=', 'trash'))->count();
    }

    /** @return Collection<int, array{track: Track, intact: ?Track, trash: bool}> */
    public function findings(string $search = ''): Collection
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($search)).'%';
        $tracks = self::query()
            ->when(trim($search) !== '', fn ($q) => $q->where(fn ($q) => $q->where('path', 'like', $like)->orWhere('title', 'like', $like)
                ->orWhere('artist', 'like', $like)->orWhere('album', 'like', $like)))
            ->orderBy('path')->get();
        // intakte Fassung im selben Album: gleicher Titel (ohne Groß/klein und Zeichen), mit Audio
        $siblings = Track::whereIn('album_hash', $tracks->pluck('album_hash')->unique())->whereNull('error')
            ->where('duration', '>=', self::MIN_DURATION)->get(['id', 'album_hash', 'title', 'track', 'path', 'filename', 'duration'])
            ->groupBy(fn ($t) => $t->album_hash.'|'.GenreTree::key((string) $t->title));

        return $tracks->map(fn (Track $t) => ['track' => $t, 'trash' => $t->move_to === 'trash',
            'intact' => $t->title ? $siblings->get($t->album_hash.'|'.GenreTree::key($t->title))?->first() : null]);
    }

    /**
     * In den Papierkorb vormerken – ein Lied bzw. alle (null). Ausgeführt beim Schreiben.
     *
     * @return int vorgemerkte Lieder
     */
    public function trash(?int $trackId = null): int
    {
        return self::query()->when($trackId, fn ($q) => $q->whereKey($trackId))
            ->where(fn ($q) => $q->whereNull('move_to')->orWhere('move_to', '!=', 'trash'))->update(['move_to' => 'trash']);
    }

    /** Vormerkung zurücknehmen */
    public function keep(int $trackId): void
    {
        self::query()->whereKey($trackId)->where('move_to', 'trash')->update(['move_to' => null]);
    }
}
