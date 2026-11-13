<?php

namespace App\Support;

use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * „Jahr als Datum“ (Inbox und Lib): im Jahr-Tag steht ein Datum oder mehr als die Jahreszahl („2003-05-12“,
 * „2001 04 27“, „2003 (Remaster)“) → nur das Jahr behalten. Ohne Jahreszahl wird nichts angefasst. Übernommen wird als
 * Tag-Änderung (nur in der Datenbank), je Album oder alle auf einmal.
 */
class YearDates
{
    /** Lieder mit mehr als der Jahreszahl im Jahr – optional nur ein Album bzw. passend zur Suche */
    public static function tracks(string $search = '', ?string $albumHash = null): Builder
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($search)).'%';

        return Track::whereIn('location', SplitAlbums::LOCATIONS)
            ->whereRaw("year NOT REGEXP '^[0-9]{4}$'")->whereRaw("year REGEXP '[0-9]{4}'")
            ->when($albumHash !== null, fn ($q) => $q->where('album_hash', $albumHash))
            ->when(trim($search) !== '', fn ($q) => $q->where(fn ($w) => $w->where('album', 'like', $like)->orWhere('albumartist', 'like', $like)
                ->orWhere('artist', 'like', $like)->orWhere('album_dir', 'like', $like)->orWhere('year', 'like', $like)));
    }

    /** nur die Jahreszahl („2003-05-12“ → „2003“) */
    public static function year(string $value): ?string
    {
        return preg_match('/(\d{4})/', $value, $m) ? $m[1] : null;
    }

    public function count(): int
    {
        return self::tracks()->distinct()->count('album_hash');
    }

    /**
     * Betroffene Alben, nach Ordner sortiert.
     *
     * @return array{total: int, albums: Collection<int, array>}
     */
    public function albums(string $search = '', int $limit = 50): array
    {
        $hashes = self::tracks($search)->selectRaw('album_hash, MIN(album_dir) AS dir')->groupBy('album_hash')->orderBy('dir')->limit($limit)->pluck('album_hash');
        $tracks = self::tracks($search)->whereIn('album_hash', $hashes)->get(['id', 'location', 'album_hash', 'album_dir', 'album', 'albumartist', 'artist', 'year', 'has_cover'])->groupBy('album_hash');
        $mode = fn (Collection $t, string $f) => $t->pluck($f)->filter()->countBy()->sortDesc()->keys()->first();

        return [
            'total' => self::tracks($search)->distinct()->count('album_hash'),
            'albums' => $hashes->map(function ($hash) use ($tracks, $mode) {
                $t = $tracks[$hash];
                $first = $t->first();

                return [
                    'hash' => $hash,
                    'location' => $first->location,
                    'dir' => ltrim(mb_substr($first->album_dir, mb_strlen(Locations::root($first->location))), '/'),
                    'album' => $mode($t, 'album'),
                    'albumartist' => $mode($t, 'albumartist') ?? $mode($t, 'artist'),
                    'cover' => $t->firstWhere('has_cover', true)?->id ?? $first->id,
                    'tracks' => $t->count(),
                    'values' => $t->pluck('year')->unique()->mapWithKeys(fn ($y) => [$y => self::year($y)])->all(),
                ];
            }),
        ];
    }

    /** Nur das Jahr übernehmen – ein Album oder alle. @return int Zahl der geänderten Lieder */
    public function apply(?string $albumHash = null): int
    {
        $editor = app(TagEditor::class);
        $n = 0;
        self::tracks('', $albumHash)->chunkById(500, function ($tracks) use ($editor, &$n) {
            foreach ($tracks as $track) {
                $n += $editor->update($track, ['year' => self::year((string) $track->year)]) ? 1 : 0;
            }
        });

        return $n;
    }
}
