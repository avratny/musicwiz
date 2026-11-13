<?php

namespace App\Livewire;

use App\Jobs\LookupArtist;
use App\Jobs\LookupArtists;
use App\Livewire\Concerns\ShowsMessages;
use App\Models\Artist;
use App\Models\ArtistImage;
use App\Models\Genre;
use App\Support\ArtistCredits;
use App\Support\ArtistRegistry;
use App\Support\ArtistTools;
use App\Support\GenreTree;
use App\Support\Lookup\LookupGroups;
use App\Support\MusicBrainzData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Künstlerverzeichnis: Liste mit Bild, Status und Schreibweisen; rechts im Flyout Einzelheiten, Pflege und Lieder.
 * Links eine zweite Leiste mit Werkzeugen – „Ähnlich“: ähnliche Schreibweisen gruppiert zum schnellen Zusammenführen.
 */
#[Layout('layouts.app')]
class ArtistList extends Component
{
    use ShowsMessages, WithFileUploads;

    private const STEP = 100;

    #[Url(as: 'ansicht', except: '')]
    public string $tool = '';

    #[Url]
    public string $q = '';

    /** all, confirmed, open */
    #[Url]
    public string $status = 'all';

    /** Spalten, nach denen sortiert werden kann – Beschriftung */
    public const SORTS = ['name' => 'Name', 'confirmed' => 'Status', 'aliases' => 'Schreibweisen', 'tracks' => 'Lieder', 'albums' => 'Alben', 'created_at' => 'Hinzugefügt'];

    #[Url]
    public string $sort = 'name';

    #[Url]
    public string $dir = 'asc';

    public int $limit = self::STEP;

    public ?int $selected = null;

    public string $name = '';

    public string $newAlias = '';

    public string $mergeQuery = '';

    public $photo = null;

    /** Genre manuell hinzufügen: Suche */
    public string $genreQuery = '';

    /** „Neu abgleichen“ angestoßen: bis das Ergebnis da ist, wird das Flyout regelmäßig neu gezeichnet */
    public ?int $awaiting = null;

    public function updated(string $property): void
    {
        if (in_array($property, ['q', 'status'], true)) {
            $this->limit = self::STEP;
        }
        if ($property === 'tool') {
            $this->close();
            $this->error = $this->message = null;
        }
    }

    /** „Verschieden“ aufheben (Liste „Verschieden“ oder Flyout „Nicht derselbe wie“) */
    public function undistinct(int $a, int $b, ArtistRegistry $registry): void
    {
        $registry->undistinct($a, $b);
        $names = Artist::whereIn('id', [$a, $b])->pluck('name', 'id');
        $this->error = null;
        $this->message = '„'.($names[$a] ?? '?').'“ und „'.($names[$b] ?? '?').'“ können wieder als ähnlich vorgeschlagen werden.';
    }

    /** Badge „unbestätigt“ bei „Alle“: die Liste auf die offenen stellen */
    public function showOpen(): void
    {
        $this->tool = '';
        $this->status = 'open';
        $this->limit = self::STEP;
        $this->close();
    }

    public function sortBy(string $column): void
    {
        if (! isset(self::SORTS[$column])) {
            return;
        }
        // Zahlen zuerst absteigend (die meisten oben), Text aufsteigend
        $this->dir = $this->sort === $column ? ($this->dir === 'asc' ? 'desc' : 'asc') : (in_array($column, ['tracks', 'albums', 'aliases', 'confirmed', 'created_at'], true) ? 'desc' : 'asc');
        $this->sort = $column;
        $this->limit = self::STEP;
    }

    public function loadMore(): void
    {
        $this->limit += self::STEP;
    }

    public function open(int $id): void
    {
        $artist = Artist::findOrFail($id);
        $this->selected = $artist->id;
        $this->name = $artist->name;
        $this->newAlias = $this->mergeQuery = '';
        $this->error = $this->message = null;
    }

    public function close(): void
    {
        $this->selected = null;
        $this->awaiting = null;
    }

    #[On('lookup-finished')]
    #[On('cover-set')]
    public function refresh(): void {}

    public function toggleConfirmed(): void
    {
        $artist = $this->artist();
        $artist->update(['confirmed' => ! $artist->confirmed]);
        $this->error = null;
        $this->message = $artist->confirmed ? 'Schreibweise bestätigt' : 'Bestätigung zurückgenommen';
    }

    public function rename(ArtistRegistry $registry): void
    {
        $this->attempt(fn () => $registry->rename($this->artist(), $this->name), 'Umbenannt – der bisherige Name bleibt als Schreibweise.');
        $this->name = $this->artist()->name;
    }

    public function addAlias(ArtistRegistry $registry): void
    {
        $this->attempt(fn () => $registry->addAlias($this->artist(), $this->newAlias), 'Schreibweise hinzugefügt.');
        if (! $this->error) {
            $this->newAlias = '';
        }
    }

    public function removeAlias(string $alias, ArtistRegistry $registry): void
    {
        $registry->removeAlias($this->artist(), $alias);
        $this->error = null;
        $this->message = 'Schreibweise entfernt.';
    }

    /** „Ist derselbe wie …“: den anderen Künstler hierher übernehmen */
    public function merge(int $otherId, ArtistRegistry $registry): void
    {
        $other = Artist::findOrFail($otherId);
        $registry->merge($this->artist(), $other, true);   // ausdrücklich gewählt (bei verschiedenen MusicBrainz-Einträgen mit Rückfrage)
        $this->mergeQuery = '';
        $this->error = null;
        $this->message = '„'.$other->name.'“ übernommen – Name und Schreibweisen gehören jetzt hierher.';
    }

    public function updatedPhoto(ArtistRegistry $registry): void
    {
        $this->validate(['photo' => 'image|max:20480'], ['photo.image' => 'Bitte ein Bild wählen.', 'photo.max' => 'Das Bild ist zu groß (höchstens 20 MB).']);
        $this->attempt(fn () => $registry->setImage($this->artist(), $this->photo->getRealPath()), 'Bild gespeichert.');
        $this->photo = null;
    }

    public function removeImage(ArtistRegistry $registry): void
    {
        $registry->removeImage($this->artist());
        $this->error = null;
        $this->message = 'Bild entfernt (die Datei bleibt im Bilderordner).';
    }

    /** Abgleich aller Interpreten (Lib und Inbox) im Hintergrund starten */
    public function startLookup(): void
    {
        LookupArtists::start();
        $this->dispatch('activity-started');
    }

    /** Diesen Interpreten sofort neu abgleichen */
    public function relookup(): void
    {
        $this->awaiting = time();   // vor dem Start merken – sonst kann ein schneller Abgleich „davor“ fertig sein
        $this->say('Abgleich angestoßen – die Ergebnisse erscheinen gleich hier.');
        LookupArtist::dispatch($this->selected);
    }

    /** Treffergruppe wählen: IDs (MusicBrainz, Discogs, Spotify) am Interpreten hinterlegen */
    public function chooseGroup(string $key, LookupGroups $groups): void
    {
        $group = $groups->choose($this->artist(), $key);
        $this->error = null;
        $this->message = $group ? 'Treffer gewählt – IDs hinterlegt, Schreibweise bestätigt.' : 'Treffer nicht mehr vorhanden.';
    }

    /** Schreibweise eines Dienstes als Namen übernehmen (alter Name wird Schreibweise, Lieder angepasst) */
    public function adoptName(string $name, ArtistRegistry $registry): void
    {
        $this->attempt(fn () => $registry->rename($this->artist(), $name), 'Schreibweise „'.$name.'“ übernommen – Lieder angepasst.');
        $this->name = $this->artist()->name;
    }

    /** Schreibweise laut Dienst als alternative Schreibweise übernehmen */
    public function adoptAlias(string $alias, ArtistRegistry $registry): void
    {
        $this->attempt(fn () => $registry->addAlias($this->artist(), $alias), '„'.$alias.'“ als Schreibweise übernommen.');
    }

    /** Bild aus dem Abgleich übernehmen (Original laden, als Interpretenbild speichern) */
    public function useImage(int $imageId, ArtistRegistry $registry): void
    {
        $image = ArtistImage::with('source')->findOrFail($imageId);
        abort_unless($image->source?->artist_id === $this->selected, 404);
        $this->attempt(fn () => $registry->adoptImage($this->artist(), $image), 'Bild übernommen.');
    }

    /** Genre manuell zuordnen (bleibt beim Neu-Verknüpfen erhalten) */
    public function addGenre(int $genreId): void
    {
        DB::table('artist_genre')->updateOrInsert(['artist_id' => $this->selected, 'genre_id' => $genreId, 'source' => 'manual'], ['weight' => 1]);
        $this->genreQuery = '';
        $this->error = null;
        $this->message = 'Genre „'.app(GenreTree::class)->path($genreId).'“ zugeordnet.';
    }

    public function removeGenre(int $genreId): void
    {
        DB::table('artist_genre')->where(['artist_id' => $this->selected, 'genre_id' => $genreId, 'source' => 'manual'])->delete();
        $this->error = null;
        $this->message = 'Manuelle Zuordnung entfernt.';
    }

    /** Genres des Interpreten, nach Hauptgenre gruppiert: Hauptgenre => [genre_id => [path, name, level, sources]] */
    private function artistGenres(Artist $artist): array
    {
        $tree = app(GenreTree::class);
        $out = [];
        foreach (DB::table('artist_genre')->join('genres', 'genres.id', '=', 'artist_genre.genre_id')->where('artist_id', $artist->id)
            ->get(['artist_genre.genre_id', 'artist_genre.source', 'artist_genre.weight', 'genres.name', 'genres.level']) as $r) {
            $root = $tree->path((int) $tree->root((int) $r->genre_id));
            $out[$root][$r->genre_id] ??= ['name' => $r->name, 'level' => (int) $r->level, 'path' => $tree->path((int) $r->genre_id), 'sources' => [], 'weight' => 0];
            $out[$root][$r->genre_id]['sources'][$r->source] = (int) $r->weight;
            $out[$root][$r->genre_id]['weight'] += (int) $r->weight;
        }
        // Hauptgenre mit dem meisten Gewicht zuerst; darin nach Ebene und Name
        uasort($out, fn ($a, $b) => array_sum(array_column($b, 'weight')) <=> array_sum(array_column($a, 'weight')));
        foreach ($out as &$list) {
            uasort($list, fn ($a, $b) => [$a['level'], $a['path']] <=> [$b['level'], $b['path']]);
        }

        return $out;
    }

    private function artist(): Artist
    {
        return Artist::with('aliases')->findOrFail($this->selected);
    }

    /** „Interpret anlegen“ (Modal): danach gleich im Flyout öffnen */
    #[On('artist-created')]
    public function artistCreated(int $id, mixed $context = null): void
    {
        if ($context !== 'artist-list' || ! Artist::find($id)) {
            return;
        }
        $this->tool = '';
        $this->open($id);
        $this->message = 'Interpret angelegt.';
    }

    #[On('artist-tools-changed')]
    public function toolsChanged(): void {}

    public function render()
    {
        if ($this->tool !== '') {
            $titles = ['eindeutig' => 'Eindeutige Interpreten', 'wahrscheinlich' => 'Wahrscheinliche Interpreten', 'schreibweise' => 'Schreibweisen', 'aehnlich' => 'Ähnliche Interpreten', 'kombis' => 'Kombis', 'verschieden' => 'Verschiedene Interpreten', 'sortiernamen' => 'Sortiernamen'];

            return view('livewire.artist-list', ['counts' => ArtistTools::counts(), 'unconfirmed' => ArtistTools::unconfirmed(), 'artist' => null])->title(($titles[$this->tool] ?? 'Interpreten').' – MusicWiz');
        }

        $like = '%'.mb_strtolower(str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($this->q))).'%';
        $artists = Artist::with('aliases')
            ->when(trim($this->q) !== '', fn ($w) => $w->where(fn ($x) => $x->whereRaw('LOWER(name) LIKE ?', [$like])
                ->orWhereHas('aliases', fn ($a) => $a->whereRaw('LOWER(alias) LIKE ?', [$like]))))
            ->when($this->status === 'confirmed', fn ($w) => $w->where('confirmed', true))
            ->when($this->status === 'open', fn ($w) => $w->where('confirmed', false))
            ->get();
        $stats = ArtistTools::stats();
        $artists->each(function (Artist $a) use ($stats) {
            $a->setAttribute('track_count', (int) ($stats[$a->id]->tracks ?? 0));
            $a->setAttribute('album_count', (int) ($stats[$a->id]->albums ?? 0));
        });
        $key = match ($this->sort) {
            'confirmed' => fn ($a) => (int) $a->confirmed,
            'aliases' => fn ($a) => $a->aliases->count(),
            'tracks' => fn ($a) => $a->track_count,
            'albums' => fn ($a) => $a->album_count,
            'created_at' => fn ($a) => $a->created_at?->timestamp ?? 0,
            default => fn ($a) => mb_strtolower($a->name),
        };
        $sign = $this->dir === 'desc' ? -1 : 1;
        // bei Gleichstand nach Name
        $byName = fn ($a, $b) => strcasecmp($a->name, $b->name) ?: strcmp($a->name, $b->name);
        // Name: ganz nach Richtung; sonst bei Gleichstand nach Name aufsteigend
        $artists = $artists->sort(fn ($a, $b) => $this->sort === 'name' ? $sign * $byName($a, $b) : ($sign * ($key($a) <=> $key($b)) ?: $byName($a, $b)))->values();

        $artist = $this->selected ? Artist::with('aliases')->find($this->selected) : null;
        if ($this->awaiting && $artist?->looked_up_at && $artist->looked_up_at->timestamp >= $this->awaiting) {
            $this->awaiting = null;
            $this->message = 'Abgleich aktualisiert.';
        }

        return view('livewire.artist-list', [
            'counts' => ArtistTools::counts(),
            'unconfirmed' => ArtistTools::unconfirmed(),
            'distinctFrom' => $artist ? $this->distinctFrom($artist) : collect(),
            'band' => $artist ? $this->band($artist) : null,
            'artists' => $artists->take($this->limit)->values(),
            'total' => $artists->count(),
            'more' => $artists->count() > $this->limit,
            'artist' => $artist,
            'detail' => $artist ? $this->detail($artist) : null,
            'candidates' => $artist && mb_strlen(trim($this->mergeQuery)) >= 2 ? $this->candidates($artist) : collect(),
            'lookupGroups' => $artist ? app(LookupGroups::class)->for($artist) : [],
            'genres' => $artist ? $this->artistGenres($artist) : [],
            'genreResults' => $artist && mb_strlen(trim($this->genreQuery)) >= 2 ? Genre::whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower(trim($this->genreQuery)).'%'])
                ->orderBy('level')->limit(10)->get()->map(fn ($g) => ['id' => $g->id, 'path' => app(GenreTree::class)->path($g->id)]) : collect(),
            'lookupStatus' => $artist ? DB::table('artist_lookups')->where('artist_id', $artist->id)->get()->keyBy('service') : collect(),
            'pendingLookup' => LookupArtists::pending()->count(),
        ])->title('Interpreten – MusicWiz');
    }

    /**
     * Flyout „Mitglieder“ / „Mitglied von“ aus den lokalen MusicBrainz-Daten – über die gewählte MusicBrainz-ID, sonst nur,
     * wenn der Name eindeutig ist (bei Gleichnamigen wüssten wir nicht, welcher). Mit Verweis, wenn es ihn bei uns gibt.
     *
     * @return array{members: list<array>, memberOf: list<array>}|null
     */
    private function band(Artist $artist): ?array
    {
        $data = app(MusicBrainzData::class);
        if (! $data->available()) {
            return null;
        }
        $mbid = $artist->mbid ?: ($data->namesakes($artist->name) === 1 ? ($data->search($artist->name, 1)[0]['mbid'] ?? null) : null);
        if (! $mbid) {
            return null;
        }
        [$members, $memberOf] = [$data->members($mbid), $data->memberOf($mbid)];
        $known = app(ArtistCredits::class)->known(array_column([...$members, ...$memberOf], 'name'));
        $link = fn ($list) => array_map(fn ($m) => $m + ['id' => $known[$m['name']]['id'] ?? null], $list);

        return $members || $memberOf ? ['members' => $link($members), 'memberOf' => $link($memberOf)] : null;
    }

    /** Flyout „Nicht derselbe wie“: als verschieden markierte Interpreten */
    private function distinctFrom(Artist $artist): Collection
    {
        $ids = DB::table('artist_distinct')->where('artist_id', $artist->id)->pluck('other_id')
            ->merge(DB::table('artist_distinct')->where('other_id', $artist->id)->pluck('artist_id'));

        return Artist::whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);
    }

    private function detail(Artist $artist): array
    {
        $tracks = $artist->tracks();

        return [
            'tracks' => (clone $tracks)->count(),
            'guest' => $artist->tracks('guest')->whereNotIn('id', DB::table('track_artist')->where('artist_id', $artist->id)->where('role', '!=', 'guest')->select('track_id'))->count(),
            'albums' => (clone $tracks)->distinct()->count('album_hash'),
            'songs' => (clone $tracks)->orderByRaw("FIELD(location, 'library', 'inbox', 'blackhole', 'trash')")->orderBy('album')->orderBy('disc')->orderBy('track')
                ->limit(12)->get(['id', 'title', 'filename', 'artist', 'album', 'duration', 'location', 'has_cover']),
        ];
    }

    private function candidates(Artist $artist): Collection
    {
        $like = '%'.mb_strtolower(trim($this->mergeQuery)).'%';

        return Artist::where('id', '!=', $artist->id)->whereRaw('LOWER(name) LIKE ?', [$like])->orderByRaw('LENGTH(name)')->limit(8)->get();
    }
}
