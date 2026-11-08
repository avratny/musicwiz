<?php

namespace App\Livewire;

use App\Jobs\PrefetchArtistLookup;
use App\Livewire\Concerns\RatesMusic;
use App\Models\Album;
use App\Models\Artist;
use App\Models\DiscSet;
use App\Models\HistoryEntry;
use App\Models\Operation;
use App\Models\Track;
use App\Support\AlbumPlanner;
use App\Support\ArtistDirectory;
use App\Support\ArtistNames;
use App\Support\Covers;
use App\Support\Genres;
use App\Support\InboxMatcher;
use App\Support\Lookup;
use App\Support\OperationRunner;
use App\Support\PathBuilder;
use App\Support\Scanner;
use App\Support\Settings;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
class AlbumEditor extends Component
{
    use RatesMusic, WithFileUploads;

    public int $albumId;

    public array $fields = [];

    public array $rows = [];

    public $coverUpload;

    public ?string $newCover = null;

    public string $coverUrl = '';

    public bool $removeCover = false;

    /** Inbox: Kategorie „Lib“ vorgeschlagen, weil alle Titel denselben Künstler haben (nur Vorauswahl, nichts geschrieben) */
    public ?string $categoryGuessed = null;

    /** unbekannte Künstler, die schon im Hintergrund nachgeschlagen werden (Dialog „erfassen“ ist dann sofort gefüllt) */
    public array $prefetched = [];

    public ?array $plan = null;

    public bool $planMove = false;

    public string $onConflict = 'best';

    public ?int $operationId = null;

    /** Nach dem Einordnen in der Inbox: zu diesem Album weiter */
    public ?int $nextAlbumId = null;

    public array $genreLookup = [];

    public ?string $lookupError = null;

    public int $discForAll = 1;

    /** Mehrfach-CD von Hand: Auswahl */
    public bool $addDiscOpen = false;

    public ?int $addDiscAlbum = null;

    public int $addDiscNumber = 2;

    /** Gleichnamige Künstler im Album: [['base' => 'Adam', 'full' => 'Adam [DE]'], ...] */
    public array $persons = [];

    /**
     * Album bewusst als Zahl statt per Route-Model-Binding: Livewire wiederholt bei jeder Hintergrund-Anfrage die
     * Middleware der Seiten-Route. Ist das Album inzwischen umgezogen (neuer Datensatz), gäbe sonst jede Anfrage 404.
     */
    public function mount(int|string|Album $album): void
    {
        $album = $album instanceof Album ? $album : Album::find((int) $album);
        abort_unless($album, 404);
        // im Blackhole wird nichts bearbeitet – nur gesucht und zurück in die Inbox geholt
        if ($album->location === 'blackhole') {
            $this->redirect($album->editUrl(), navigate: true);

            return;
        }
        $this->albumId = $album->id;
        $this->loadAlbum($album);
    }

    private function loadAlbum(Album $album): void
    {
        $genres = app(Genres::class);
        $tracks = $album->tracks()->get();
        $first = $tracks->first();
        $mode = fn (string $k) => $tracks->pluck($k)->filter()->countBy()->sortDesc()->keys()->first();
        $category = $album->category;
        // Inbox ohne Kategorie-Kommentar: ein einziger Künstler bei allen Titeln → Lib vorauswählen
        $this->categoryGuessed = null;
        if ($category === null && $album->location === 'inbox' && ($guess = $this->singleArtistCategory($tracks->all(), (string) ($mode('albumartist') ?? '')))) {
            $category = $this->categoryGuessed = $guess;
        }

        $isSet = $album->discCount() > 1;
        $this->fields = [
            // Mehrfach-CD: "…, Disc 1" aus dem Albumnamen entfernen
            'album' => $isSet ? (string) $album->title : (string) ($mode('album') ?? ''),
            'albumartist' => (string) ($mode('albumartist') ?? ''),
            'year' => (string) ($mode('year') ?? ''),
            'genres' => $genres->selection($first?->genres ?? []),
            'category' => $category,
        ];
        // Mehrfach-CD: CD-Nummer aus dem Ordnernamen, wenn der Tag nicht passt (z. B. beide CDs "1/1")
        $discOf = fn (Track $t) => $isSet ? (string) ($album->discForDir($t->dir) ?? Scanner::discFromName(basename($t->dir)) ?? Scanner::discFromName($t->album) ?? $t->disc) : (string) $t->disc;
        $tracks = $tracks->sortBy(fn (Track $t) => sprintf('%03d-%04d-%s', (int) $discOf($t), (int) $t->track, $t->filename))->values();
        $this->rows = $tracks->map(fn (Track $t) => [
            'id' => $t->id,
            'disc' => $discOf($t),
            'track' => (string) $t->track,
            'title' => (string) $t->title,
            'artist' => (string) $t->artist,
            'genres' => $genres->selection($t->genres ?? []),
            'filename' => $t->filename,
            'dir' => $t->dir,
            'duration' => $t->durationLabel(),
            'seconds' => (float) ($t->duration ?? 0),
            'ext' => $t->ext,
            'writable' => $t->writable,
            'error' => $t->error,
            'rating' => $t->rating,
        ])->values()->all();
        $this->discForAll = max(1, (int) ($mode('disc') ?? 1));
        $this->persons = $this->detectPersons($tracks->all());
    }

    /**
     * Kategorie „Lib“ (Künstler, kein Sampler), wenn alle Titel denselben Hauptkünstler haben – „feat.“-Gäste zählen
     * nicht. Kein Vorschlag bei fehlendem Künstler oder Sampler-Albumkünstler („Various Artists“).
     *
     * @param  list<Track>  $tracks
     */
    private function singleArtistCategory(array $tracks, string $albumArtist): ?string
    {
        $settings = app(Settings::class);
        if (! $tracks || strcasecmp(trim($albumArtist), (string) $settings->get('compilation_artist')) === 0) {
            return null;
        }
        $main = [];
        foreach ($tracks as $t) {
            $first = trim((string) (ArtistNames::split($t->artist)[0] ?? ''));
            if ($first === '') {
                return null;
            }
            $main[mb_strtolower(PathBuilder::folderArtist(['artist' => $first]))] = true;
        }
        if (count($main) !== 1) {
            return null;
        }
        $lib = collect($settings->categoriesIn('library'))->first(fn ($c) => ! $c['compilation']);

        return $lib['name'] ?? null;
    }

    /** Unbekannte Künstler im Hintergrund nachschlagen (MusicBrainz, Discogs, Bilder) – nicht bei sync-Warteschlange. */
    private function prefetchUnknown(array $names): void
    {
        if (config('queue.default') === 'sync') {
            return;
        }
        foreach (array_unique($names) as $name) {
            if (in_array($name, $this->prefetched, true)) {
                continue;
            }
            $this->prefetched[] = $name;
            $key = ArtistNames::compareKey($name);
            $titles = array_values(array_unique(array_column(array_filter($this->rows,
                fn ($r) => collect([...ArtistNames::split($r['artist']), ...ArtistNames::split($this->fields['albumartist'])])->contains(fn ($n) => ArtistNames::compareKey($n) === $key)), 'title')));
            PrefetchArtistLookup::dispatch($name, array_slice(array_filter($titles), 0, 3));
        }
    }

    /** Künstler im Dialog erfasst: Hinweise („neu: …“, Schreibweisen) neu berechnen */
    #[On('artist-directory-changed')]
    public function artistDirectoryChanged(): void {}

    /** Nach dem Bewerten die Sterne in der Liste nachziehen (der Rest des Formulars bleibt, wie er ist). */
    protected function afterRating(?object $operation): void
    {
        $ratings = Track::whereIn('id', array_column($this->rows, 'id'))->pluck('rating', 'id');
        foreach ($this->rows as $i => $row) {
            $this->rows[$i]['rating'] = $ratings[$row['id']] ?? null;
        }
        if ($operation) {
            $this->dispatch('activity-changed');
        }
    }

    public function album(): Album
    {
        return Album::findOrFail($this->albumId);
    }

    // ---------------------------------------------------------------- Hilfen

    public function renumber(): void
    {
        $counters = [];
        foreach ($this->rows as $i => $row) {
            $d = (int) $row['disc'] ?: 1;
            $counters[$d] = ($counters[$d] ?? 0) + 1;
            $this->rows[$i]['track'] = (string) $counters[$d];
        }
    }

    /** CD-Kopfzeile: Nummer für alle Tracks dieser CD ändern (zusammenhängende Zeilen ab $start). */
    public function setDiscGroup(int $start, string $value): void
    {
        $new = max(1, (int) $value);
        $old = $this->rows[$start]['disc'] ?? null;
        if ($old === null || (string) $new === (string) $old) {
            return;
        }
        $dirs = [];
        for ($i = $start; $i < count($this->rows) && $this->rows[$i]['disc'] === $old; $i++) {
            $this->rows[$i]['disc'] = (string) $new;
            $dirs[$this->rows[$i]['dir']] = true;
        }

        // Mehrfach-CD aus mehreren Ordnern: Zuordnung merken, damit sie nach einem Scan nicht zurückspringt
        $album = $this->album();
        if ($album->discCount() > 1) {
            $map = $this->currentDiscMap($album);
            foreach (array_keys($dirs) as $dir) {
                if (isset($map[$dir])) {
                    $map[$dir] = $new;
                }
            }
            foreach (DiscSet::all() as $set) {
                if (array_intersect(array_column($set->members, 'dir'), array_keys($map))) {
                    $set->delete();
                }
            }
            DiscSet::create(['members' => array_map(fn ($dir, $disc) => ['dir' => $dir, 'disc' => (int) $disc], array_keys($map), $map)]);
            $album->update(['disc_map' => $map]);
        }
    }

    public function setDiscForAll(): void
    {
        foreach ($this->rows as $i => $row) {
            $this->rows[$i]['disc'] = (string) max(1, $this->discForAll);
        }
    }

    public function applyArtist(int $index, string $value): void
    {
        $this->rows[$index]['artist'] = $value;
    }

    public function applyAlbumArtist(string $value): void
    {
        $this->fields['albumartist'] = $value;
    }

    public function applyAllArtistCorrections(): void
    {
        $dir = app(ArtistDirectory::class);
        foreach ($this->rows as $i => $row) {
            $this->rows[$i]['artist'] = app(PathBuilder::class)->uniformSpelling($dir->correctText($row['artist']));
        }
        $this->fields['albumartist'] = app(PathBuilder::class)->uniformSpelling($dir->correctText($this->fields['albumartist']));
    }

    public function copyArtistToAlbumArtist(): void
    {
        $artists = array_unique(array_filter(array_column($this->rows, 'artist')));
        if (count($artists) === 1) {
            $this->fields['albumartist'] = reset($artists);
        }
    }

    /** Sampler: Album-Genre auf alle Titel übernehmen (ersetzt abweichende Genres einzelner Titel). */
    public function applyGenreToAllTracks(): void
    {
        foreach ($this->rows as $i => $row) {
            $this->rows[$i]['genres'] = $this->fields['genres'];
        }
    }

    public function toggleGenre(string $name, ?int $row = null): void
    {
        $list = $row === null ? $this->fields['genres'] : $this->rows[$row]['genres'];
        if (in_array($name, $list, true)) {
            $list = array_values(array_diff($list, [$name]));
        } else {
            $list[] = $name;
            $list = array_slice($list, -2); // höchstens zwei
        }
        if ($row === null) {
            $this->fields['genres'] = $list;
        } else {
            $this->rows[$row]['genres'] = $list;
        }
    }

    public function updatedCoverUpload(): void
    {
        $this->validate(['coverUpload' => 'image|max:20480']);
        $this->newCover = app(Covers::class)->normalizeUpload($this->coverUpload->getRealPath());
        $this->removeCover = false;
        $this->coverUpload = null;
    }

    public function loadCoverUrl(Covers $covers): void
    {
        $this->resetErrorBag('coverUrl');
        try {
            $this->newCover = $covers->download($this->coverUrl);
            $this->removeCover = false;
            $this->coverUrl = '';
        } catch (\Throwable $e) {
            $this->addError('coverUrl', $e->getMessage());
        }
    }

    public function markRemoveCover(): void
    {
        $this->newCover = null;
        $this->removeCover = true;
    }

    public function discardCover(): void
    {
        $this->newCover = null;
        $this->removeCover = false;
    }

    /** Jahr und Genre nachschlagen (Sampler: nach Albumname, nicht nach einem Track-Interpreten). */
    public function lookupGenres(): void
    {
        $this->genreLookup = [];
        $this->lookupError = null;
        $compilation = (bool) (app(Settings::class)->category($this->fields['category'])['compilation'] ?? false)
            || (bool) Album::find($this->albumId)?->is_compilation
            || strcasecmp(trim($this->fields['albumartist']), (string) app(Settings::class)->get('compilation_artist')) === 0;
        $artist = $this->fields['albumartist'] ?: ($this->rows[0]['artist'] ?? '');
        $artist = ArtistNames::split($artist)[0] ?? $artist;
        $result = app(Lookup::class)->albumCandidates($compilation ? null : $artist, $this->fields['album'], $compilation);
        $genres = app(Genres::class);
        foreach ($result['candidates'] as $c) {
            $c['suggestion'] = $genres->suggest($c['genres']);
            $this->genreLookup[] = $c;
        }
        if (! $this->genreLookup) {
            $this->lookupError = 'Nichts gefunden'.($result['errors'] ? ' ('.implode(' · ', $result['errors']).')' : '').'.';
        }
    }

    public function useYear(string $year): void
    {
        $this->fields['year'] = $year;
    }

    public function useGenres(array $selection): void
    {
        $this->fields['genres'] = array_slice($selection, 0, 2);
    }

    // ---------------------------------------------------------------- Vorschau & Ausführen

    public function preview(bool $move): void
    {
        if ($move && ! $this->fields['category']) {
            $this->addError('category', 'Bitte zuerst eine Kategorie wählen.');

            return;
        }
        $this->resetErrorBag();
        $this->planMove = $move;
        $this->plan = app(AlbumPlanner::class)->plan($this->album(), $this->plannerFields(), $this->trackEdits(), $move, $this->newCover, $this->removeCover);
    }

    public function closePreview(): void
    {
        $this->plan = null;
    }

    public function confirm(): void
    {
        $planner = app(AlbumPlanner::class);
        $plan = $planner->plan($this->album(), $this->plannerFields(), $this->trackEdits(), $this->planMove, $this->newCover, $this->removeCover);
        $actions = $planner->actions($plan, $this->onConflict);
        if (! $actions) {
            $this->plan = null;
            session()->flash('status', 'Keine Änderungen.');

            return;
        }
        $album = $this->album();
        $verb = $this->planMove ? ($album->location === 'inbox' ? 'Einordnen' : 'Speichern & verschieben') : 'Tags speichern';
        // Inbox + Einordnen: danach direkt zum nächsten Album der Inbox-Liste (vor dem Start ermitteln)
        $this->nextAlbumId = $this->planMove && $album->location === 'inbox' ? Inbox::neighbourOf($album->id) : null;
        $op = OperationRunner::dispatch('album', $verb.': '.($this->fields['albumartist'] ?: '').' – '.$this->fields['album'].($this->fields['category'] ? ' ['.$this->fields['category'].']' : ''), $actions, ['on_conflict' => $this->onConflict]);
        $this->operationId = $op->id;

        $this->plan = null;
        $this->newCover = null;
        $this->removeCover = false;
        $this->dispatch('activity-changed');
    }

    /** Wird gepollt, solange eine Aktion läuft. */
    public function checkOperation()
    {
        $op = $this->operationId ? Operation::find($this->operationId) : null;
        if (! $op || $op->isActive()) {
            return null;
        }
        $this->operationId = null;
        if ($op->status === 'failed') {
            session()->flash('error', 'Nicht alles hat geklappt: '.$op->error);
        } else {
            session()->flash('status', 'Erledigt: '.$op->description);
        }

        $album = Album::find($this->albumId);
        if (! $album) {
            // Album ist umgezogen: über den Verlauf das neue Album finden
            // Ziel über ein verschobenes Lied finden (nicht über Cover oder Extras)
            $audio = config('musicwiz.audio_extensions');
            $newPath = HistoryEntry::where('operation_id', $op->id)->where('kind', 'move')->whereNotNull('new_path')->latest('id')->pluck('new_path')
                ->first(fn ($p) => in_array(strtolower(pathinfo($p, PATHINFO_EXTENSION)), $audio, true));
            $track = $newPath ? Track::where('path', $newPath)->first() : null;
            $album = $track?->albumRecord;

            // Einordnen aus der Inbox ohne Fehler: weiter zum nächsten Inbox-Album
            if ($op->status !== 'failed' && $this->nextAlbumId !== null) {
                $next = Album::where('location', 'inbox')->find($this->nextAlbumId);
                session()->flash('status', '✓ Eingeordnet: '.$op->description);
                if ($album) {
                    session()->flash('filed_url', $album->editUrl());
                }

                return $next ? $this->redirect($next->editUrl(), navigate: true) : $this->redirectRoute('inbox', navigate: true);
            }
            if (! $album) {
                return $this->redirectRoute('inbox', navigate: true);
            }

            return $this->redirect($album->editUrl(), navigate: true);
        }
        if ($album->location === 'blackhole') {
            return $this->redirect($album->editUrl(), navigate: true);
        }
        $this->loadAlbum($album);

        return null;
    }

    // ---------------------------------------------------------------- Mehrfach-CD von Hand

    public function openAddDisc(): void
    {
        $album = $this->album();
        $this->addDiscOpen = true;
        $this->addDiscAlbum = null;
        $numbers = $album->discCount() > 1 ? $album->discNumbers() : [1];
        $this->addDiscNumber = $album->missingDiscs()[0] ?? (max($numbers) + 1);
    }

    /** Andere Inbox-Alben als mögliche weitere CD, nach Ähnlichkeit sortiert. */
    private function discCandidates(Album $album): array
    {
        $words = fn (?string $s) => array_filter(preg_split('/[^a-z0-9]+/', strtolower(ArtistNames::stripAccents(Scanner::stripDisc($s)))), fn ($w) => strlen($w) >= 3);
        $mine = $words($album->title);
        $parent = dirname($album->dir);
        $out = [];
        foreach (Album::where('location', 'inbox')->where('id', '!=', $album->id)->get() as $other) {
            if (dirname($other->dir) !== $parent) {
                continue;
            }
            $score = 0;
            $reasons = [];
            $common = array_intersect($mine, $words($other->title));
            if ($common) {
                $score += 3 * count($common) / max(1, count(array_unique([...$mine, ...$words($other->title)])));
                $reasons[] = 'gemeinsame Wörter: '.implode(', ', array_slice(array_unique($common), 0, 4));
            }
            if ($album->year && $album->year === $other->year) {
                $score += 2;
                $reasons[] = 'gleiches Jahr '.$other->year;
            }
            if ((string) $album->albumartist === (string) $other->albumartist) {
                $score += 0.5;
            }
            $avg = $album->track_count / max(1, $album->discCount());
            if ($avg && abs($other->track_count - $avg) <= 3) {
                $score += 0.5;
                $reasons[] = 'ähnliche Trackzahl';
            }
            $out[] = ['album' => $other, 'score' => $score, 'reasons' => $reasons];
        }
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($out, 0, 12);
    }

    /** Aktuelle CDs des Albums mit Nummer: [dir => disc] */
    private function currentDiscMap(Album $album): array
    {
        $map = [];
        foreach ($album->allDirs() as $i => $dir) {
            $map[$dir] = $album->discForDir($dir) ?? Scanner::discFromName(basename($dir))
                ?? ($album->discCount() > 1 ? $i + 1 : (int) ($album->tracks()->where('dir', $dir)->value('disc') ?: 1));
        }

        return $map;
    }

    public function addDisc(Scanner $scanner)
    {
        $album = $this->album();
        $other = Album::where('location', 'inbox')->find($this->addDiscAlbum);
        if (! $other) {
            $this->addError('addDisc', 'Bitte ein Album wählen.');

            return null;
        }
        $map = $this->currentDiscMap($album);
        if (in_array($this->addDiscNumber, $map, true)) {
            $this->addError('addDisc', 'CD '.$this->addDiscNumber.' gibt es schon.');

            return null;
        }
        $n = max(1, $this->addDiscNumber);
        foreach ($other->allDirs() as $dir) {
            $map[$dir] = $n++;
        }
        // bestehende Zuordnungen dieser Ordner ersetzen
        foreach (DiscSet::all() as $set) {
            if (array_intersect(array_column($set->members, 'dir'), array_keys($map))) {
                $set->delete();
            }
        }
        DiscSet::create(['members' => array_map(fn ($dir, $disc) => ['dir' => $dir, 'disc' => (int) $disc], array_keys($map), $map)]);
        $scanner->rebuildAlbums('inbox');
        $first = array_search(min($map), $map, true);
        $new = Album::where('dir', $first)->first();
        session()->flash('status', 'CD hinzugefügt: '.$other->title.' als CD '.$this->addDiscNumber.'.');

        return $this->redirect($new ? $new->editUrl() : route('inbox'), navigate: true);
    }

    public function removeDisc(string $dir, Scanner $scanner)
    {
        $set = DiscSet::forDir($dir);
        if (! $set) {
            return null;
        }
        $members = array_values(array_filter($set->members, fn ($m) => $m['dir'] !== $dir));
        count($members) >= 2 ? $set->update(['members' => $members]) : $set->delete();
        $scanner->rebuildAlbums('inbox');
        $remaining = $members[0]['dir'] ?? $this->album()->dir;
        $album = Album::where('dir', $remaining)->first() ?? Album::where('dir', $dir)->first();
        session()->flash('status', 'CD herausgelöst: '.basename($dir));

        return $this->redirect($album ? $album->editUrl() : route('inbox'), navigate: true);
    }

    /** Mehrdeutige Namen im Album und ihre bisherige Zuordnung (aus ARTISTS/ALBUMARTISTS). */
    private function detectPersons(array $tracks): array
    {
        $dir = app(ArtistDirectory::class);
        $found = [];
        foreach ($tracks as $t) {
            foreach ([[$t->artist, $t->artists], [$t->albumartist, $t->albumartists]] as [$text, $list]) {
                foreach (ArtistNames::split($text) as $n) {
                    if ($dir->personsFor($n)) {
                        $r = $dir->resolveNames($n, (array) ($list ?? []));
                        $key = ArtistNames::compareKey($n);
                        $found[$key] ??= ['base' => $n, 'full' => ''];
                        if (! $r['unresolved'] && $found[$key]['full'] === '') {
                            $found[$key]['full'] = $r['names'][0];
                        }
                    }
                }
            }
        }

        return array_values($found);
    }

    /** Genre pro Lied: bei Samplern und in "Kein Album"-Ordnern (Einzellieder). */
    private function perTrackGenres(): bool
    {
        return (bool) (app(Settings::class)->category($this->fields['category'])['compilation'] ?? false) || $this->isLooseFolder();
    }

    private function isLooseFolder(): bool
    {
        $album = Album::find($this->albumId);

        return $album && basename($album->dir) === app(Settings::class)->get('no_album');
    }

    private function plannerFields(): array
    {
        $map = [];
        foreach ($this->persons as $p) {
            if ($p['full'] !== '') {
                $map[$p['base']] = $p['full'];
            }
        }

        $fields = $this->fields + ['persons' => $map];
        if ($this->isLooseFolder()) {
            unset($fields['genres']); // Einzellieder: kein gemeinsames Genre, nur pro Lied
        }

        return $fields;
    }

    private function trackEdits(): array
    {
        $perTrack = $this->perTrackGenres();
        $edits = [];
        foreach ($this->rows as $row) {
            $edit = ['title' => $row['title'], 'artist' => $row['artist'], 'track' => $row['track'], 'disc' => $row['disc']];
            if ($perTrack && $row['genres']) {
                $edit['genres'] = $row['genres'];
            }
            $edits[$row['id']] = $edit;
        }

        return $edits;
    }

    public function render(Settings $settings, Genres $genres, ArtistDirectory $directory)
    {
        $album = Album::find($this->albumId);
        if (! $album || $album->location === 'blackhole') {
            // während des Umzugs ist das alte Album schon weg (bzw. liegt im Blackhole – dorthin wird weitergeleitet)
            return view('livewire.album-editor-moving');
        }
        $category = $settings->category($this->fields['category']);
        $compilationArtist = $settings->get('compilation_artist');

        $hints = [];
        foreach ($this->rows as $i => $row) {
            $corrected = app(PathBuilder::class)->uniformSpelling($directory->correctText($row['artist']));
            if ($row['artist'] !== '' && $corrected !== $row['artist']) {
                $hints[$i] = ['type' => 'fix', 'value' => $corrected];
            } else {
                $unknown = array_values(array_filter(ArtistNames::split($row['artist']), fn ($n) => $directory->check($n)['status'] === 'new'));
                if ($unknown) {
                    $hints[$i] = ['type' => 'new', 'value' => implode(', ', $unknown), 'names' => $unknown];
                }
            }
        }
        $albumArtistFix = app(PathBuilder::class)->uniformSpelling($directory->correctText($this->fields['albumartist']));
        $albumArtistNew = array_values(array_filter(ArtistNames::split($this->fields['albumartist']),
            fn ($n) => strcasecmp($n, (string) $compilationArtist) !== 0 && $directory->check($n)['status'] === 'new'));
        $this->prefetchUnknown([...$albumArtistNew, ...array_merge(...array_map(fn ($h) => $h['names'] ?? [], array_values($hints)))]);
        $renames = $directory->renamesFor([$this->fields['albumartist'], ...array_column($this->rows, 'artist')]);

        // Gleichnamige Künstler: auch Namen berücksichtigen, die gerade erst eingetippt wurden
        $known = array_map(fn ($p) => ArtistNames::compareKey($p['base']), $this->persons);
        foreach ([...array_column($this->rows, 'artist'), $this->fields['albumartist']] as $text) {
            foreach (ArtistNames::split($text) as $n) {
                $key = ArtistNames::compareKey($n);
                if (! in_array($key, $known, true) && $directory->personsFor($n)) {
                    $this->persons[] = ['base' => $n, 'full' => ''];
                    $known[] = $key;
                }
            }
        }
        $personOptions = [];
        foreach ($this->persons as $i => $p) {
            $personOptions[$i] = array_map(fn ($a) => ['name' => $a->name, 'label' => $a->name.($a->disambiguation ? ' – '.$a->disambiguation : '')], $directory->personsFor($p['base']));
        }

        $defaultGenre = null;
        $firstArtist = ArtistNames::split($this->fields['albumartist'] ?: ($this->rows[0]['artist'] ?? ''))[0] ?? null;
        if ($firstArtist) {
            $canonical = $directory->check($firstArtist)['canonical'] ?? $firstArtist;
            $defaultGenre = Artist::where('name', $canonical)->value('default_genre');
        }
        $trackGenres = Track::where('album_id', $album->id)->pluck('genres')->flatten()->filter()->unique()->values()->all();
        $genreSuggestion = $genres->suggest($trackGenres);

        $op = $this->operationId ? Operation::find($this->operationId) : null;

        return view('livewire.album-editor', [
            'album' => $album,
            'categories' => $settings->categories(),
            'category' => $category,
            'genreOptions' => $genres->options(),
            'hints' => $hints,
            'renames' => $renames,
            'personOptions' => $personOptions,
            'existing' => $album->location === 'inbox' ? app(InboxMatcher::class)->match($album) : null,
            'perTrackGenres' => $this->perTrackGenres(),
            'looseFolder' => $this->isLooseFolder(),
            'noAlbum' => $settings->get('no_album'),
            'discCandidates' => $this->addDiscOpen ? $this->discCandidates($album) : [],
            'discMap' => $album->location === 'inbox' ? $this->currentDiscMap($album) : [],
            'manualDirs' => collect(DiscSet::all())->flatMap(fn ($s) => array_column($s->members, 'dir'))->all(),
            'albumArtistFix' => $albumArtistFix !== $this->fields['albumartist'] ? $albumArtistFix : null,
            'albumArtistNew' => $albumArtistNew,
            'defaultGenre' => $defaultGenre,
            'genreSuggestion' => $genreSuggestion,
            'unlistedGenres' => array_values(array_filter($trackGenres, fn ($g) => ! $genres->isListed($g))),
            'compilationArtist' => $compilationArtist,
            'vaWarning' => $category && ! $category['compilation'] && strcasecmp(trim($this->fields['albumartist']), $compilationArtist) === 0,
            'base' => $settings->base(),
            'operation' => $op,
        ])->title($album->title);
    }
}
