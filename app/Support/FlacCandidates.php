<?php

namespace App\Support;

use App\Models\Album;
use App\Models\PurchaseMark;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lib-Alben, die nur aus verlustbehafteten Dateien bestehen – Kandidaten, um sie als FLAC zu kaufen. Gekauftes kommt
 * über die Inbox: „schon in der Lib“ + „bessere Version behalten“ ersetzt die MP3s, danach fällt das Album hier weg.
 */
class FlacCandidates
{
    public const LOSSLESS = ['flac', 'wav', 'aiff', 'alac'];

    public function __construct(private Settings $settings) {}

    /** IDs der Lib-Alben ohne verlustfreie Datei. */
    public static function albumIds(): Collection
    {
        return DB::table('tracks')->where('location', 'library')->whereNotNull('album_id')
            ->selectRaw("album_id, sum(case when ext in ('".implode("','", self::LOSSLESS)."') then 1 else 0 end) as lossless")
            ->groupBy('album_id')->having('lossless', 0)->pluck('album_id');
    }

    /** Merk-Schlüssel: Interpret (bzw. „Sampler“) + Albumname ohne CD-Angabe und Satzzeichen. */
    public static function key(Album $album): string
    {
        $artist = $album->is_compilation ? 'sampler' : mb_strtolower(trim((string) ($album->folder_artist ?: $album->albumartist)));

        return $artist.'|'.Scanner::albumKey($album->title);
    }

    /**
     * Alle Kandidaten mit Bewertung, Bitrate und Merk-Status.
     *
     * @return Collection<int, array{album: Album, key: string, rating: ?float, bitrate: ?int, tracks: int, state: ?string}>
     */
    public function all(): Collection
    {
        return $this->items(self::albumIds());
    }

    /**
     * Unvollständige Lib-Alben (Prüfung „Album unvollständig“), egal in welchem Format – zum Nachkaufen als ganzes Album.
     *
     * @return Collection<int, array> wie all(), zusätzlich 'missing' (z. B. „11 von 14 – es fehlen 3, 7, 12“)
     */
    public function incomplete(): Collection
    {
        $missing = collect(app(CleanupChecks::class)->findings('incomplete'))
            ->mapWithKeys(fn ($f) => [(int) substr($f['id'], 1) => $f['detail']]);

        return $this->items($missing->keys())->map(fn ($c) => ['missing' => $missing[$c['album']->id] ?? null] + $c);
    }

    /** @param  Collection<int, int>  $ids */
    private function items(Collection $ids): Collection
    {
        $albums = Album::whereIn('id', $ids)->get();
        $ratings = Ratings::albumAverages($ids, 'library');
        $bitrates = DB::table('tracks')->whereIn('album_id', $ids)->groupBy('album_id')
            ->selectRaw('album_id, avg(bitrate) as b')->pluck('b', 'album_id');
        $marks = PurchaseMark::pluck('state', 'item_key');

        return $albums->map(function (Album $a) use ($ratings, $bitrates, $marks) {
            $key = self::key($a);

            return ['album' => $a, 'key' => $key, 'rating' => $ratings[$a->id] ?? null,
                'bitrate' => isset($bitrates[$a->id]) ? (int) round($bitrates[$a->id] / 1000) : null,
                'tracks' => (int) $a->track_count, 'state' => $marks[$key] ?? null, 'missing' => null];
        })->values();
    }

    /** Qobuz-Suche „Komplett“ (Interpret + Album) im eingestellten Shop, z. B. https://www.qobuz.com/de-de/search/albums/ABBA%20Gold */
    public function qobuzUrl(Album $album): string
    {
        $title = Scanner::stripDisc($album->title);

        return $this->qobuzSearch($album->is_compilation ? $title : trim($this->artist($album).' '.$title));
    }

    /** Qobuz-Suche nur nach dem Interpreten (alle seine Alben); null bei Samplern. */
    public function qobuzArtistUrl(Album $album): ?string
    {
        $artist = $album->is_compilation ? '' : $this->artist($album);

        return $artist !== '' ? $this->qobuzSearch($artist) : null;
    }

    private function artist(Album $album): string
    {
        return trim((string) ($album->folder_artist ?: $album->albumartist));
    }

    private function qobuzSearch(string $query): string
    {
        $store = trim((string) $this->settings->get('qobuz_store')) ?: 'de-de';

        return 'https://www.qobuz.com/'.rawurlencode($store).'/search/albums/'.rawurlencode($query);
    }
}
