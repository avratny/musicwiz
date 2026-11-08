<?php

namespace App\Livewire;

use App\Livewire\Concerns\RatesMusic;
use App\Livewire\Concerns\StartsScans;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Track;
use App\Support\ArtistDirectory;
use App\Support\Ratings;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app'), Title('Lib')]
class Library extends Component
{
    use RatesMusic, StartsScans;

    /** Bereich: library bzw. blackhole (gleiche Ansicht, siehe Blackhole) */
    protected string $area = 'library';

    /** gewählter Interpret (Ordner-Interpret) */
    #[Url]
    public string $artist = '';

    /** Sampler statt eines Interpreten */
    #[Url]
    public bool $sampler = false;

    /** zuletzt hinzugefügte Alben statt „Alle“ (ohne Auswahl: alle Alben des Bereichs) */
    #[Url]
    public bool $recent = false;

    #[Url]
    public string $q = '';

    /** Ansicht: albums (Raster) oder table (Titel mit Trenner pro Album) – bleibt für die Sitzung gemerkt */
    #[Session]
    public string $view = 'albums';

    /** Filter über die aktuelle Auswahl */
    #[Url]
    public string $filter = '';

    #[Url]
    public string $format = '';

    #[Url]
    public string $genre = '';

    /** Bewertung: none (unbewertet), 1–5 (genau) oder 3+ / 4+ (ab) */
    #[Url]
    public string $rating = '';

    public const RATING_FILTERS = ['none' => 'Unbewertet', '1' => '★ (Schrott)', '2' => '★★', '3' => '★★★', '4' => '★★★★', '5' => '★★★★★', '3+' => 'ab ★★★', '4+' => 'ab ★★★★'];

    /** nur Titel aus YouTube-Downloads (Tag SOURCE) */
    #[Url]
    public bool $youtube = false;

    /** Sortierung der Tabelle: Spalte und Richtung (Album/Jahr ordnen die Alben, alles andere die Titel im Album) */
    #[Url]
    public string $sort = '';

    #[Url]
    public string $dir = 'asc';

    public const SORTABLE = ['nr', 'title', 'artist', 'duration', 'genre', 'year', 'format', 'rating', 'album'];

    /** höchstens so viele Titel in der Tabelle (Sampler können sehr viele sein) */
    public const TABLE_LIMIT = 2000;

    /** Albumraster: so viele Kacheln auf einmal, dann „Mehr laden“ (große Libs: Tausende Kacheln bremsen den Browser) */
    public const PAGE = 120;

    public int $shown = self::PAGE;

    public function loadMore(): void
    {
        $this->shown += self::PAGE;
    }

    /** Neue Auswahl bzw. neuer Filter: wieder mit der ersten Seite beginnen */
    public function updated(string $name): void
    {
        if (in_array($name, ['q', 'filter', 'format', 'genre', 'rating', 'youtube'], true)) {
            $this->shown = self::PAGE;
        }
    }

    public function showArtist(string $name): void
    {
        $this->artist = $name;
        $this->sampler = $this->recent = false;
        $this->resetSelectionFilters();
    }

    public function showSampler(): void
    {
        $this->artist = '';
        $this->sampler = true;
        $this->recent = false;
        $this->resetSelectionFilters();
    }

    public function showAll(): void
    {
        $this->artist = '';
        $this->sampler = $this->recent = false;
        $this->resetSelectionFilters();
    }

    public function showRecent(): void
    {
        $this->showAll();
        $this->recent = true;
    }

    /** Format und Genre hängen von der Auswahl ab – beim Wechsel zurücksetzen (der Text bleibt) */
    private function resetSelectionFilters(): void
    {
        $this->format = '';
        $this->genre = '';
        $this->shown = self::PAGE;
    }

    public function setView(string $view): void
    {
        $this->view = $view === 'table' ? 'table' : 'albums';
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, self::SORTABLE, true)) {
            return;
        }
        if ($this->sort === $column) {
            $this->dir = $this->dir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->dir = 'asc';
        }
    }

    public function clearFilters(): void
    {
        $this->filter = $this->format = $this->genre = $this->rating = '';
        $this->youtube = false;
    }

    /** Bewerten nur in der Lib (im Blackhole wird nichts geändert) */
    protected function canRate(): bool
    {
        return $this->area === 'library';
    }

    /** Zusätzliche Daten für die Ansicht (Blackhole: aufgeklapptes Album). */
    protected function extraData(): array
    {
        return [];
    }

    public function render(Settings $settings, ArtistDirectory $directory)
    {
        $base = Album::where('location', $this->area);
        $nonLetter = (string) $settings->get('non_letter_folder');

        // alle Interpreten nach Buchstaben, Nicht-Buchstaben zuerst
        $artists = (clone $base)->where('is_compilation', false)->whereNotNull('folder_artist')
            ->selectRaw('folder_artist, max(letter) as letter, count(*) as n')->groupBy('folder_artist')
            ->orderByRaw('folder_artist COLLATE NOCASE')->get()
            ->groupBy(fn ($a) => $a->letter ?: $nonLetter)
            ->sortKeysUsing(fn ($a, $b) => [$a !== $nonLetter, $a] <=> [$b !== $nonLetter, $b]);
        $samplerCount = (clone $base)->where('is_compilation', true)->count();

        $artistModel = null;
        $searching = trim($this->q) !== '';
        $tracks = null; // nur geladen, wenn nötig (Tabelle, Filter, Suche)

        if ($searching) {
            $term = '%'.trim($this->q).'%';
            $tracks = $this->trackQuery()
                ->where(fn ($w) => $w->where('artist', 'like', $term)->orWhere('title', 'like', $term)->orWhere('album', 'like', $term)->orWhere('albumartist', 'like', $term))
                ->limit(self::TABLE_LIMIT)->get();
            $albums = Album::whereIn('id', $tracks->pluck('album_id')->unique())->get()
                ->sortBy(fn ($a) => mb_strtolower(($a->is_compilation ? '' : $a->folder_artist).'|'.$a->year.'|'.$a->title))->values();
        } elseif ($this->sampler) {
            $albums = (clone $base)->where('is_compilation', true)->orderByRaw('title COLLATE NOCASE')->get();
        } elseif ($this->artist !== '') {
            $albums = (clone $base)->where('is_compilation', false)->where('folder_artist', $this->artist)->orderBy('year')->orderBy('title')->get();
            $canonical = $directory->check($this->artist)['canonical'] ?? $this->artist;
            $artistModel = Artist::where('name', $canonical)->first();
        } elseif ($this->recent) {
            $albums = (clone $base)->latest('created_at')->latest('id')->limit(30)->get();
        } else {
            // Alle: Interpreten-Alben nach Interpret, Sampler nach Albumname einsortiert
            $albums = (clone $base)->orderByRaw('COALESCE(folder_artist, title) COLLATE NOCASE')->orderBy('year')->orderByRaw('title COLLATE NOCASE')->get();
        }
        $all = ! $searching && ! $this->sampler && ! $this->recent && $this->artist === '';

        $filtering = trim($this->filter) !== '' || $this->format !== '' || $this->genre !== '' || $this->youtube || isset(self::RATING_FILTERS[$this->rating]);
        $formats = $genres = [];
        $groups = collect();
        // Suche liefert höchstens TABLE_LIMIT Titel – dann fehlen womöglich Alben (Hinweis in beiden Ansichten)
        $truncated = $searching && $tracks->count() >= self::TABLE_LIMIT;
        if ($tracks !== null || $filtering || $this->view === 'table') {
            if ($tracks !== null) {
                // Suchergebnis (begrenzt) liegt schon vor: Filter in PHP
                $formats = $tracks->pluck('ext')->map(fn ($e) => strtolower((string) $e))->filter()->unique()->sort()->values()->all();
                $genres = $tracks->pluck('genres')->flatten()->filter()->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
                $tracks = $this->applyFilters($tracks);
            } else {
                // Alle: direkt über den Bereich statt einer riesigen ID-Liste; Filter in der Datenbank statt alle Titel zu laden
                $scope = fn () => $all ? Track::where('location', $this->area)->whereNotNull('album_id') : Track::where('location', $this->area)->whereIn('album_id', $albums->pluck('id'));
                [$formats, $genres] = $this->facets($scope());
                if ($this->view === 'table' && ! $filtering) {
                    // Tabelle ohne Filter: nur die Alben laden, die bis zur Titel-Grenze angezeigt werden
                    $albums = $this->sortAlbums($albums);
                    $ids = [];
                    $sum = 0;
                    foreach ($albums as $a) {
                        $ids[] = $a->id;
                        if (($sum += (int) $a->track_count) > self::TABLE_LIMIT) {
                            break;
                        }
                    }
                    $truncated = count($ids) < $albums->count();
                    $tracks = $this->trackQuery()->whereIn('album_id', $ids)->get();
                } else {
                    $tracks = $this->filterQuery($scope())->select($this->trackColumns())->get();
                }
            }
            if ($filtering) {
                $albums = $albums->whereIn('id', $tracks->pluck('album_id')->unique())->values();
            }
            if ($this->view === 'table') {
                [$groups, $cut] = $this->groupForTable($albums, $tracks);
                $truncated = $truncated || $cut;
            }
        }
        $hiddenAlbums = 0;
        if ($this->view !== 'table' && $albums->count() > $this->shown) {
            $hiddenAlbums = $albums->count() - $this->shown;
            $albums = $albums->take($this->shown)->values();
        }

        return view('livewire.library', [
            'area' => $this->area,
            'artists' => $artists,
            'artistCount' => $artists->flatten(1)->count(),
            'samplerCount' => $samplerCount,
            'searching' => $searching,
            'albums' => $albums,
            'groups' => $groups,
            'truncated' => $truncated,
            'hiddenAlbums' => $hiddenAlbums,
            'filtering' => $filtering,
            'formats' => $formats,
            'genres' => $genres,
            'trackCount' => $tracks?->count(),
            'artistModel' => $artistModel,
            'ratings' => $all ? Ratings::albumAverages(null, $this->area) : Ratings::albumAverages($albums->pluck('id')),
            'canRate' => $this->canRate(),
            'total' => (clone $base)->count(),
            'scan' => $this->scanStatus(),
        ] + $this->extraData());
    }

    private function trackColumns(): array
    {
        return ['id', 'album_id', 'path', 'filename', 'ext', 'format', 'bitrate', 'duration', 'title', 'artist', 'album', 'track', 'disc', 'year', 'genres', 'source', 'rating'];
    }

    private function trackQuery()
    {
        return Track::where('location', $this->area)->select($this->trackColumns());
    }

    /** Formate und Genres der Auswahl (für die Filter-Auswahllisten), ohne alle Titel zu laden. */
    private function facets($scope): array
    {
        $formats = (clone $scope)->whereNotNull('ext')->distinct()->pluck('ext')->map(fn ($e) => strtolower((string) $e))->filter()->unique()->sort()->values()->all();
        // meist nur wenige verschiedene Genre-Kombinationen: die eindeutigen JSON-Listen holen und erst hier zerlegen
        $genres = (clone $scope)->whereNotNull('genres')->where('genres', '!=', '[]')->distinct()->pluck('genres')
            ->flatMap(fn ($v) => is_array($v) ? $v : (array) json_decode((string) $v, true))->filter()->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all();

        return [$formats, $genres];
    }

    /** Dieselben Filter wie applyFilters(), aber als Datenbankabfrage. */
    private function filterQuery($query)
    {
        $like = fn (string $v) => '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v).'%';
        if ($this->format !== '') {
            $query->whereRaw('lower(ext) = ?', [$this->format]);
        }
        if ($this->genre !== '') {
            // Genre als ganzer JSON-Eintrag ("Pop", nicht "K-Pop"); je nach Schreibweg mit bzw. ohne maskierte Zeichen gespeichert
            $variants = array_unique(array_map(fn ($flags) => json_encode($this->genre, $flags), [0, JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES]));
            $query->where(function ($w) use ($variants, $like) {
                foreach ($variants as $v) {
                    $w->orWhereRaw("genres LIKE ? ESCAPE '\\'", [$like($v)]);
                }
            });
        }
        if ($this->youtube) {
            $query->whereRaw('lower(source) = ?', ['youtube']);
        }
        if (isset(self::RATING_FILTERS[$this->rating])) {
            match (true) {
                $this->rating === 'none' => $query->where(fn ($w) => $w->whereNull('rating')->orWhere('rating', 0)),
                str_ends_with($this->rating, '+') => $query->where('rating', '>=', (int) $this->rating),
                default => $query->where('rating', (int) $this->rating),
            };
        }
        $text = trim($this->filter);
        if ($text !== '') {
            $query->where(function ($w) use ($text, $like) {
                foreach (['title', 'artist', 'album', 'genres', 'year'] as $col) {
                    $w->orWhereRaw($col." LIKE ? ESCAPE '\\'", [$like($text)]);
                }
            });
        }

        return $query;
    }

    private function sortAlbums(Collection $albums): Collection
    {
        if (! in_array($this->sort, ['album', 'year'], true)) {
            return $albums;
        }
        $desc = $this->dir === 'desc';
        $key = fn (Album $a) => $this->sort === 'year' ? [(int) $a->year, mb_strtolower((string) $a->title)] : [mb_strtolower((string) $a->title), (int) $a->year];

        return $albums->sort(fn ($a, $b) => $desc ? $key($b) <=> $key($a) : $key($a) <=> $key($b))->values();
    }

    private function applyFilters(Collection $tracks): Collection
    {
        $text = mb_strtolower(trim($this->filter));

        return $tracks->filter(function (Track $t) use ($text) {
            if ($this->format !== '' && strtolower((string) $t->ext) !== $this->format) {
                return false;
            }
            if ($this->genre !== '' && ! in_array($this->genre, $t->genres ?? [], true)) {
                return false;
            }
            if ($this->youtube && ! $t->isFromYouTube()) {
                return false;
            }
            if (isset(self::RATING_FILTERS[$this->rating]) && ! self::matchesRating((int) $t->rating, $this->rating)) {
                return false;
            }
            if ($text !== '') {
                $hay = mb_strtolower(implode(' ', [$t->title, $t->artist, $t->album, implode(' ', $t->genres ?? []), $t->year]));

                return str_contains($hay, $text);
            }

            return true;
        })->values();
    }

    /**
     * Tabelle: Alben in Reihenfolge (nach Album/Jahr sortierbar), darin die Titel nach der gewählten Spalte.
     *
     * @return array{0: Collection<int, array{album: Album, tracks: Collection}>, 1: bool}
     */
    private function groupForTable(Collection $albums, Collection $tracks): array
    {
        $desc = $this->dir === 'desc';
        $albums = $this->sortAlbums($albums);
        $byAlbum = $tracks->groupBy('album_id');
        $trackSort = in_array($this->sort, ['album', 'year', ''], true) ? 'nr' : $this->sort;
        $trackDesc = $desc && $trackSort === $this->sort;

        $groups = collect();
        $left = self::TABLE_LIMIT;
        $truncated = false;
        foreach ($albums as $album) {
            $list = ($byAlbum[$album->id] ?? collect())
                ->sort(fn ($a, $b) => $trackDesc ? self::sortKey($b, $trackSort) <=> self::sortKey($a, $trackSort) : self::sortKey($a, $trackSort) <=> self::sortKey($b, $trackSort))->values();
            if ($list->isEmpty()) {
                continue;
            }
            if ($left <= 0) {
                $truncated = true;
                break;
            }
            if ($list->count() > $left) {
                $list = $list->take($left);
                $truncated = true;
            }
            $left -= $list->count();
            $groups->push(['album' => $album, 'tracks' => $list]);
        }

        return [$groups, $truncated];
    }

    private static function matchesRating(int $stars, string $filter): bool
    {
        return match (true) {
            $filter === 'none' => $stars === 0,
            str_ends_with($filter, '+') => $stars >= (int) $filter,
            default => $stars === (int) $filter,
        };
    }

    private static function sortKey(Track $t, string $column): array
    {
        $nr = [(int) $t->disc, (int) $t->track, $t->filename];

        return match ($column) {
            'title' => [mb_strtolower((string) $t->title), ...$nr],
            'artist' => [mb_strtolower((string) $t->artist), ...$nr],
            'duration' => [(float) $t->duration, ...$nr],
            'genre' => [mb_strtolower((string) (($t->genres ?? [])[0] ?? '')), ...$nr],
            'year' => [(int) $t->year, ...$nr],
            'format' => [strtolower((string) $t->ext), (int) $t->bitrate, ...$nr],
            'rating' => [(int) $t->rating, ...$nr],
            default => $nr,
        };
    }
}
