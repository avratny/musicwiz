<?php

namespace App\Livewire;

use App\Jobs\ScanLocation;
use App\Models\FilePlan;
use App\Models\Track;
use App\Support\Activity;
use App\Support\Albums;
use App\Support\Categories;
use App\Support\Locations;
use App\Support\TagEditor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Session;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Eine Seite je Bereich (Inbox, Lib, Blackhole, Papierkorb): Tabelle mit Sortieren, Filtern, Bearbeiten und Flyout. */
#[Layout('layouts.app')]
class TrackTable extends Component
{
    /**
     * Spalten in Anzeigereihenfolge: Beschriftung, Spalte in der Datenbank (sortieren), filterbar, direkt bearbeitbar,
     * Breite, standardmäßig sichtbar
     */
    public const COLUMNS = [
        'disc' => ['label' => 'CD', 'db' => 'disc', 'filter' => false, 'edit' => true, 'width' => 'w-12', 'default' => false],
        'track' => ['label' => 'Nr.', 'db' => 'track', 'filter' => false, 'edit' => true, 'width' => 'w-14', 'default' => true],
        'title' => ['label' => 'Titel', 'db' => 'title', 'filter' => true, 'edit' => true, 'width' => null, 'default' => true],
        'artist' => ['label' => 'Interpret', 'db' => 'artist', 'filter' => true, 'edit' => true, 'width' => 'w-[16%]', 'default' => true],
        'artists' => ['label' => 'Interpreten einzeln', 'db' => 'artists', 'filter' => true, 'edit' => true, 'width' => 'w-[14%]', 'default' => false],
        'album' => ['label' => 'Album', 'db' => 'album', 'filter' => true, 'edit' => true, 'width' => 'w-[15%]', 'default' => true],
        'albumartist' => ['label' => 'Album-Interpret', 'db' => 'albumartist', 'filter' => true, 'edit' => true, 'width' => 'w-[12%]', 'default' => true],
        'albumartists' => ['label' => 'Album-Interpreten einzeln', 'db' => 'albumartists', 'filter' => true, 'edit' => true, 'width' => 'w-[12%]', 'default' => false],
        'year' => ['label' => 'Jahr', 'db' => 'year', 'filter' => true, 'edit' => true, 'width' => 'w-16', 'default' => true],
        'genre' => ['label' => 'Genre', 'db' => 'genre', 'filter' => true, 'edit' => true, 'width' => 'w-[10%]', 'default' => true],
        'comment' => ['label' => 'Kommentar', 'db' => 'comment', 'filter' => true, 'edit' => true, 'width' => 'w-[12%]', 'default' => false],
        'compilation' => ['label' => 'Sampler', 'db' => 'compilation', 'filter' => false, 'edit' => false, 'width' => 'w-20', 'default' => false],
        'category' => ['label' => 'Kategorie', 'db' => 'category', 'filter' => true, 'edit' => false, 'width' => 'w-80', 'default' => false],
        'rating' => ['label' => 'Bewertung', 'db' => 'rating', 'filter' => false, 'edit' => false, 'width' => 'w-24', 'default' => true],
        'duration' => ['label' => 'Länge', 'db' => 'duration', 'filter' => false, 'edit' => false, 'width' => 'w-16', 'default' => true],
        'format' => ['label' => 'Format', 'db' => 'format', 'filter' => true, 'edit' => false, 'width' => 'w-24', 'default' => true],
        'filename' => ['label' => 'Datei', 'db' => 'filename', 'filter' => true, 'edit' => false, 'width' => 'w-[18%]', 'default' => false],
        'created_at' => ['label' => 'Hinzugefügt', 'db' => 'created_at', 'filter' => false, 'edit' => false, 'width' => 'w-28', 'default' => false],
    ];

    /** Spalten, die immer sichtbar bleiben (Klick aufs Lied) */
    public const REQUIRED = ['title'];

    /** zusätzliche Sortierungen ohne eigene Spalte */
    public const SORTS = ['album_dir' => 'Ordner'];

    /** Sortierungen, die zu Album-Gruppen passen (Werte je Album) – alle anderen zeigen eine flache Liste */
    public const GROUP_SORTS = ['album_dir', 'album', 'albumartist', 'year', 'genre', 'created_at'];

    /** Lieder je Schritt (gruppiert: ganze Alben, bis die Zahl erreicht ist) – mehr kommt beim Scrollen; klein halten, damit die Seite sofort da ist */
    private const TRACKS_STEP = 150;

    public string $location = 'inbox';

    #[Url]
    public string $q = '';

    #[Url]
    public array $filters = [];

    #[Url]
    public string $sort = 'album_dir';

    #[Url]
    public string $dir = 'asc';

    #[Url]
    public bool $grouped = true;

    /** sichtbare Spalten – gilt für alle vier Seiten, bleibt für die Sitzung */
    #[Session]
    public array $visible = [];

    /** Spalten, die es beim letzten Mal gab – neu hinzugekommene Standardspalten werden eingeblendet */
    #[Session]
    public array $knownColumns = [];

    public bool $showFilters = false;

    public int $limit = self::TRACKS_STEP;

    /** ausgewählte Lieder (Flyout): eins → alle Grunddaten, mehrere → gemeinsame Felder */
    public array $selection = [];

    public array $form = [];

    /** Stand beim Öffnen (mehrere: nur gemeinsame Werte) – übernommen wird, was davon abweicht */
    public array $initial = [];

    /** Felder mit verschiedenen Werten in der Auswahl */
    public array $mixed = [];

    public ?string $saved = null;

    public function mount(string $slug): void
    {
        $this->location = Locations::fromSlug($slug);
        $this->showFilters = (bool) array_filter($this->filters);
        // Sitzung von vor dieser Merkliste: alle Spalten bis auf „Bewertung“ waren schon da
        $known = $this->knownColumns ?: array_diff(array_keys(self::COLUMNS), ['rating']);
        $new = array_diff(self::defaultColumns(), $known);
        $this->visible = $this->visible ? array_values(array_unique([...$this->visible, ...$new])) : self::defaultColumns();
        $this->knownColumns = array_keys(self::COLUMNS);
        $this->grouped = $this->grouped && in_array($this->sort, self::GROUP_SORTS, true);
    }

    public static function defaultColumns(): array
    {
        return array_keys(array_filter(self::COLUMNS, fn ($c) => $c['default']));
    }

    /** sichtbare Spalten in fester Reihenfolge (Pflichtspalten immer dabei) */
    public function visibleColumns(): array
    {
        $visible = array_merge($this->visible, self::REQUIRED);
        $columns = array_filter(self::COLUMNS, fn ($key) => in_array($key, $visible, true), ARRAY_FILTER_USE_KEY);

        // Blackhole: nichts direkt bearbeiten
        return $this->canEdit() ? $columns : array_map(fn ($c) => ['edit' => false] + $c, $columns);
    }

    public function resetColumns(): void
    {
        $this->visible = self::defaultColumns();
    }

    public function updated(string $name): void
    {
        // wieder gruppieren, aber nach einem Lied-Feld sortiert: zurück auf „Ordner“
        if ($name === 'grouped' && $this->grouped && ! in_array($this->sort, self::GROUP_SORTS, true)) {
            $this->sort = 'album_dir';
            $this->dir = 'asc';
        }
        if (in_array(explode('.', $name)[0], ['q', 'filters', 'grouped'], true)) {
            $this->limit = self::TRACKS_STEP;
        }
    }

    public function sortBy(string $column): void
    {
        if (! isset(self::COLUMNS[$column]) && ! isset(self::SORTS[$column])) {
            return;
        }
        $this->dir = $this->sort === $column && $this->dir === 'asc' ? 'desc' : 'asc';
        $this->sort = $column;
        // nach einem Lied-Feld (Titel, Interpret …) sortieren: Album-Gruppen ergeben keinen Sinn mehr → flache Liste
        if ($this->grouped && ! in_array($column, self::GROUP_SORTS, true)) {
            $this->grouped = false;
        }
        $this->limit = self::TRACKS_STEP;
    }

    public function loadMore(): void
    {
        $this->limit += self::TRACKS_STEP;
    }

    public function scan(): void
    {
        if (Activity::running('scan')) {
            return;
        }
        Activity::start('scan', Locations::LABELS[$this->location].' einlesen – wartet');
        ScanLocation::dispatch($this->location);
        $this->dispatch('activity-started');
    }

    #[On('scan-finished')]
    public function refresh(): void {}

    /** Direkt in der Tabelle bearbeitet: speichern, angezeigten Wert zurückgeben (ohne die Tabelle neu zu zeichnen). */
    #[Renderless]
    public function saveField(int $id, string $field, mixed $value, TagEditor $editor): array
    {
        abort_unless(isset(Track::EDITABLE[$field]) && $this->canEdit(), 422);
        $track = Track::where('location', $this->location)->findOrFail($id);
        $editor->update($track, [$field => $value]);
        if (in_array($id, $this->selection, true)) {
            $this->select($this->selection);
        }

        return ['value' => $track->getAttribute($field)];
    }

    /** Felder, die bei mehreren Liedern gemeinsam bearbeitet werden (Titel und Nummer sind je Lied verschieden) */
    public const MULTI_FIELDS = ['artist', 'album', 'albumartist', 'track_total', 'disc', 'disc_total', 'year', 'genre', 'comment', 'compilation', 'category', 'rating'];

    public function open(int $id): void
    {
        $this->select([$id]);
    }

    /** Auswahl aus der Tabelle (Klick, ⌘/Strg+Klick, Shift+Klick, Checkboxen) */
    public function select(array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $tracks = $ids ? Track::where('location', $this->location)->whereIn('id', $ids)->get()->keyBy('id') : collect();
        $this->selection = array_values(array_filter($ids, fn ($id) => isset($tracks[$id])));
        $this->saved = null;
        $this->mixed = [];
        if (! $this->selection) {
            $this->form = $this->initial = [];

            return;
        }
        // der Fingerabdruck ist kein Formularfeld (lang, nur vom System gesetzt)
        $fields = count($this->selection) === 1 ? array_keys(array_diff_key(Track::EDITABLE, ['fingerprint' => true])) : self::MULTI_FIELDS;
        $form = [];
        foreach ($fields as $f) {
            $values = $tracks->map(fn ($t) => $f === 'compilation' ? (bool) $t->$f : $t->$f)->unique(fn ($v) => var_export($v, true));
            if ($values->count() > 1) {
                $this->mixed[$f] = true;
                $form[$f] = $f === 'compilation' ? '' : null;
            } else {
                $form[$f] = $values->first();
            }
        }
        if (count($this->selection) > 1 && is_bool($form['compilation'])) {
            $form['compilation'] = $form['compilation'] ? '1' : '0'; // Auswahlliste Ja/Nein
        }
        $this->form = $this->initial = $form;
    }

    public function close(): void
    {
        $this->select([]);
    }

    public function saveFlyout(TagEditor $editor): void
    {
        abort_unless($this->canEdit(), 422);
        $tracks = Track::where('location', $this->location)->whereIn('id', $this->selection)->get();
        if ($tracks->count() === 1) {
            // nur Geändertes – sonst zöge der alte Wert von „Interpreten einzeln“ nicht mit dem Interpreten mit
            $values = array_filter($this->form, fn ($v, $f) => TagEditor::normalize($f, $v) !== TagEditor::normalize($f, $this->initial[$f] ?? null), ARRAY_FILTER_USE_BOTH);
        } else {
            // nur Felder, die geändert wurden – sonst würden „verschiedene Werte“ überschrieben
            $values = array_filter($this->form, fn ($v, $f) => in_array($f, self::MULTI_FIELDS, true)
                && ! ($f === 'compilation' && $v === '') && TagEditor::normalize($f, $v) !== TagEditor::normalize($f, $this->initial[$f] ?? null)
                && ! (isset($this->mixed[$f]) && trim((string) $v) === ''), ARRAY_FILTER_USE_BOTH);
        }
        $fields = [];
        $changedTracks = 0;
        foreach ($tracks as $track) {
            $changed = $editor->update($track, $values);
            $fields += $changed;
            $changedTracks += $changed ? 1 : 0;
        }
        $this->select($this->selection);
        $this->saved = ! $fields ? 'Keine Änderung' : (count($fields) === 1 ? '1 Feld' : count($fields).' Felder').' geändert'
            .($tracks->count() > 1 ? ' bei '.$changedTracks.' '.($changedTracks === 1 ? 'Lied' : 'Liedern') : '').' – in der Datenbank, noch nicht in der Datei';
    }

    /** Blackhole wird ignoriert: nichts bearbeiten, nur zurück in die Inbox */
    public function canEdit(): bool
    {
        return ! Locations::ignored($this->location);
    }

    /** Kategorie wählen geht in Inbox und Lib */
    public function hasCategories(): bool
    {
        return in_array($this->location, ['inbox', 'library'], true);
    }

    /** Blackhole: Lieder für die Inbox vormerken bzw. die Vormerkung aufheben – verschoben wird erst beim Schreiben */
    public function moveToInbox(array $ids, bool $move = true): void
    {
        abort_unless(Locations::ignored($this->location), 422);
        Track::where('location', $this->location)->whereIn('id', array_map('intval', $ids))->update(['move_to' => $move ? 'inbox' : null]);
    }

    /** Blackhole, Album-Kopf: ganzes Album für die Inbox vormerken bzw. die Vormerkung aufheben */
    public function moveAlbumToInbox(string $hash, bool $move = true): void
    {
        $this->moveToInbox(Track::where('location', $this->location)->where('album_hash', $hash)->pluck('id')->all(), $move);
    }

    /** Album-Kopf: Kategorie für alle Lieder des Albums – verschoben wird erst beim Schreiben */
    public function setAlbumCategory(string $hash, string $category, TagEditor $editor): void
    {
        $this->setCategory(Track::where('location', $this->location)->where('album_hash', $hash)->pluck('id')->all(), $category, $editor);
    }

    /** Lied bewerten (0 = Bewertung entfernen) */
    public function rateTrack(int $id, int $stars, TagEditor $editor): void
    {
        $this->rate([$id], $stars, $editor);
    }

    /** Album bewerten: setzt alle seine Lieder (wie 0.3.x) */
    public function rateAlbum(string $hash, int $stars, TagEditor $editor): void
    {
        $this->rate(Track::where('location', $this->location)->where('album_hash', $hash)->pluck('id')->all(), $stars, $editor);
    }

    private function rate(array $ids, int $stars, TagEditor $editor): void
    {
        abort_unless($this->canEdit() && $stars >= 0 && $stars <= 5, 422);
        foreach (Track::where('location', $this->location)->whereIn('id', array_map('intval', $ids))->get() as $track) {
            $editor->update($track, ['rating' => $stars]);
        }
        if ($this->selection) {
            $this->select($this->selection);
        }
    }

    /** Kategorie je Lied (Einzeltitel, Ordner ohne gemeinsames Album) */
    public function setCategory(array $ids, string $category, TagEditor $editor): void
    {
        abort_unless($this->hasCategories() && Categories::normalize($category) === $category, 422);
        foreach (Track::where('location', $this->location)->whereIn('id', array_map('intval', $ids))->get() as $track) {
            $editor->update($track, ['category' => $category]);
        }
        if ($this->selection) {
            $this->select($this->selection);
        }
    }

    private function base(): Builder
    {
        $query = Track::query()->where('location', $this->location);
        $like = fn (string $v) => '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v).'%';
        foreach (preg_split('/\s+/u', trim($this->q), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $query->where(fn ($w) => $w->where('title', 'like', $like($word))->orWhere('artist', 'like', $like($word))
                ->orWhere('album', 'like', $like($word))->orWhere('albumartist', 'like', $like($word))->orWhere('filename', 'like', $like($word)));
        }
        foreach ($this->filters as $column => $value) {
            if (trim((string) $value) !== '' && (self::COLUMNS[$column]['filter'] ?? false)) {
                $query->where(self::COLUMNS[$column]['db'], 'like', $like(trim($value)));
            }
        }

        return $query;
    }

    private function sortColumn(): string
    {
        return self::COLUMNS[$this->sort]['db'] ?? (isset(self::SORTS[$this->sort]) ? $this->sort : 'album_dir');
    }

    private const LIST_COLUMNS = ['id', 'album_hash', 'album_dir', 'path', 'filename', 'ext', 'format', 'duration', 'bitrate', 'sample_rate', 'bits',
        'has_cover', 'error', 'title', 'artist', 'artists', 'album', 'albumartist', 'albumartists', 'track', 'disc', 'disc_total', 'year', 'genre', 'compilation', 'category', 'rating', 'move_to', 'comment', 'created_at'];

    public function render()
    {
        $dir = $this->dir === 'desc' ? 'desc' : 'asc';
        $column = $this->sortColumn();
        $groups = collect();
        if ($this->grouped) {
            $aggregate = ($dir === 'asc' ? 'MIN' : 'MAX').'('.$column.')';
            $rows = $this->base()->selectRaw('album_hash, MIN(album_dir) AS group_dir, '.$aggregate.' AS sort_value, COUNT(*) AS n')
                ->groupBy('album_hash')->orderByRaw($aggregate.' IS NULL')->orderBy('sort_value', $dir)->orderByRaw('MIN(album_dir)')
                ->limit($this->limit)->get();
            // ganze Alben, bis $limit Lieder erreicht sind (mindestens eins)
            $sum = 0;
            $rows = $rows->takeWhile(function ($r) use (&$sum) {
                $take = $sum === 0 || $sum < $this->limit;
                $sum += (int) $r->n;

                return $take;
            })->values();
            $tracks = $rows->isEmpty() ? collect() : $this->base()->whereIn('album_hash', $rows->pluck('album_hash'))
                ->orderBy('disc')->orderBy('track')->orderBy('filename')->get(self::LIST_COLUMNS)->groupBy('album_hash');
            $groups = $rows->map(fn ($r) => $this->group($tracks[$r->album_hash] ?? collect()))->filter();
            $totalGroups = $this->base()->distinct()->count('album_hash');
            $more = $totalGroups > $rows->count();
            $list = collect();
        } else {
            $list = $this->base()->orderByRaw($column.' IS NULL')->orderBy($column, $dir)->orderBy('id')->limit($this->limit + 1)->get(self::LIST_COLUMNS);
            $more = $list->count() > $this->limit;
            $list = $list->take($this->limit);
            $totalGroups = null;
        }

        return view('livewire.track-table', [
            'groups' => $groups,
            // vorgemerkte Lieder (Album unvollständig: kopieren/verschieben beim Schreiben) – als Zeile im Ziel-Album
            'plans' => $groups->isEmpty() ? collect() : FilePlan::with('track')->whereIn('target_album_hash', $groups->pluck('hash'))->get()->groupBy('target_album_hash'),
            'list' => $list,
            'more' => $more,
            'totalTracks' => $this->base()->count(),
            'totalGroups' => $totalGroups,
            'track' => count($this->selection) === 1 ? Track::with(['changes' => fn ($q) => $q->limit(30)])->find($this->selection[0]) : null,
            'picked' => count($this->selection) > 1 ? Track::whereIn('id', $this->selection)->get(['id', 'title', 'filename', 'artist', 'has_cover', 'move_to']) : collect(),
            'title' => Locations::LABELS[$this->location],
            'columns' => $this->visibleColumns(),
        ])->title(Locations::LABELS[$this->location].' – MusicWiz');
    }

    /** Cover gesetzt (Klick aufs Cover): neu zeichnen */
    #[On('cover-set')]
    public function coverSet(): void {}

    /** Album-Kopf: häufigster Wert je Feld, Gesamtlänge, Cover des ersten Titels. */
    private function group(Collection $tracks): ?array
    {
        if ($tracks->isEmpty()) {
            return null;
        }
        $mode = fn (string $field) => $tracks->pluck($field)->filter(fn ($v) => $v !== null && $v !== '')->countBy()->sortDesc()->keys()->first();
        // kein Album oder verschiedene Alben im Ordner (Einzeltitel): Kategorie und Cover je Lied
        $loose = Albums::loose($tracks);
        // Einzeltitel: Jahr und Genre nur, wenn sie bei allen gleich sind (kein „häufigster Wert“ wie bei einem Album)
        $shared = fn (string $field) => $loose ? ($tracks->pluck($field)->unique()->count() === 1 ? $tracks->first()->{$field} : null) : $mode($field);

        return [
            'hash' => $tracks->first()->album_hash,
            'dir' => $tracks->first()->album_dir,
            'album' => $mode('album'),
            // ohne Album-Interpret: der eine Interpret, bei mehreren „Verschiedene Interpreten“
            'albumartist' => $mode('albumartist') ?? ($tracks->pluck('artist')->filter()->unique()->count() > 1 ? 'Verschiedene Interpreten' : $mode('artist')),
            'year' => $shared('year'),
            'genre' => $shared('genre'),
            // Durchschnitt der bewerteten Lieder (wie 0.3.x)
            'rating' => $tracks->whereNotNull('rating')->isNotEmpty() ? round((float) $tracks->whereNotNull('rating')->avg('rating'), 1) : null,
            'categories' => $tracks->pluck('category')->filter()->unique()->values()->all(),
            'loose' => $loose,
            'toInbox' => $tracks->where('move_to', 'inbox')->count(),
            'cover' => $tracks->firstWhere('has_cover', true)?->id ?? $tracks->first()->id,
            'duration' => (int) $tracks->sum('duration'),
            'discs' => max($tracks->pluck('disc')->filter()->unique()->count(), (int) $tracks->max('disc_total')),
            'tracks' => $tracks,
        ];
    }
}
