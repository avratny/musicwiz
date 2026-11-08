<?php

namespace App\Support;

use App\Models\Album;
use App\Models\Track;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Gibt es ein Inbox-Album (oder seine Lieder) schon im Bestand?
 *  - Album: gleicher Albumname (ohne CD-Angabe, Schreibweise egal) und gleicher Interpret; Sampler nur nach Albumname.
 *  - Lieder: gleicher Interpret und Titel – egal in welchem Album sie im Bestand liegen.
 */
class InboxMatcher
{
    private ?array $librarySongs = null;

    /** @var array<string, list<array{id:int, compilation:bool, artist:string}>>|null Albumschlüssel => Bestandsalben */
    private ?array $libraryAlbums = null;

    private static function titleKey(?string $title): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(ArtistNames::stripAccents((string) $title))) ?? '';
    }

    public static function songKey(?string $artist, ?string $title): string
    {
        $first = ArtistNames::split($artist)[0] ?? (string) $artist;

        return ArtistNames::compareKey($first).'|'.self::titleKey($title);
    }

    /** @return array<string, list<array{id:int, path:string, ext:string, bitrate:?int, album_id:?int, album:?string}>> */
    private function librarySongs(): array
    {
        // Index über den ganzen Bestand: einmal pro Datenstand berechnen, nicht bei jedem Tastendruck im Editor
        return $this->librarySongs ??= Cache::remember('inbox-match:songs:'.DataVersion::tracks(), now()->addHour(), function () {
            $songs = [];
            foreach (Track::where('location', 'library')->select(['id', 'path', 'ext', 'bitrate', 'artist', 'title', 'album_id', 'album'])->cursor() as $t) {
                $songs[self::songKey($t->artist, $t->title)][] = $t->only(['id', 'path', 'ext', 'bitrate', 'album_id', 'album']);
            }

            return $songs;
        });
    }

    private function libraryAlbums(): array
    {
        return $this->libraryAlbums ??= Cache::remember('inbox-match:albums:'.DataVersion::tracks(), now()->addHour(), function () {
            $albums = [];
            foreach (Album::where('location', 'library')->get(['id', 'title', 'is_compilation', 'folder_artist', 'albumartist']) as $l) {
                $albums[Scanner::albumKey($l->title)][] = [
                    'id' => $l->id,
                    'compilation' => (bool) $l->is_compilation,
                    'artist' => ArtistNames::compareKey($l->folder_artist ?: $l->albumartist),
                ];
            }

            return $albums;
        });
    }

    /** Passendes Album im Bestand. */
    public function existingAlbum(Album $album): ?Album
    {
        $key = Scanner::albumKey($album->title);
        if ($key === '' || basename($album->dir) === app(Settings::class)->get('no_album')) {
            return null;
        }
        $artistKey = ArtistNames::compareKey(PathBuilder::folderArtist(['albumartist' => $album->albumartist, 'artist' => $album->folder_artist]));

        $hit = collect($this->libraryAlbums()[$key] ?? [])->first(function (array $l) use ($album, $artistKey) {
            if ($album->is_compilation || $l['compilation']) {
                return $album->is_compilation && $l['compilation'];
            }

            return $l['artist'] === $artistKey;
        });

        return $hit ? Album::find($hit['id']) : null;
    }

    /**
     * @return array{album: ?Album, songs: int, total: int, tracks: array<int, list<array>>}
     *                                                                                       tracks: Inbox-Track-ID => passende Fassungen im Bestand
     */
    public function match(Album $album, ?Collection $tracks = null): array
    {
        $tracks ??= $album->tracks()->get();
        $songs = $this->librarySongs();
        $found = [];
        foreach ($tracks as $t) {
            $hits = $songs[self::songKey($t->artist, $t->title)] ?? [];
            if ($hits) {
                $found[$t->id] = $hits;
            }
        }

        $existing = $this->existingAlbum($album);
        // Wo liegen die gefundenen Lieder? album_id => Anzahl (ohne Album = "Kein Album")
        $where = [];
        foreach ($found as $hits) {
            foreach (collect($hits)->pluck('album_id')->unique() as $albumId) {
                $where[$albumId ?? 0] = ($where[$albumId ?? 0] ?? 0) + 1;
            }
        }
        arsort($where);
        $albums = Album::whereIn('id', array_keys($where))->get()->keyBy('id');
        $info = ['album' => $existing, 'songs' => count($found), 'total' => $tracks->count(), 'tracks' => $found, 'duplicate' => false,
            'where' => collect($where)->map(fn ($n, $id) => ['album' => $albums[$id] ?? null, 'count' => $n])->values()->all()];
        if ($existing) {
            $libTracks = Track::where('album_id', $existing->id)->get(['disc', 'disctotal', 'artist', 'title']);
            $discs = fn ($ts) => collect($ts)->map(fn ($t) => (int) $t->disc ?: 1)->unique()->sort()->values()->all();
            $info['album_tracks'] = $libTracks->count();
            $info['album_discs'] = $discs($libTracks);
            $info['inbox_discs'] = $discs($tracks);
            // echtes Duplikat nur, wenn (fast) alle Lieder schon da sind
            $info['duplicate'] = $tracks->count() > 0 && count($found) >= 0.8 * $tracks->count();
        }

        return $info;
    }

    /** Für die Inbox-Übersicht: album_id => Ergebnis (ohne Track-Details). */
    public function forAlbums(Collection $albums): array
    {
        $tracks = Track::whereIn('album_id', $albums->pluck('id'))->get(['id', 'album_id', 'artist', 'title'])->groupBy('album_id');
        $out = [];
        foreach ($albums as $a) {
            $m = $this->match($a, $tracks[$a->id] ?? collect());
            unset($m['tracks']);
            $out[$a->id] = $m;
        }

        return $out;
    }
}
