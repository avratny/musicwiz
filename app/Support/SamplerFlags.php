<?php

namespace App\Support;

use App\Models\Artist;
use App\Models\Track;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sampler-Kennzeichnung (Inbox und Lib, Alben ab 2 Liedern, keine Einzeltitel-Ordner wie „Kein Album“): Sampler-Flag und Album-Interpret „Various Artists“ passen
 * zu den Interpreten der Lieder.
 * - Sampler ohne Kennzeichnung: kein Interpret steht auf mehr als der Hälfte der Lieder, aber Flag oder „Various
 *   Artists“ fehlt → beides setzen
 * - fälschlich als Sampler gekennzeichnet: ein Interpret steht auf allen Liedern (Gäste zählen nicht), aber Flag oder
 *   „Various Artists“ ist gesetzt → Flag entfernen, „Various Artists“ durch diesen Interpreten ersetzen
 * Dazwischen (ein Interpret auf den meisten, aber nicht allen Liedern, z. B. „ABBA & Friends“) bleibt offen.
 * Grundlage sind die Verbindungen zum Künstlerverzeichnis (Rolle „artist“). Kein Sampler ist außerdem ein Album, dessen
 * eigener Album-Interpret (nicht „Various Artists“) im Interpreten von mehr als der Hälfte der Lieder steht – auch in
 * noch nicht entschiedenen Kombis („Stevie Wonder & Dionne Warwick“ bei „Stevie Wonder“; bei einer Kombi als
 * Album-Interpret zählt der erste Name). Übernommen wird als Tag-Änderung.
 */
class SamplerFlags
{
    public const KINDS = ['missing' => 'Sampler ohne Kennzeichnung', 'wrong' => 'Kein Sampler, aber gekennzeichnet'];

    /** @return Collection<string, array> Album-Hash => Befund */
    public function findings(string $search = ''): Collection
    {
        $albums = DB::table('tracks')->whereIn('location', SplitAlbums::LOCATIONS)
            ->selectRaw('album_hash, COUNT(*) AS n, SUM(compilation) AS flagged, SUM(albumartist = ?) AS va', [NameRules::VARIOUS])
            ->groupBy('album_hash')->having('n', '>=', 2)->get()->keyBy('album_hash');
        $loose = Albums::looseHashes($albums->keys());
        $albums = $albums->reject(fn ($a, $hash) => $loose[$hash] ?? false);
        // je Album und Interpret: auf wie vielen Liedern
        $artists = DB::table('tracks')->join('track_artist', fn ($j) => $j->on('track_artist.track_id', '=', 'tracks.id')->where('track_artist.role', 'artist'))
            ->whereIn('tracks.location', SplitAlbums::LOCATIONS)->whereIn('tracks.album_hash', $albums->keys())
            ->selectRaw('tracks.album_hash, track_artist.artist_id, COUNT(DISTINCT tracks.id) AS n')
            ->groupBy('tracks.album_hash', 'track_artist.artist_id')->get()->groupBy('album_hash');

        $found = collect();
        $own = null;
        foreach ($albums as $hash => $a) {
            $rows = $artists[$hash] ?? collect();
            $common = $rows->firstWhere('n', (int) $a->n);
            $marked = (int) $a->flagged > 0 || (int) $a->va > 0;
            $complete = (int) $a->flagged === (int) $a->n && (int) $a->va === (int) $a->n;
            $sampler = $rows->count() >= 2 && (int) $rows->max('n') * 2 <= (int) $a->n;
            if ($sampler && ! $complete) {
                $own ??= $this->ownAlbumArtist($albums->filter(fn ($x) => (int) $x->va === 0)->keys()->all());
                if (isset($own[$hash])) {
                    continue;
                }
                $found[$hash] = ['kind' => 'missing', 'artist' => null, 'artists' => $rows->count()];
            } elseif ($common && $marked) {
                $found[$hash] = ['kind' => 'wrong', 'artist' => $common->artist_id, 'artists' => $rows->count()];
            }
        }
        if (trim($search) !== '' && $found->isNotEmpty()) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($search)).'%';
            $hits = Track::whereIn('album_hash', $found->keys())->where(fn ($w) => $w->where('album', 'like', $like)
                ->orWhere('albumartist', 'like', $like)->orWhere('artist', 'like', $like)->orWhere('album_dir', 'like', $like))
                ->distinct()->pluck('album_hash')->flip();
            $found = $found->filter(fn ($f, $hash) => isset($hits[$hash]));
        }

        return $found;
    }

    /**
     * Alben mit eigenem Album-Interpreten, der im Interpreten von mehr als der Hälfte der Lieder steht.
     *
     * @return array<string, true>
     */
    private function ownAlbumArtist(array $hashes): array
    {
        $out = [];
        foreach (array_chunk($hashes, 500) as $chunk) {
            $albums = Track::whereIn('location', SplitAlbums::LOCATIONS)->whereIn('album_hash', $chunk)->get(['album_hash', 'artist', 'albumartist'])->groupBy('album_hash');
            foreach ($albums as $hash => $tracks) {
                $albumartist = (string) $tracks->pluck('albumartist')->filter()->countBy()->sortDesc()->keys()->first();
                $main = ArtistCredits::suggest(ArtistCredits::splitGuest($albumartist)[0])[0] ?? '';
                if ($main === '' || $albumartist === NameRules::VARIOUS) {
                    continue;
                }
                $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($main, '/').'(?![\p{L}\p{N}])/iu';
                if ($tracks->filter(fn ($t) => preg_match($pattern, (string) $t->artist))->count() * 2 > $tracks->count()) {
                    $out[$hash] = true;
                }
            }
        }

        return $out;
    }

    public function count(): int
    {
        return $this->findings()->count();
    }

    /**
     * Betroffene Alben mit Anzeige-Daten, nach Ordner sortiert.
     *
     * @return array{total: int, albums: list<array>}
     */
    public function albums(string $search = '', int $limit = 50): array
    {
        $found = $this->findings($search);
        $tracks = Track::whereIn('location', SplitAlbums::LOCATIONS)->whereIn('album_hash', $found->keys())
            ->get(['id', 'location', 'album_hash', 'album_dir', 'album', 'albumartist', 'compilation', 'has_cover'])->groupBy('album_hash')
            ->sortBy(fn ($t) => mb_strtolower($t->first()->album_dir));
        $names = Artist::whereIn('id', $found->pluck('artist')->filter())->pluck('name', 'id');
        $mode = fn (Collection $t, string $f) => $t->pluck($f)->filter()->countBy()->sortDesc()->keys()->first();

        return [
            'total' => $found->count(),
            'albums' => $tracks->take($limit)->map(function (Collection $t, string $hash) use ($found, $names, $mode) {
                $first = $t->first();

                return [
                    'hash' => $hash,
                    'kind' => $found[$hash]['kind'],
                    'location' => $first->location,
                    'dir' => ltrim(mb_substr($first->album_dir, mb_strlen(Locations::root($first->location))), '/'),
                    'album' => $mode($t, 'album'),
                    'albumartist' => $mode($t, 'albumartist'),
                    'artist' => $found[$hash]['artist'] ? $names[$found[$hash]['artist']] ?? null : null,
                    'artists' => $found[$hash]['artists'],
                    'cover' => $t->firstWhere('has_cover', true)?->id ?? $first->id,
                    'tracks' => $t->count(),
                    'flagged' => $t->where('compilation', true)->count(),
                    'va' => $t->where('albumartist', NameRules::VARIOUS)->count(),
                ];
            })->values()->all(),
        ];
    }

    /** Kennzeichnung richten – ein Album oder alle (null). @return int Zahl der geänderten Lieder */
    public function apply(?string $albumHash = null): int
    {
        $found = $this->findings();
        if ($albumHash !== null) {
            $found = $found->only([$albumHash]);
        }
        $names = Artist::whereIn('id', $found->pluck('artist')->filter())->pluck('name', 'id');
        $editor = app(TagEditor::class);
        $n = 0;
        foreach ($found as $hash => $f) {
            foreach (Track::whereIn('location', SplitAlbums::LOCATIONS)->where('album_hash', $hash)->get() as $track) {
                $values = $f['kind'] === 'missing'
                    ? ['compilation' => true, 'albumartist' => NameRules::VARIOUS]
                    : ['compilation' => false] + ($track->albumartist === NameRules::VARIOUS && isset($names[$f['artist']]) ? ['albumartist' => $names[$f['artist']]] : []);
                $n += $editor->update($track, $values) ? 1 : 0;
            }
        }

        return $n;
    }
}
