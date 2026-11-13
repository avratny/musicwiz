<?php

namespace App\Livewire;

use App\Livewire\Concerns\ShowsMessages;
use App\Models\TagChange;
use App\Models\Track;
use App\Support\Locations;
use App\Support\TagEditor;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Alle Tag-Änderungen (nur in der Datenbank, noch nicht in den Dateien): durchsuchen, filtern, sortieren und auf den
 * Stand der Datei zurücksetzen – einzeln (Feld eines Lieds) oder alle bzw. alle gefilterten.
 */
#[Layout('layouts.app')]
class ChangeLog extends Component
{
    use ShowsMessages;

    /** sortierbare Spalten → Spalte in der Datenbank */
    public const SORTS = [
        'created_at' => 'tag_changes.created_at',
        'title' => 'tracks.title',
        'location' => 'tracks.location',
        'field' => 'tag_changes.field',
        'source' => 'tag_changes.source',
        'reason' => 'tag_changes.reason',
    ];

    private const STEP = 200;

    private const GROUP_STEP = 30;

    #[Url]
    public string $q = '';

    #[Url]
    public string $field = '';

    #[Url]
    public string $source = '';

    #[Url]
    public string $location = '';

    #[Url]
    public string $sort = 'created_at';

    #[Url]
    public string $dir = 'desc';

    /** nach Album und Lied gruppiert (Standard) oder flache Liste */
    #[Url]
    public bool $grouped = true;

    public int $limit = self::GROUP_STEP;

    public function mount(): void
    {
        // gruppiert passt nur die Sortierung nach Zeit (Alben nach letzter Änderung)
        $this->grouped = $this->grouped && $this->sort === 'created_at';
    }

    public function updated(string $property): void
    {
        if ($property === 'grouped' && $this->grouped) {
            $this->sort = 'created_at';
            $this->dir = 'desc';
        }
        $this->limit = $this->grouped ? self::GROUP_STEP : self::STEP;
    }

    public function sortBy(string $column): void
    {
        if (! isset(self::SORTS[$column])) {
            return;
        }
        $this->dir = $this->sort === $column ? ($this->dir === 'asc' ? 'desc' : 'asc') : ($column === 'created_at' ? 'desc' : 'asc');
        $this->sort = $column;
        // nach anderem als der Zeit sortieren: flache Liste
        if ($column !== 'created_at') {
            $this->grouped = false;
        }
        $this->limit = $this->grouped ? self::GROUP_STEP : self::STEP;
    }

    public function loadMore(): void
    {
        $this->limit += $this->grouped ? self::GROUP_STEP : self::STEP;
    }

    public function resetFilters(): void
    {
        $this->reset('q', 'field', 'source', 'location');
    }

    /** ein Feld eines Lieds auf den Stand der Datei zurücksetzen (alle Änderungen dieses Felds) */
    public function revert(int $trackId, string $field, TagEditor $editor): void
    {
        $track = Track::find($trackId);
        $done = $track ? $editor->revert($track, [$field]) : [];
        $this->say($done ? '„'.($track->title ?: $track->filename).'“: '.(Track::EDITABLE[$field] ?? $field).' zurückgesetzt.' : 'Nichts zurückzusetzen.');
    }

    /** alle Änderungen zurücksetzen – bei gesetztem Filter nur die gefilterten */
    public function revertAll(TagEditor $editor): void
    {
        $fields = $this->query()->select(['tag_changes.track_id', 'tag_changes.field'])->distinct()->get()->groupBy('track_id');
        $count = 0;
        foreach ($fields->keys()->chunk(500) as $ids) {
            foreach (Track::whereIn('id', $ids)->get() as $track) {
                $count += count($editor->revert($track, $fields[$track->id]->pluck('field')->all()));
            }
        }
        $this->limit = $this->grouped ? self::GROUP_STEP : self::STEP;
        $this->say($count.' '.($count === 1 ? 'Feld' : 'Felder').' bei '.$fields->count().' '.($fields->count() === 1 ? 'Lied' : 'Liedern').' auf den Stand der Datei zurückgesetzt.');
    }

    private function query(): Builder
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($this->q)).'%';

        return TagChange::query()->join('tracks', 'tracks.id', '=', 'tag_changes.track_id')
            ->when(trim($this->q) !== '', fn ($w) => $w->where(fn ($x) => $x->where('tracks.title', 'like', $like)->orWhere('tracks.artist', 'like', $like)
                ->orWhere('tracks.filename', 'like', $like)->orWhere('tag_changes.old_value', 'like', $like)
                ->orWhere('tag_changes.new_value', 'like', $like)->orWhere('tag_changes.reason', 'like', $like)))
            ->when(isset(Track::EDITABLE[$this->field]), fn ($w) => $w->where('tag_changes.field', $this->field))
            // ermittelte Fingerabdrücke (zehntausende) nur, wenn ausdrücklich nach dem Feld gefiltert
            ->when($this->field !== 'fingerprint', fn ($w) => $w->where('tag_changes.field', '!=', 'fingerprint'))
            ->when(in_array($this->source, [TagEditor::MANUAL, TagEditor::SYSTEM], true), fn ($w) => $w->where('tag_changes.source', $this->source))
            ->when(isset(Locations::LABELS[$this->location]), fn ($w) => $w->where('tracks.location', $this->location));
    }

    private const COLUMNS = ['tag_changes.*', 'tracks.title', 'tracks.artist', 'tracks.filename', 'tracks.location', 'tracks.album', 'tracks.albumartist',
        'tracks.album_hash', 'tracks.album_dir', 'tracks.disc', 'tracks.track'];

    /** Cover gesetzt (Klick aufs Cover): neue Änderungen zeigen */
    #[On('cover-set')]
    public function coverSet(): void {}

    public function render()
    {
        $dir = $this->dir === 'asc' ? 'asc' : 'desc';
        $groups = collect();
        $rows = collect();
        if ($this->grouped) {
            // Alben nach letzter Änderung; darin Lieder nach CD/Nummer, je Lied die neueste Änderung zuerst
            $albums = $this->query()->selectRaw('tracks.album_hash, MAX(tag_changes.created_at) AS last_change, COUNT(*) AS changes')
                ->groupBy('tracks.album_hash')->orderBy('last_change', $dir)->orderByRaw('MAX(tag_changes.id) '.$dir)->limit($this->limit + 1)->get();
            $more = $albums->count() > $this->limit;
            $albums = $albums->take($this->limit);
            $changes = $albums->isEmpty() ? collect() : $this->query()->whereIn('tracks.album_hash', $albums->pluck('album_hash'))
                ->select(self::COLUMNS)->orderBy('tracks.disc')->orderBy('tracks.track')->orderBy('tracks.title')
                ->orderByDesc('tag_changes.created_at')->orderByDesc('tag_changes.id')->get()->groupBy('album_hash');
            $groups = $albums->map(function ($a) use ($changes) {
                $list = $changes[$a->album_hash] ?? collect();
                $first = $list->first();

                return [
                    'hash' => $a->album_hash,
                    'album' => $list->pluck('album')->filter()->countBy()->sortDesc()->keys()->first(),
                    'albumartist' => $list->pluck('albumartist')->filter()->countBy()->sortDesc()->keys()->first(),
                    'dir' => $first?->album_dir,
                    'location' => $first?->location,
                    'cover' => $first?->track_id,
                    'last' => $a->last_change,
                    'changes' => (int) $a->changes,
                    'tracks' => $list->groupBy('track_id'),
                ];
            })->filter(fn ($g) => $g['tracks']->isNotEmpty());
        } else {
            $column = self::SORTS[$this->sort] ?? self::SORTS['created_at'];
            $rows = $this->query()->select(self::COLUMNS)->orderBy($column, $dir)->orderBy('tag_changes.id', 'desc')->limit($this->limit + 1)->get();
            $more = $rows->count() > $this->limit;
            $rows = $rows->take($this->limit);
        }

        return view('livewire.change-log', [
            'rows' => $rows,
            'groups' => $groups,
            'more' => $more,
            'total' => $this->query()->count(),
            'filtered' => trim($this->q) !== '' || $this->field || $this->source || $this->location,
        ])->title('Änderungen – MusicWiz');
    }
}
