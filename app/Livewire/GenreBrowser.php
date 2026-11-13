<?php

namespace App\Livewire;

use App\Jobs\RelinkGenres;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\GenreIgnore;
use App\Models\GenreSynonym;
use App\Support\ArtistGenres;
use App\Support\GenreChecks;
use App\Support\GenreCleanup;
use App\Support\GenreTree;
use App\Support\OpenCounts;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Genrebaum ansehen und pflegen (Name, Einordnung, Beatport/Discogs, Synonyme, Untergenres) und gefundene Werte, die
 * keinem Genre zugeordnet sind, per Klick als Synonym einhängen. Nach Änderungen werden die Interpreten neu verknüpft.
 */
#[Layout('layouts.app')]
class GenreBrowser extends Component
{
    #[Url]
    public string $q = '';

    /** tree oder unmapped */
    #[Url]
    public string $tab = 'tree';

    public ?int $selected = null;

    public array $form = ['name' => '', 'parent_id' => null, 'beatport' => '', 'discogs' => ''];

    public string $newSynonym = '';

    public string $newChild = '';

    /** Zuordnen eines nicht zugeordneten Werts: Wert und Suche nach dem Ziel-Genre */
    public ?string $message = null;

    /** Nicht zugeordnet: gerade erledigte Werte bleiben grün mit Meldung stehen (Wert => Meldung) */
    public array $done = [];

    /** Nicht zugeordnet: feste Reihenfolge der Zeilen, damit Erledigtes an seinem Platz bleibt */
    public array $order = [];

    public ?string $error = null;

    public function open(int $id): void
    {
        $g = Genre::findOrFail($id);
        $this->selected = $g->id;
        $this->form = ['name' => $g->name, 'parent_id' => $g->parent_id, 'beatport' => (string) $g->beatport, 'discogs' => (string) $g->discogs];
        $this->newSynonym = $this->newChild = '';
        $this->message = $this->error = null;
    }

    public function close(): void
    {
        $this->selected = null;
    }

    public function save(): void
    {
        $g = Genre::findOrFail($this->selected);
        $name = trim($this->form['name']);
        if ($name === '') {
            $this->fail('Der Name darf nicht leer sein.');

            return;
        }
        $parentId = $this->form['parent_id'] ? (int) $this->form['parent_id'] : null;
        if ($problem = $this->moveProblem($g, $parentId, $name)) {
            $this->fail($problem);

            return;
        }
        DB::transaction(function () use ($g, $name, $parentId) {
            $g->update(['name' => $name, 'beatport' => trim($this->form['beatport']) ?: null, 'discogs' => trim($this->form['discogs']) ?: null]);
            $this->place($g, $parentId);
        });
        $this->changed('Gespeichert.');
    }

    /** Genre in eine andere Gruppe verschieben (null = Hauptgenre); die Ebene ergibt sich aus der Gruppe */
    public function move(int $id, ?int $parentId): void
    {
        $g = Genre::findOrFail($id);
        if ($problem = $this->moveProblem($g, $parentId, $g->name)) {
            $this->fail($problem);

            return;
        }
        DB::transaction(fn () => $this->place($g, $parentId));
        if ($this->selected === $g->id) {
            $this->open($g->id);
        }
        $this->changed('„'.$g->name.'“ → '.($parentId ? app(GenreTree::class)->path($parentId) : 'Hauptgenre').'.');
    }

    /** Neues Untergenre direkt in einer Gruppe anlegen */
    public function addChildTo(int $parentId, string $name): void
    {
        $parent = Genre::findOrFail($parentId);
        $name = trim($name);
        if ($name === '' || $parent->level >= 3) {
            $this->fail($name === '' ? 'Bitte einen Namen eingeben.' : 'Unter einem Style gibt es keine weitere Ebene.');

            return;
        }
        if (Genre::where('parent_id', $parent->id)->where('name', $name)->exists()) {
            $this->fail('„'.$name.'“ gibt es hier schon.');

            return;
        }
        Genre::create(['parent_id' => $parent->id, 'name' => $name, 'level' => $parent->level + 1, 'position' => (int) Genre::where('parent_id', $parent->id)->max('position') + 1]);
        $this->changed('„'.$name.'“ in '.app(GenreTree::class)->path($parent->id).' angelegt.');
    }

    private function moveProblem(Genre $g, ?int $parentId, string $name): ?string
    {
        if ($parentId && ($parentId === $g->id || in_array($parentId, $this->descendants($g->id), true))) {
            return 'Ein Genre kann nicht unter sich selbst eingeordnet werden.';
        }
        $level = $parentId ? Genre::findOrFail($parentId)->level + 1 : 1;
        if ($level + $this->depth($g->id) > 3) {
            return 'Der Baum hat höchstens drei Ebenen (Hauptgenre › Familie › Style).';
        }
        if (Genre::where('parent_id', $parentId)->where('name', $name)->where('id', '!=', $g->id)->exists()) {
            return '„'.$name.'“ gibt es an dieser Stelle schon.';
        }

        return null;
    }

    private function place(Genre $g, ?int $parentId): void
    {
        $level = $parentId ? Genre::findOrFail($parentId)->level + 1 : 1;
        if ($g->parent_id !== $parentId) {
            $g->position = (int) Genre::where('parent_id', $parentId)->max('position') + 1;
        }
        $g->update(['parent_id' => $parentId, 'level' => $level]);
        $this->relevel($g->id, $level);
    }

    public function addSynonym(): void
    {
        $synonym = trim($this->newSynonym);
        if ($synonym === '') {
            return;
        }
        GenreSynonym::firstOrCreate(['genre_id' => $this->selected, 'synonym' => mb_substr($synonym, 0, 128)]);
        $this->newSynonym = '';
        $this->changed('Synonym hinzugefügt.');
    }

    public function removeSynonym(int $id): void
    {
        GenreSynonym::where('genre_id', $this->selected)->whereKey($id)->delete();
        $this->changed('Synonym entfernt.');
    }

    public function addChild(): void
    {
        $parent = Genre::findOrFail($this->selected);
        $name = trim($this->newChild);
        if ($name === '' || $parent->level >= 3) {
            $this->fail($name === '' ? 'Bitte einen Namen eingeben.' : 'Unter einem Style gibt es keine weitere Ebene.');

            return;
        }
        if (Genre::where('parent_id', $parent->id)->where('name', $name)->exists()) {
            $this->fail('„'.$name.'“ gibt es hier schon.');

            return;
        }
        $child = Genre::create(['parent_id' => $parent->id, 'name' => $name, 'level' => $parent->level + 1, 'position' => (int) Genre::where('parent_id', $parent->id)->max('position') + 1]);
        $this->newChild = '';
        $this->open($child->id);
        $this->changed('„'.$name.'“ angelegt.');
    }

    public function addMain(string $name): void
    {
        $name = trim($name);
        if ($name !== '' && ! Genre::whereNull('parent_id')->where('name', $name)->exists()) {
            $g = Genre::create(['parent_id' => null, 'name' => $name, 'level' => 1, 'position' => (int) Genre::whereNull('parent_id')->max('position') + 1]);
            $this->open($g->id);
            $this->changed('Hauptgenre „'.$name.'“ angelegt.');
        }
    }

    public function delete(): void
    {
        $g = Genre::findOrFail($this->selected);
        if ($g->children()->exists()) {
            $this->fail('Erst die Untergenres entfernen oder verschieben.');

            return;
        }
        $g->delete();
        $this->selected = null;
        $this->changed('„'.$g->name.'“ gelöscht.');
    }

    /** Nicht zugeordneten Wert als Synonym an ein Genre hängen (Vorschlag oder Suche) */
    public function assign(string $value, int $genreId): void
    {
        GenreSynonym::firstOrCreate(['genre_id' => $genreId, 'synonym' => mb_substr($value, 0, 128)]);
        $path = app(GenreTree::class)->path($genreId);
        $this->done[$value] = 'zugeordnet → '.$path;
        $this->changed('„'.$value.'“ → '.$path);
    }

    /**
     * Neues Genre für einen nicht zugeordneten Wert anlegen – unter $parentId (null = Hauptgenre) – und den Wert damit
     * verknüpfen: heißt das Genre anders als der Wert, wird der Wert sein Synonym.
     */
    public function createAndAssign(string $value, string $name, ?int $parentId): void
    {
        try {
            $genre = app(GenreTree::class)->create($name, $parentId);
        } catch (InvalidArgumentException $e) {
            $this->fail($e->getMessage());

            return;
        }
        if (GenreTree::key($genre->name) !== GenreTree::key($value)) {
            GenreSynonym::firstOrCreate(['genre_id' => $genre->id, 'synonym' => mb_substr($value, 0, 128)]);
        }
        app(GenreTree::class)->forget();
        $path = app(GenreTree::class)->path($genre->id);
        $this->done[$value] = 'neu angelegt → '.$path;
        $this->changed('„'.$genre->name.'“ angelegt ('.$path.') und „'.$value.'“ verknüpft.');
    }

    /** Zu breit („Rock/Pop“): nicht zuordnen, sondern aus den Liedern entfernen */
    public function ignore(string $value): void
    {
        GenreIgnore::firstOrCreate(['value' => mb_substr(trim($value), 0, 128)]);
        app(GenreTree::class)->forget();
        $n = app(GenreCleanup::class)->apply();
        $this->done[$value] = 'zu breit – wird entfernt, '.$n.' '.($n === 1 ? 'Lied' : 'Lieder').' angepasst';
        $this->changed('„'.$value.'“ wird entfernt – '.$n.' '.($n === 1 ? 'Lied' : 'Lieder').' angepasst (siehe Änderungen).');
    }

    /** Zurücknehmen: der Wert erscheint wieder unter „Nicht zugeordnet“ (angepasste Lieder bleiben) */
    public function unignore(int $id): void
    {
        $ignore = GenreIgnore::findOrFail($id);
        $ignore->delete();
        $this->changed('„'.$ignore->value.'“ wird nicht mehr entfernt.');
    }

    /** Nach jeder Änderung am Baum: Interpreten im Hintergrund neu verknüpfen */
    private function changed(string $message): void
    {
        app(GenreTree::class)->forget();
        RelinkGenres::dispatch();
        $this->error = null;
        $this->message = $message;
    }

    private function fail(string $message): void
    {
        $this->message = null;
        $this->error = $message;
    }

    /** @return list<int> */
    private function descendants(int $id): array
    {
        $out = [];
        foreach (Genre::where('parent_id', $id)->pluck('id') as $child) {
            $out = [...$out, $child, ...$this->descendants($child)];
        }

        return $out;
    }

    private function depth(int $id): int
    {
        $max = 0;
        foreach (Genre::where('parent_id', $id)->pluck('id') as $child) {
            $max = max($max, 1 + $this->depth($child));
        }

        return $max;
    }

    private function relevel(int $id, int $level): void
    {
        foreach (Genre::where('parent_id', $id)->get() as $child) {
            $child->update(['level' => $level + 1]);
            $this->relevel($child->id, $level + 1);
        }
    }

    public function render(ArtistGenres $artistGenres)
    {
        $all = Genre::withCount('synonyms')->orderBy('position')->orderBy('name')->get();
        $artists = DB::table('artist_genre')->selectRaw('genre_id, COUNT(DISTINCT artist_id) AS n')->groupBy('genre_id')->pluck('n', 'genre_id');
        $byParent = $all->groupBy(fn ($g) => $g->parent_id ?? 0);
        // Suche: Treffer (Name oder Synonym) samt ihrer Vorfahren zeigen
        $visible = null;
        if (trim($this->q) !== '') {
            $key = GenreTree::key($this->q);
            $hits = $all->filter(fn ($g) => str_contains(GenreTree::key($g->name), $key))->pluck('id')
                ->merge(GenreSynonym::get(['genre_id', 'synonym'])->filter(fn ($s) => str_contains(GenreTree::key($s->synonym), $key))->pluck('genre_id'));
            $visible = [];
            $parents = $all->pluck('parent_id', 'id');
            foreach ($hits as $id) {
                for ($n = $id; $n; $n = $parents[$n] ?? null) {
                    $visible[$n] = true;
                }
            }
        }
        $genre = $this->selected ? Genre::with(['synonyms', 'children', 'parent'])->find($this->selected) : null;
        $unmapped = $allUnmapped = [];
        if ($this->tab === 'unmapped') {
            // einmal rechnen: angezeigt die ersten 300, gezählt alle
            $allUnmapped = $artistGenres->unmapped(PHP_INT_MAX);
            $byValue = [];
            foreach (array_slice($allUnmapped, 0, 300) as $u) {
                $byValue[$u['value']] = $u + ['suggestions' => app(GenreTree::class)->suggest($u['value'])];
            }
            // bisherige Zeilen bleiben stehen (Erledigtes grün), neue kommen hinten dazu
            $this->order = [...array_values(array_filter($this->order, fn ($v) => isset($byValue[$v]) || isset($this->done[$v]))),
                ...array_values(array_diff(array_keys($byValue), $this->order))];
            $unmapped = array_map(fn ($v) => $byValue[$v] ?? ['value' => $v, 'done' => $this->done[$v]], $this->order);
        }

        return view('livewire.genre-browser', [
            'byParent' => $byParent,
            'artistCounts' => $artists,
            'visible' => $visible,
            'total' => $all->count(),
            'unmapped' => $unmapped,
            'open' => $this->tab === 'unmapped' ? ['unmapped' => count($allUnmapped)] : app(OpenCounts::class)->genres(),
            'genreIndex' => $this->tab === 'unmapped' ? app(GenreChecks::class)->searchIndex() : [],
            'ignores' => $this->tab === 'unmapped' ? GenreIgnore::orderBy('value')->get() : collect(),
            'genre' => $genre,
            'path' => $genre ? app(GenreTree::class)->path($genre->id) : null,
            'parents' => $genre ? $all->where('level', '<', 3)->reject(fn ($g) => $g->id === $genre->id)->map(fn ($g) => ['id' => $g->id, 'path' => app(GenreTree::class)->path($g->id), 'level' => $g->level])
                ->sortBy('path')->values() : collect(),
            // Ziele fürs Verschieben (Hauptgenres und Familien)
            'targets' => $all->where('level', '<', 3)->map(fn ($g) => ['id' => $g->id, 'level' => $g->level, 'path' => app(GenreTree::class)->path($g->id)])->sortBy('path')->values(),
            'linkedArtists' => $genre ? Artist::whereIn('id', DB::table('artist_genre')->where('genre_id', $genre->id)->select('artist_id'))->orderBy('name')->limit(40)->get(['id', 'name', 'image']) : collect(),
        ])->title('Genres – MusicWiz');
    }
}
