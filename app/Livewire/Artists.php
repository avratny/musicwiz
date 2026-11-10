<?php

namespace App\Livewire;

use App\Jobs\CheckCombis;
use App\Jobs\ImportMusicBrainzAliases;
use App\Jobs\PrefetchUnknownArtists;
use App\Jobs\ResearchArtistGroup;
use App\Livewire\Concerns\NewArtistWizard;
use App\Models\Album;
use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Models\ArtistSplit;
use App\Models\RejectedAlias;
use App\Models\Track;
use App\Support\ArtistActions;
use App\Support\ArtistDirectory;
use App\Support\ArtistGenreSuggestions;
use App\Support\ArtistGroupAnalyzer;
use App\Support\ArtistImages;
use App\Support\ArtistNames;
use App\Support\ArtistQuickMatch;
use App\Support\CombiCheck;
use App\Support\Covers;
use App\Support\FileOps;
use App\Support\Genres;
use App\Support\Homonyms;
use App\Support\Lookup;
use App\Support\OperationRunner;
use App\Support\PendingChanges;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app'), Title('Künstler')]
class Artists extends Component
{
    use NewArtistWizard;
    use WithFileUploads;

    #[Url]
    public string $tab = 'directory';

    #[Url]
    public string $q = '';

    #[Url]
    public ?int $selected = null;

    /** Schnell erfassen: angezeigte Art (sure, name, unsure, pending) */
    #[Url(as: 'art')]
    public string $quickKind = 'sure';

    /** Schnell erfassen: Häkchen je Name (md5 => bool) */
    public array $quickTake = [];

    /** Schnell erfassen: MusicBrainz-Schreibweise übernehmen statt der aus dem Bestand (md5 => bool) */
    public array $quickMb = [];

    public int $quickLimit = 100;

    /** Schnell erfassen: gewähltes Bild je Name (md5 => URL) */
    public array $quickImage = [];

    /** Bild wählen bzw. (nochmal geklickt) wieder abwählen. */
    public function quickPickImage(string $key, string $url): void
    {
        $this->quickImage[$key] = ($this->quickImage[$key] ?? null) === $url ? null : $url;
        $this->quickTake[$key] = true;
    }

    /** Schnell erfassen: angehakte Namen gesammelt anlegen. */
    public function quickSave(ArtistQuickMatch $quick, Lookup $lookup, ArtistGenreSuggestions $genres): void
    {
        $picks = [];
        foreach ($quick->rows() as $r) {
            if (in_array($r['kind'], ['sure', 'name'], true) && $r['hit'] && ($this->quickTake[$r['key']] ?? false)) {
                $picks[] = ['name' => $r['name'], 'hit' => $r['hit'], 'mb_spelling' => $r['hit']['name'] !== $r['name'] && ($this->quickMb[$r['key']] ?? false),
                    'image' => $this->quickImage[$r['key']] ?? null];
            }
        }
        if (! $picks) {
            return;
        }
        $result = $quick->save($picks, $lookup, $genres);
        $images = count(array_filter(array_column($picks, 'image')));
        $this->quickTake = $this->quickMb = $this->quickImage = [];
        session()->flash('status', count($picks).' Künstler übernommen ('.$result['created'].' neu angelegt) – mit MusicBrainz-ID, Standard-Genre und Schreibweisen.'
            .($images ? ' '.$images.' Bild(er) werden im Hintergrund geladen.' : '').PendingChanges::note($result['staged']));
    }

    /** Schnell erfassen: Kombi in die vorgeschlagenen Teile trennen („Gerhard Polt, Gisela Schneeberger“). */
    public function quickSplit(string $name, ArtistQuickMatch $quick): void
    {
        // Teile aus der Kombi-Prüfung (genaue Schreibweise der Dienste), sonst aus dem Namen
        $combi = app(CombiCheck::class)->cached($name);
        $parts = ($combi['verdict'] ?? null) === 'collab' ? $combi['parts'] : ArtistNames::suggestSplit($name);
        if (count($parts) < 2 || Artist::where('name', $name)->exists()) {
            return;
        }
        $staged = $quick->split($name, $parts);
        session()->flash('status', '„'.$name.'“ wird künftig in '.implode(', ', array_map(fn ($p) => '„'.$p.'“', $parts)).' getrennt – die einzelnen Namen werden nachgeschlagen und erscheinen hier.'.PendingChanges::note($staged));
    }

    /** Im Dialog erfasst: Liste neu aufbauen (der Name ist nicht mehr offen). */
    #[On('artist-directory-changed')]
    public function directoryChangedInModal(): void
    {
        app(ArtistDirectory::class)->flush();
        $this->splitNew = array_values(array_filter($this->splitNew, fn ($p) => app(ArtistDirectory::class)->check($p)['status'] === 'new'));
    }

    public function updatedQuickKind(): void
    {
        $this->quickLimit = 100;
    }

    /** Verzeichnis: so viele Einträge, dann „Mehr anzeigen“ */
    public int $artistLimit = 500;

    public function moreArtists(): void
    {
        $this->artistLimit += 500;
    }

    /** Alle unbekannten Künstler im Hintergrund nachschlagen (läuft sonst nach jedem Scan von selbst) */
    public function startPrefetch(): void
    {
        session()->flash('status', PrefetchUnknownArtists::start()
            ? 'Vorladen gestartet – läuft im Hintergrund, etwa eine Abfrage pro Sekunde (Grenze von MusicBrainz).'
            : 'Nichts vorzuladen – alle unbekannten Künstler sind schon nachgeschlagen (bzw. es läuft bereits).');
    }

    public function updatedQ(): void
    {
        $this->artistLimit = 500;
    }

    public array $form = [];

    public string $newAlias = '';

    /** „Ist derselbe wie …“: Auswahl offen, Suchtext, angehakte Einträge */
    public bool $mergeOpen = false;

    public string $mergeQ = '';

    public array $mergeIds = [];

    public array $mbResults = [];

    public array $discogsResults = [];

    public array $imageCandidates = [];

    public ?string $lookupError = null;

    /** Verzeichnis: Genre-Vorschläge aus Bestand, MusicBrainz, Discogs */
    public array $genreProposals = [];

    public $imageUpload;

    public string $imageUrl = '';

    /** Vorschläge: gewählte richtige Schreibweise je Gruppe */
    public array $choice = [];

    public string $newName = '';

    /** Vorschläge: Namen, die nicht zur Gruppe gehören (von Hand) */
    public array $exclude = [];

    /** Vorschläge: von der Analyse ausgeschlossene Namen, die doch dazugehören */
    public array $include = [];

    /** Gleichnamige Künstler: Aufteilen */
    public bool $splitOpen = false;

    /** [{suffix, mbid, disambiguation, label, include}] */
    public array $splitPersons = [];

    public int $splitExisting = 0;

    public ?string $splitError = null;

    /** Gleichnamige Künstler: Zuordnung album_id => voller Name */
    public array $assign = [];

    /** album_id => [Name|null, Begründung] */
    public array $mbSuggest = [];

    public bool $assignMove = true;

    public ?array $assignPreview = null;

    /** Aufgeklappte Varianten zum Reinhören: "gruppe|name" */
    public array $expanded = [];

    #[Url]
    public string $sort = 'count';

    #[Url]
    public string $filter = '';

    public function select(int $id): void
    {
        $this->selected = $id;
        $this->splitInto = null;
        $this->splitOpen = $this->mergeOpen = false;
        $this->mergeQ = '';
        $this->mergeIds = [];
        $this->splitPersons = $this->assign = $this->mbSuggest = [];
        $this->assignPreview = $this->splitError = null;
        $this->resetLookup();
        $this->fillForm();
    }

    /** Beim Verlassen des Assistenten den Namen aus der Adresse nehmen. */
    public function updatedTab(): void
    {
        if ($this->tab !== 'unknown') {
            $this->wizName = null;
        }
    }

    /**
     * Ein paar Lieder zum Reinhören: aus dem Namen und seinen Schreibweisen, verschiedene Alben zuerst.
     *
     * @return list<array{id:int, album_id:?int, seconds:float, title:string, artist:string, album:string}>
     */
    private function sampleTracks(Artist $artist, int $limit = 8): array
    {
        $profiles = app(ArtistGroupAnalyzer::class)->profiles();
        $tracks = [];
        foreach ([$artist->name, ...$artist->aliases->pluck('alias')->all()] as $name) {
            array_push($tracks, ...($profiles[$name]['tracks'] ?? []));
        }
        // verschiedene Alben bevorzugen, dann auffüllen
        $byAlbum = collect($tracks)->unique('id')->groupBy(fn ($t) => $t['album_id'] ?? 0);
        $out = [];
        for ($round = 0; count($out) < $limit && $byAlbum->contains(fn ($g) => isset($g[$round])); $round++) {
            foreach ($byAlbum as $group) {
                if (isset($group[$round]) && count($out) < $limit) {
                    $out[] = $group[$round];
                }
            }
        }

        return $out;
    }

    public function mount(): void
    {
        if ($this->selected) {
            $this->fillForm();
        }
    }

    private function fillForm(): void
    {
        $a = Artist::find($this->selected);
        $this->form = $a ? $a->only(['name', 'mbid', 'discogs_id', 'default_genre']) : [];
    }

    private function resetLookup(): void
    {
        $this->mbResults = $this->discogsResults = $this->imageCandidates = $this->genreProposals = [];
        $this->lookupError = null;
    }

    public function create(): void
    {
        $name = trim($this->newName);
        if ($name === '') {
            return;
        }
        $a = Artist::firstOrCreate(['name' => $name]);
        $this->newName = '';
        $this->select($a->id);
    }

    public function saveArtist(Settings $settings, FileOps $files): void
    {
        $a = Artist::findOrFail($this->selected);
        $name = trim($this->form['name'] ?? '');
        if ($name === '') {
            return;
        }
        if ($name !== $a->name && Artist::where('name', $name)->exists()) {
            $this->addError('form.name', 'Diesen Künstler gibt es schon – als Variante zusammenführen über „Vorschläge“.');

            return;
        }
        // Namensänderung: Bild mit umbenennen, alter Name wird Variante
        $staged = 0;
        if ($name !== $a->name) {
            try {
                ['artist' => $a, 'staged' => $staged] = app(ArtistActions::class)->rename($a, $name);
            } catch (\Throwable $e) {
                $this->lookupError = 'Nicht umbenannt: '.$e->getMessage();

                return;
            }
        }
        $a->fill([
            'mbid' => trim($this->form['mbid'] ?? '') ?: null,
            'discogs_id' => trim($this->form['discogs_id'] ?? '') ?: null,
            'default_genre' => ($this->form['default_genre'] ?? '') ?: null,
        ]);
        $a->save();
        session()->flash('status', 'Gespeichert.'.PendingChanges::note($staged));
    }

    /** Standard-Genre sofort speichern (ohne „Speichern“). */
    public function updatedFormDefaultGenre($value): void
    {
        if ($this->selected) {
            Artist::whereKey($this->selected)->update(['default_genre' => $value ?: null]);
        }
    }

    /** Standard-Genre online ermitteln (mit der gespeicherten MusicBrainz- bzw. Discogs-ID). */
    public function lookupGenres(ArtistGenreSuggestions $suggestions): void
    {
        $artist = Artist::find($this->selected);
        if (! $artist) {
            return;
        }
        $r = $suggestions->for($artist->name, $artist->mbid, $artist->discogs_id, $artist);
        $this->genreProposals = $r['proposals'];
        $this->lookupError = $r['errors'] ? implode(' · ', $r['errors'])
            : (! $artist->mbid && ! $artist->discogs_id ? 'Für MusicBrainz/Discogs zuerst die ID nachschlagen.' : null);
    }

    public function useSuggestedGenre(Genres $genres): void
    {
        $artist = Artist::find($this->selected);
        $s = $artist ? $genres->suggestForArtist($artist) : null;
        if ($s) {
            $this->form['default_genre'] = $s['genre'];
            $artist->update(['default_genre' => $s['genre']]);
        }
    }

    /** Für alle Künstler ohne Standard-Genre den Vorschlag aus dem Bestand übernehmen. */
    public function fillDefaultGenres(Genres $genres): void
    {
        $n = 0;
        foreach (Artist::whereNull('default_genre')->get() as $artist) {
            if ($s = $genres->suggestForArtist($artist)) {
                $artist->update(['default_genre' => $s['genre']]);
                $n++;
            }
        }
        if ($this->selected) {
            $this->fillForm();
        }
        session()->flash('status', $n ? $n.' Künstler haben jetzt ein Standard-Genre aus dem Bestand.' : 'Kein Künstler ohne Standard-Genre mit passenden Liedern.');
    }

    /** „Ist derselbe wie …“: angehakte Einträge gehen im gewählten Künstler auf (er bleibt der richtige Name). */
    public function merge(ArtistDirectory $directory): void
    {
        $target = Artist::find($this->selected);
        $ids = array_map('intval', array_filter($this->mergeIds));
        if (! $target || ! $ids) {
            return;
        }
        $result = app(ArtistActions::class)->merge($target, $ids);
        $staged = $result['staged'];
        $msg = $result['merged']
            ? '„'.implode('“, „', $result['merged']).'“ '.(count($result['merged']) === 1 ? 'ist' : 'sind').' jetzt Schreibweise von „'.$target->name.'“.'.PendingChanges::note($staged)
            : 'Nichts zusammengeführt.';
        if ($result['skipped']) {
            $msg .= ' Ausgelassen (gleichnamige Person, bewusst getrennt): „'.implode('“, „', $result['skipped']).'“.';
        }
        session()->flash('status', $msg);
        $this->mergeOpen = false;
        $this->mergeQ = '';
        $this->mergeIds = [];
        $this->fillForm();
    }

    /**
     * Einträge zur Auswahl: mit Suchtext danach gefiltert (Name oder Schreibweise), sonst ähnliche Namen zuerst.
     *
     * @return Collection<int, Artist>
     */
    private function mergeCandidates(Artist $target)
    {
        $query = Artist::where('id', '!=', $target->id)->whereNull('base_name')->withCount('aliases');
        $q = trim($this->mergeQ);
        if ($q !== '') {
            return $query->where(fn ($w) => $w->where('name', 'like', '%'.$q.'%')->orWhereHas('aliases', fn ($a) => $a->where('alias', 'like', '%'.$q.'%')))
                ->orderByRaw('name COLLATE NOCASE')->limit(40)->get();
        }
        $key = (string) $target->compare_key;

        return $query->get()->filter(fn (Artist $a) => $a->compare_key === $key
            || (strlen($key) >= 4 && abs(strlen($a->compare_key) - strlen($key)) <= 3 && levenshtein($key, (string) $a->compare_key) <= (strlen($key) > 10 ? 3 : 2))
            || (strlen($key) >= 4 && (str_contains((string) $a->compare_key, $key) || str_contains($key, (string) $a->compare_key))))
            ->sortBy(fn (Artist $a) => levenshtein($key, (string) $a->compare_key))->take(20)->values();
    }

    /** Gemerkte Trennung („Yanou & Liz“ → Yanou, Liz) aufheben. */
    public function deleteSplit(int $id): void
    {
        if ($split = ArtistSplit::find($id)) {
            app(ArtistActions::class)->removeSplit($split);
        }
    }

    /** Verzeichnis: nur Einträge zeigen, die laut Kombi-Prüfung eine Zusammenarbeit sind */
    #[Url(as: 'zusammenarbeit')]
    public bool $onlyCollab = false;

    /** Kombi-Prüfung für alle Kombi-Einträge im Hintergrund starten */
    public function startCombiCheck(bool $fresh = false): void
    {
        session()->flash('status', CheckCombis::start($fresh)
            ? 'Kombi-Prüfung gestartet – läuft im Hintergrund (Deezer, Spotify, MusicBrainz, etwa ein Eintrag alle paar Sekunden).'
            : 'Nichts zu prüfen – alle Kombi-Einträge sind schon geprüft (bzw. es läuft bereits).');
    }

    /** Kombi-Prüfung für den gewählten Eintrag jetzt (fragt die Dienste, dauert ein paar Sekunden). */
    public function checkCombiNow(CombiCheck $check): void
    {
        $a = Artist::find($this->selected);
        if ($a && CombiCheck::applies($a->name)) {
            $check->check($a->name, true);
        }
    }

    /** „In Einzelkünstler trennen“: Teile (vorbelegt aus dem Namen), Fehlermeldung */
    public ?array $splitInto = null;

    /** Nach dem Trennen: Einzelnamen, die noch nicht im Verzeichnis stehen (zum Erfassen anbieten) */
    public array $splitNew = [];

    public function openSplitInto(): void
    {
        $a = Artist::find($this->selected);
        if (! $a) {
            return;
        }
        // Mitglieder laut MusicBrainz: oft die Namen, unter denen die Einzelkünstler im Bestand stehen
        $members = $a->mbid ? rescue(fn () => app(Lookup::class)->musicbrainzMembers($a->mbid), [], report: false) : [];
        // Teile: aus der Kombi-Prüfung (Schreibweise der Dienste), sonst aus dem Namen
        $combi = app(CombiCheck::class)->cached($a->name);
        $parts = ($combi['verdict'] ?? null) === 'collab' ? $combi['parts'] : (ArtistNames::suggestSplit($a->name) ?: [$a->name, '']);
        $this->splitInto = ['parts' => $parts, 'members' => $members, 'error' => null];
    }

    /**
     * Hinweise je Teil: im Verzeichnis, im Bestand oder neu – und für neue Namen ähnliche vorhandene („Stone“ → „CJ Stone“),
     * zuerst die Mitglieder laut MusicBrainz.
     *
     * @return array<int, array{status: string, label: string, suggestions: list<array{name: string, count: int, source: string}>}>
     */
    private function splitIntoHints(ArtistDirectory $directory): array
    {
        $counts = $directory->namesInCollection();
        $known = array_merge(array_fill_keys(Artist::pluck('name')->all(), 0), $counts);
        $out = [];
        foreach ($this->splitInto['parts'] ?? [] as $i => $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            $check = $directory->check($part);
            if (in_array($check['status'], ['ok', 'variant'], true)) {
                $out[$i] = ['status' => 'known', 'label' => 'im Verzeichnis'.($check['canonical'] !== $part ? ' als „'.$check['canonical'].'“' : ''), 'suggestions' => []];

                continue;
            }
            $n = $counts[$part] ?? 0;
            $key = ArtistNames::compareKey($part);
            $suggestions = [];
            foreach ((array) ($this->splitInto['members'] ?? []) as $m) {
                $mk = ArtistNames::compareKey($m);
                if ($m !== $part && $mk !== '' && $key !== '' && (str_contains($mk, $key) || str_contains($key, $mk))) {
                    $suggestions[$m] = ['name' => $m, 'count' => (int) ($counts[$m] ?? 0), 'source' => 'MusicBrainz'];
                }
            }
            // vorhandene Namen, in denen der Teil als ganzes Wort steckt („CJ Stone“, „Marc van Linden“)
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($part, '/').'(?![\p{L}\p{N}])/iu';
            $local = [];
            foreach ($known as $name => $c) {
                $name = (string) $name;
                if ($name !== $part && ! isset($suggestions[$name]) && ! ArtistNames::looksCollaboration($name) && preg_match($pattern, $name)) {
                    $local[$name] = ['name' => $name, 'count' => (int) $c, 'source' => $directory->check($name)['status'] === 'ok' ? 'Verzeichnis' : 'Bestand'];
                }
            }
            uasort($local, fn ($a, $b) => $b['count'] <=> $a['count']);
            $out[$i] = ['status' => $n ? 'collection' : 'new', 'label' => $n ? 'im Bestand ('.$n.' Titel), nicht im Verzeichnis' : 'neu – kommt im Bestand bisher nicht vor',
                'suggestions' => array_slice(array_values([...$suggestions, ...$local]), 0, 4)];
        }

        return $out;
    }

    public function useSplitIntoSuggestion(int $i, string $name): void
    {
        $this->splitInto['parts'][$i] = $name;
    }

    public function addSplitIntoPart(): void
    {
        $this->splitInto['parts'][] = '';
    }

    public function removeSplitIntoPart(int $i): void
    {
        unset($this->splitInto['parts'][$i]);
        $this->splitInto['parts'] = array_values($this->splitInto['parts']);
    }

    /**
     * Eintrag ist eine Zusammenarbeit („Beam & Yanou“): aus dem Verzeichnis nehmen, Trennung merken (auch für seine
     * Kombi-Schreibweisen wie „Beam and Yanou“), Tags der Titel vormerken und die Einzelnamen zum Erfassen anbieten.
     */
    public function saveSplitInto(ArtistActions $actions): void
    {
        $a = Artist::find($this->selected);
        if (! $a || ! $this->splitInto) {
            return;
        }
        try {
            $r = $actions->splitInto($a, $this->splitInto['parts']);
        } catch (\InvalidArgumentException $e) {
            $this->splitInto['error'] = $e->getMessage();

            return;
        }
        $this->splitNew = $r['new'];
        $this->splitInto = null;
        $this->selected = null;
        $this->form = [];
        session()->flash('status', '„'.$a->name.'“ wird künftig in '.implode(', ', array_map(fn ($p) => '„'.$p.'“', $r['parts'])).' getrennt und steht nicht mehr im Verzeichnis.'.PendingChanges::note($r['staged']));
    }

    public function deleteArtist(): void
    {
        Artist::whereKey($this->selected)->delete();
        $this->selected = null;
        $this->form = [];
    }

    public function addAlias(): void
    {
        $alias = trim($this->newAlias);
        $a = Artist::find($this->selected);
        if (! $a || $alias === '' || $alias === $a->name) {
            return;
        }
        try {
            $staged = app(ArtistActions::class)->addAlias($a, $alias)['staged'];
        } catch (\InvalidArgumentException $e) {
            $this->addError('newAlias', $e->getMessage());

            return;
        }
        $this->resetErrorBag('newAlias');
        $this->newAlias = '';
        if ($staged) {
            session()->flash('status', '„'.$alias.'“ ist jetzt eine Schreibweise von „'.$a->name.'“.'.PendingChanges::note($staged));
        }
    }

    /** Schreibweise löschen und merken – sonst trägt das nächste Holen von MusicBrainz sie wieder ein. */
    public function removeAlias(int $id): void
    {
        $alias = ArtistAlias::find($id);
        if ($alias) {
            RejectedAlias::remember($alias->artist_id, $alias->alias);
            $alias->delete();
        }
    }

    /** Abgelehnte Schreibweise wieder zulassen (beim nächsten Holen darf MusicBrainz sie eintragen). */
    public function allowAlias(int $id): void
    {
        RejectedAlias::whereKey($id)->delete();
    }

    // ---------------------------------------------------------------- Nachschlagen

    public function lookupMusicBrainz(Lookup $lookup): void
    {
        $this->resetLookup();
        try {
            $this->mbResults = $lookup->musicbrainzArtists($this->form['name'] ?? '');
            if (! $this->mbResults && $lookup->hasDiscogs()) {
                $this->discogsResults = $lookup->discogsArtists($this->form['name'] ?? '');
            }
            if (! $this->mbResults && ! $this->discogsResults) {
                $this->lookupError = 'Kein Treffer.';
            }
        } catch (\Throwable $e) {
            $this->lookupError = $e->getMessage();
        }
    }

    public function lookupDiscogs(Lookup $lookup): void
    {
        $this->resetLookup();
        try {
            $this->discogsResults = $lookup->discogsArtists($this->form['name'] ?? '');
            $this->lookupError = $this->discogsResults ? null : 'Kein Treffer.';
        } catch (\Throwable $e) {
            $this->lookupError = $e->getMessage();
        }
    }

    /** Treffer übernehmen: ID sofort speichern; abweichender Name nur ins Feld (Umbenennen braucht „Speichern“). */
    public function useMusicBrainz(int $i): void
    {
        $hit = $this->mbResults[$i] ?? null;
        $this->mbResults = [];
        if ($hit) {
            $this->storeId('mbid', $hit['mbid'], 'MusicBrainz', $hit['name']);
        }
    }

    private function storeId(string $field, string $id, string $source, string $name): void
    {
        $artist = Artist::find($this->selected);
        if (! $artist) {
            return;
        }
        $artist->update([$field => $id]);
        $this->form[$field] = $id;
        $msg = $source.'-ID gespeichert.';
        if ($field === 'mbid') {
            $added = $this->importAliases($artist);
            $msg .= $added ? ' '.count($added).' Schreibweise(n) von MusicBrainz übernommen.' : '';
        }
        $clean = ArtistNames::stripDiscogsSuffix($name);
        if ($clean !== '' && $clean !== $artist->name && ! $artist->base_name) {
            $this->form['name'] = $clean;
            $msg .= ' '.$source.' schreibt „'.$clean.'“ – ins Namensfeld übernommen, mit „Speichern“ bestätigen (benennt auch das Bild um).';
        }
        session()->flash('status', $msg);
    }

    /** IDs von Hand: beim Verlassen des Feldes speichern. */
    public function updatedFormMbid($value): void
    {
        Artist::whereKey($this->selected)->update(['mbid' => trim((string) $value) ?: null]);
        if (trim((string) $value) !== '' && ($artist = Artist::find($this->selected))) {
            $this->importAliases($artist);
        }
    }

    /** Schreibweisen von MusicBrainz für den gewählten Künstler holen (Knopf im Verzeichnis). */
    public function loadMusicBrainzAliases(): void
    {
        $artist = Artist::find($this->selected);
        if (! $artist?->mbid) {
            return;
        }
        $added = $this->importAliases($artist);
        if ($this->lookupError === null) {
            session()->flash('status', $added ? 'Von MusicBrainz übernommen: „'.implode('“, „', $added).'“.' : 'Keine neuen Schreibweisen bei MusicBrainz.');
        }
    }

    /** Für alle Künstler mit MusicBrainz-ID im Hintergrund (ca. 1 Abfrage pro Sekunde). */
    public function loadAllMusicBrainzAliases(): void
    {
        if (Cache::get(ImportMusicBrainzAliases::STATUS_KEY)['running'] ?? false) {
            return;
        }
        Cache::forever(ImportMusicBrainzAliases::STATUS_KEY, ['running' => true, 'done' => 0, 'total' => Artist::whereNotNull('mbid')->count(), 'added' => 0]);
        ImportMusicBrainzAliases::dispatch();
    }

    /** @return list<string> neu gespeicherte Schreibweisen */
    private function importAliases(Artist $artist): array
    {
        try {
            return app(ArtistDirectory::class)->importMusicBrainzAliases($artist, app(Lookup::class)->musicbrainzArtistAliases($artist->mbid));
        } catch (\Throwable $e) {
            $this->lookupError = 'MusicBrainz-Schreibweisen: '.$e->getMessage();

            return [];
        }
    }

    public function updatedFormDiscogsId($value): void
    {
        Artist::whereKey($this->selected)->update(['discogs_id' => trim((string) $value) ?: null]);
    }

    public function useDiscogs(int $i): void
    {
        $hit = $this->discogsResults[$i] ?? null;
        $this->discogsResults = [];
        if ($hit) {
            $this->storeId('discogs_id', $hit['discogs_id'], 'Discogs', $hit['name']);
        }
    }

    public function findImages(Lookup $lookup): void
    {
        $this->resetLookup();
        $errors = [];
        $name = $this->form['name'] ?? '';
        $sources = [
            fn () => ! empty($this->form['mbid']) ? ($lookup->audiodbArtist($this->form['mbid'], $name)['images'] ?? []) : [],
            fn () => $lookup->hasSpotify() ? ($lookup->spotifyArtist($name, array_values(app(ArtistGroupAnalyzer::class)->profiles()[$name]['titles'] ?? []))['images'] ?? []) : [],
            fn () => $lookup->deezerArtistImages($name),
            fn () => ! empty($this->form['mbid']) && trim((string) app(Settings::class)->get('fanart_key')) !== '' ? $lookup->fanartArtistImages($this->form['mbid']) : [],
            fn () => ! empty($this->form['discogs_id']) && $lookup->hasDiscogs() ? $lookup->discogsArtistImages($this->form['discogs_id']) : [],
        ];
        foreach ($sources as $src) {
            try {
                $this->imageCandidates = array_merge($this->imageCandidates, $src());
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        if (! $this->imageCandidates) {
            $this->lookupError = $errors ? implode(' · ', $errors) : 'Keine Bilder gefunden.';
        }
    }

    public function useImage(int $i, Covers $covers): void
    {
        $c = $this->imageCandidates[$i] ?? null;
        if (! $c) {
            return;
        }
        try {
            $tmp = $covers->download($c['url']);
            $this->storeImage($tmp);
            $this->imageCandidates = [];
        } catch (\Throwable $e) {
            $this->lookupError = $e->getMessage();
        }
    }

    public function loadImageUrl(Covers $covers): void
    {
        $this->resetErrorBag('imageUrl');
        try {
            $this->storeImage($covers->download($this->imageUrl, 'tmp'));
            $this->imageUrl = '';
            $this->imageCandidates = [];
        } catch (\Throwable $e) {
            $this->addError('imageUrl', $e->getMessage());
        }
    }

    /** Bild entfernen: Datei wandert in den Papierkorb. */
    public function removeImage(Settings $settings, FileOps $files): void
    {
        $a = Artist::findOrFail($this->selected);
        if ($a->image) {
            $path = rtrim($settings->get('artist_images'), '/').'/'.$a->image;
            if (is_file($path)) {
                $files->trash($path);
            }
            $a->update(['image' => null]);
        }
    }

    public function updatedImageUpload(Covers $covers): void
    {
        $this->validate(['imageUpload' => 'image|max:20480']);
        $this->storeImage($covers->normalizeUpload($this->imageUpload->getRealPath(), 'tmp', 1200));
        $this->imageUpload = null;
    }

    private function storeImage(string $tmp): void
    {
        ArtistImages::store(Artist::findOrFail($this->selected), $tmp);
    }

    public static function imageFile(string $name, string $ext): string
    {
        return ArtistImages::fileName($name, $ext);
    }

    // ---------------------------------------------------------------- Vorschläge

    public function resolveGroup(string $key, ArtistDirectory $directory, ArtistGroupAnalyzer $analyzer): void
    {
        $group = collect($directory->candidateGroups())->firstWhere('key', $key);
        if (! $group) {
            return;
        }
        $analysis = $analyzer->analyze($group);
        $excluded = $this->excludedNames($key, $analysis);
        $names = array_values(array_diff(array_keys($group['names']), $excluded));
        $canonical = trim($this->choice[$key] ?? '') ?: $analysis['recommendation'];
        $artist = $directory->resolve($canonical, $names);
        // "gehört nicht dazu" dauerhaft merken: als eigene Künstler eintragen, sonst käme die Gruppe wieder
        $separate = $directory->keepSeparate(array_values(array_diff($excluded, [$canonical])));
        // MusicBrainz-ID aus der Recherche übernehmen, wenn sie eindeutig zur gewählten Schreibweise passt
        foreach ($analysis['research']['artists'] ?? [] as $hit) {
            if ($hit && ! $artist->mbid && ArtistNames::compareKey($hit['name']) === ArtistNames::compareKey($canonical)) {
                $artist->update(['mbid' => $hit['mbid']]);
                break;
            }
        }
        unset($this->choice[$key], $this->exclude[$key], $this->include[$key]);
        $staged = app(PendingChanges::class)->stageForNames([...$names, $canonical], 'Schreibweisen-Vorschlag');
        session()->flash('status', '„'.$canonical.'“ gespeichert.'
            .($separate ? ' Als eigene Künstler eingetragen: „'.implode('“, „', $separate).'“.' : '')
            .PendingChanges::note($staged));
    }

    /** Alle Namen der Gruppe sind verschiedene Künstler: jeweils eigener Eintrag, keine Aliase. */
    public function separateGroup(string $key, ArtistDirectory $directory): void
    {
        $group = collect($directory->candidateGroups())->firstWhere('key', $key);
        if (! $group) {
            return;
        }
        $added = $directory->keepSeparate(array_keys($group['names']));
        unset($this->choice[$key], $this->exclude[$key], $this->include[$key]);
        session()->flash('status', 'Als verschiedene Künstler gespeichert: „'.implode('“, „', array_keys($group['names'])).'“'
            .($added ? ' (neu im Verzeichnis: „'.implode('“, „', $added).'“)' : '').'.');
    }

    /** Ausgeschlossen = (von der Analyse unwahrscheinlich − wieder aufgenommen) ∪ von Hand ausgeschlossen. */
    private function excludedNames(string $key, array $analysis): array
    {
        $auto = array_keys(array_filter($analysis['variants'], fn ($v) => $v['probability'] !== null && $v['probability'] < 0.4));

        return array_values(array_unique(array_merge(array_diff($auto, $this->include[$key] ?? []), $this->exclude[$key] ?? [])));
    }

    public function toggleExclude(string $key, string $name, bool $currentlyExcluded): void
    {
        $toggle = fn (array $list) => in_array($name, $list, true) ? array_values(array_diff($list, [$name])) : [...$list, $name];
        if ($currentlyExcluded) {
            in_array($name, $this->exclude[$key] ?? [], true)
                ? $this->exclude[$key] = $toggle($this->exclude[$key])
                : $this->include[$key] = $toggle($this->include[$key] ?? []);
        } else {
            in_array($name, $this->include[$key] ?? [], true)
                ? $this->include[$key] = $toggle($this->include[$key])
                : $this->exclude[$key] = $toggle($this->exclude[$key] ?? []);
        }
    }

    // ---------------------------------------------------------------- Gleichnamige Künstler

    public function openSplit(): void
    {
        $artist = Artist::findOrFail($this->selected);
        $this->splitOpen = true;
        $this->splitError = null;
        if (! $this->splitPersons) {
            $this->splitPersons = $artist->base_name ? [] : [
                ['suffix' => '', 'mbid' => $artist->mbid, 'disambiguation' => null, 'label' => 'bisheriger Eintrag', 'include' => true],
                ['suffix' => '', 'mbid' => null, 'disambiguation' => null, 'label' => 'weitere Person', 'include' => true],
            ];
        }
    }

    public function loadSplitCandidates(Homonyms $homonyms): void
    {
        $artist = Artist::findOrFail($this->selected);
        $this->splitError = null;
        try {
            $existing = Artist::where('base_name', $artist->baseName())->pluck('mbid')->filter()->all();
            $found = array_values(array_filter($homonyms->candidates($artist->baseName()), fn ($c) => ! in_array($c['mbid'], $existing, true)));
            if (! $found) {
                $this->splitError = 'MusicBrainz kennt keine (weiteren) Künstler mit diesem Namen – Personen bitte von Hand anlegen.';

                return;
            }
            $this->splitPersons = array_map(fn ($c, $i) => [
                'suffix' => $c['suffix'], 'mbid' => $c['mbid'], 'disambiguation' => $c['disambiguation'] ?: null,
                'label' => $c['label'] ?: 'MusicBrainz',
                // vorausgewählt: passende Treffer, sonst die ersten zwei
                'include' => ($c['relevance'] ?? 0) > 0 || ($i < 2 && ! collect($found)->contains(fn ($f) => ($f['relevance'] ?? 0) > 0)),
            ], $found, array_keys($found));
            // bisheriger Eintrag = Treffer mit derselben MusicBrainz-ID, sonst der erste
            $this->splitExisting = (int) (collect($found)->search(fn ($c) => $c['mbid'] === $artist->mbid) ?: 0);
        } catch (\Throwable $e) {
            $this->splitError = $e->getMessage();
        }
    }

    public function addSplitPerson(): void
    {
        $this->splitPersons[] = ['suffix' => '', 'mbid' => null, 'disambiguation' => null, 'label' => 'von Hand', 'include' => true];
    }

    public function doSplit(Homonyms $homonyms): void
    {
        $artist = Artist::findOrFail($this->selected);
        $persons = [];
        foreach ($this->splitPersons as $i => $p) {
            if (! empty($p['include'])) {
                $persons[] = $p + ['existing' => ! $artist->base_name && $i === $this->splitExisting];
            }
        }
        try {
            $homonyms->split($artist, $persons);
            $this->splitOpen = false;
            $this->splitPersons = [];
            session()->flash('status', 'Aufgeteilt. Jetzt unten die Alben den Personen zuordnen.');
        } catch (\Throwable $e) {
            $this->splitError = $e->getMessage();
        }
    }

    public function changeSuffix(int $id, string $suffix, Homonyms $homonyms): void
    {
        try {
            $homonyms->changeSuffix(Artist::findOrFail($id), $suffix);
        } catch (\Throwable $e) {
            $this->splitError = $e->getMessage();
        }
    }

    public function checkMusicBrainz(int $albumId, Homonyms $homonyms): void
    {
        $artist = Artist::findOrFail($this->selected);
        try {
            $this->mbSuggest[$albumId] = $homonyms->suggestByMusicBrainz($artist->baseName(), Album::findOrFail($albumId));
            if ($this->mbSuggest[$albumId][0] && empty($this->assign[$albumId])) {
                $this->assign[$albumId] = $this->mbSuggest[$albumId][0];
            }
        } catch (\Throwable $e) {
            $this->mbSuggest[$albumId] = [null, $e->getMessage()];
        }
    }

    public function previewAssign(Homonyms $homonyms): void
    {
        $artist = Artist::findOrFail($this->selected);
        $plan = $homonyms->plan($artist->baseName(), array_filter($this->assign), $this->assignMove);
        $this->assignPreview = [
            'rows' => array_map(fn ($r) => ['album' => $r['album']->title, 'dir' => $r['album']->dir, 'person' => $r['person'], 'changes' => $r['changes'], 'target' => $r['target'], 'problems' => $r['problems']], $plan['rows']),
            'steps' => count($plan['actions']),
        ];
    }

    public function confirmAssign(Homonyms $homonyms): void
    {
        $artist = Artist::findOrFail($this->selected);
        $plan = $homonyms->plan($artist->baseName(), array_filter($this->assign), $this->assignMove);
        if ($plan['actions']) {
            OperationRunner::dispatch('homonyms', 'Gleichnamige Künstler zuordnen: '.$artist->baseName().' ('.count($plan['rows']).' Alben)', $plan['actions']);
            $this->dispatch('activity-changed');
            session()->flash('status', 'Zuordnung läuft – Fortschritt im Protokoll.');
        }
        $this->assignPreview = null;
        $this->assign = [];
    }

    public function toggleExpand(string $id): void
    {
        $this->expanded = in_array($id, $this->expanded, true) ? array_values(array_diff($this->expanded, [$id])) : [...$this->expanded, $id];
    }

    public function research(string $key, ArtistDirectory $directory): void
    {
        $group = collect($directory->candidateGroups())->firstWhere('key', $key);
        if ($group) {
            Cache::forever(ArtistGroupAnalyzer::researchKey($group), ['running' => true]);
            ResearchArtistGroup::dispatch($group);
        }
    }

    /** Alle angezeigten Gruppen ohne Recherche nacheinander recherchieren (höchstens 30). */
    public function researchAll(ArtistDirectory $directory, ArtistGroupAnalyzer $analyzer): void
    {
        $n = 0;
        foreach ($this->sortedGroups($directory, $analyzer) as [$group, $analysis]) {
            if ($analysis['research'] === null && $n++ < 30) {
                Cache::forever(ArtistGroupAnalyzer::researchKey($group), ['running' => true]);
                ResearchArtistGroup::dispatch($group);
            }
        }
    }

    private function sortedGroups(ArtistDirectory $directory, ArtistGroupAnalyzer $analyzer): array
    {
        $rows = array_map(fn ($g) => [$g, $analyzer->analyze($g)], array_slice($directory->candidateGroups(), 0, 300));
        if ($this->filter === 'unsure') {
            $rows = array_filter($rows, fn ($r) => $r[1]['confidence'] >= 0.4 && $r[1]['confidence'] < 0.8);
        } elseif ($this->filter === 'sure') {
            $rows = array_filter($rows, fn ($r) => $r[1]['confidence'] >= 0.8);
        } elseif ($this->filter === 'different') {
            $rows = array_filter($rows, fn ($r) => $r[1]['confidence'] < 0.4);
        }
        if ($this->sort === 'sure') {
            usort($rows, fn ($a, $b) => $b[1]['confidence'] <=> $a[1]['confidence']);
        } elseif ($this->sort === 'unsure') {
            usort($rows, fn ($a, $b) => abs($a[1]['confidence'] - 0.5) <=> abs($b[1]['confidence'] - 0.5));
        }

        return array_values($rows);
    }

    public function render(ArtistDirectory $directory, Genres $genres)
    {
        $data = ['genreOptions' => $genres->options()];
        if ($this->tab === 'directory') {
            $query = Artist::query()
                ->when($this->q !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$this->q.'%')->orWhereHas('aliases', fn ($a) => $a->where('alias', 'like', '%'.$this->q.'%'))));
            // Kombi-Prüfung: Ergebnisse aller Kombi-Einträge (nur gespeicherte), Filter „wahrscheinlich Zusammenarbeit“
            $combiNames = Artist::pluck('name', 'id')->filter(fn ($n) => CombiCheck::applies($n));
            $combis = array_filter(app(CombiCheck::class)->cachedMany($combiNames->values()->all()));
            $collabIds = $combiNames->filter(fn ($n) => ($combis[$n]['verdict'] ?? null) === 'collab')->keys()->all();
            if ($this->onlyCollab) {
                $query->whereIn('id', $collabIds);
            }
            $data['combis'] = $combis;
            $data['collabCount'] = count($collabIds);
            $data['combiTotal'] = count(CheckCombis::names());
            $data['combiJob'] = CheckCombis::status();
            $data['artistTotal'] = (clone $query)->count();
            $data['artists'] = $query->withCount('aliases')->orderByRaw('name COLLATE NOCASE')->limit($this->artistLimit)->get();
            $data['artist'] = $this->selected ? Artist::with(['aliases', 'rejectedAliases'])->find($this->selected) : null;
            $data['mergeCandidates'] = $data['artist'] && $this->mergeOpen ? $this->mergeCandidates($data['artist']) : collect();
            $data['splits'] = ArtistSplit::orderBy('name')->get();
            $data['genreSuggestion'] = $data['artist'] ? $genres->suggestForArtist($data['artist']) : null;
            $data['artistTracks'] = $data['artist'] ? $this->sampleTracks($data['artist']) : [];
            $data['splitIntoHints'] = $this->splitInto ? $this->splitIntoHints($directory) : [];
            $data['combi'] = $data['artist'] && CombiCheck::applies($data['artist']->name) ? app(CombiCheck::class)->cached($data['artist']->name) : null;
            $data['combiApplies'] = $data['artist'] && CombiCheck::applies($data['artist']->name);
            // TheAudioDB (nur Zwischenspeicher): Biografie, Herkunft, Mitglieder – geholt beim Vorladen bzw. „Bilder suchen“
            $data['audiodb'] = $data['artist']?->mbid ? app(Lookup::class)->audiodbArtistCached($data['artist']->mbid, $data['artist']->name) : null;
            // ohne Lieder im Bestand: Hinweis, ob welche noch in der Inbox liegen (zählen erst nach dem Einordnen)
            $data['inboxTracks'] = $data['artist'] && ! $data['artistTracks']
                ? Track::where('location', 'inbox')->where(fn ($q) => $q->where('artist', 'like', '%'.$data['artist']->name.'%')
                    ->orWhere('albumartist', 'like', '%'.$data['artist']->name.'%'))->count()
                : 0;
            $data['withoutGenre'] = Artist::whereNull('default_genre')->count();
            $data['aliasJob'] = Cache::get(ImportMusicBrainzAliases::STATUS_KEY);
            $data['withMbid'] = Artist::whereNotNull('mbid')->count();
            $data['homonym'] = null;
            if ($data['artist']?->base_name) {
                $base = $data['artist']->base_name;
                $albums = app(Homonyms::class)->albums($base);
                foreach ($albums as $row) {
                    $this->assign[$row['album']->id] ??= $row['assigned'] ?? $row['suggestion'] ?? '';
                }
                $data['homonym'] = [
                    'base' => $base,
                    'persons' => Artist::where('base_name', $base)->orderBy('name')->get(),
                    'albums' => $albums,
                ];
            }
        } elseif ($this->tab === 'suggestions') {
            $analyzer = app(ArtistGroupAnalyzer::class);
            $data['groups'] = array_map(function ($row) {
                [$g, $a] = $row;
                $g['analysis'] = $a;
                $g['excluded'] = $this->excludedNames($g['key'], $a);

                return $g;
            }, array_slice($this->sortedGroups($directory, $analyzer), 0, 150));
            $data['researching'] = collect($data['groups'])->contains(fn ($g) => $g['analysis']['research']['running'] ?? false);
            // Cover der Alben für die Hörproben
            $albumIds = collect($data['groups'])->flatMap(fn ($g) => collect($g['analysis']['variants'])->flatMap(fn ($v) => array_column($v['tracks'], 'album_id')))->filter()->unique();
            $data['covers'] = Album::whereIn('id', $albumIds)->get()->mapWithKeys(fn ($a) => [$a->id => $a->coverUrl(120)])->all();
        } elseif ($this->tab === 'quick') {
            $all = app(ArtistQuickMatch::class)->rows();
            $data['quickCounts'] = array_count_values(array_column($all, 'kind'));
            $rows = array_values(array_filter($all, fn ($r) => $r['kind'] === $this->quickKind));
            $data['quickTotal'] = count($rows);
            $data['quickRows'] = array_slice($rows, 0, $this->quickLimit);
            $data['quickImages'] = in_array($this->quickKind, ['sure', 'name'], true) ? app(ArtistQuickMatch::class)->images($data['quickRows'], app(Lookup::class)) : [];
            // nichts vorab angehakt (ein Bild wählen hakt an); MusicBrainz-Schreibweise (nur Groß/klein, Satzzeichen, Akzente) an
            foreach ($data['quickRows'] as $r) {
                $this->quickTake[$r['key']] ??= false;
                if ($r['hit'] && $r['hit']['name'] !== $r['name']) {
                    $this->quickMb[$r['key']] ??= true;
                }
            }
            $data['prefetch'] = PrefetchUnknownArtists::status();
        } else {
            $data += $this->wizData($directory, app(ArtistGroupAnalyzer::class));
            $data['prefetch'] = PrefetchUnknownArtists::status();
        }

        return view('livewire.artists', $data);
    }
}
