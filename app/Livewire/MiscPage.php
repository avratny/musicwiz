<?php

namespace App\Livewire;

use App\Jobs\AskClaudeTextRepairs;
use App\Jobs\MeasureFingerprints;
use App\Jobs\MeasureReplayGain;
use App\Jobs\ScanLocation;
use App\Livewire\Concerns\ShowsMessages;
use App\Models\TextRepair;
use App\Models\Track;
use App\Support\Activity;
use App\Support\Duplicates;
use App\Support\Fingerprints;
use App\Support\GenreChecks;
use App\Support\ReplayGain;
use App\Support\TextRepairAi;
use App\Support\TextRepairs;
use App\Support\YearDates;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Sonstiges: Prüfungen, die weder zu Interpreten noch zu Alben oder Genres gehören – links eine Leiste.
 * „Zeichen & Schreibweise“: kaputte Zeichen in den Tags; sicher Reparierbares mit festem Vorschlag, verlorene Zeichen mit
 * Vorschlägen von Claude (bis zu 10, gespeichert) – wählen oder selbst schreiben. Dazu Schreibweisen zum Prüfen (Klammer
 * offen, Punkt am Ende, einseitiger Bindestrich) mit Vorschlag oder „Passt so“.
 * „Jahr als Datum“: im Jahr-Tag steht mehr als die Jahreszahl – nur das Jahr behalten (YearDates).
 * „ReplayGain fehlt“: Lautheit im Hintergrund messen und ReplayGain setzen (ReplayGain, MeasureReplayGain).
 * „Duplikate“: gleiche Lieder – Alben bleiben vollständig, die bessere Datei gewinnt; nur vormerken (Duplicates).
 * „Genre fehlt“, „Genre weicht ab“, „Album uneinheitlich“: Vorschlag, Alternativen oder eigenes Genre; Alben je Album,
 * Sampler und Einzeltitel je Lied (GenreChecks).
 * „Akustischer Fingerabdruck“: je Bereich ermitteln (Lib und Blackhole nur auf Knopfdruck, Inbox auch nach dem
 * Einlesen) – Grundlage für Duplikate über Albumgrenzen (Fingerprints, MeasureFingerprints).
 * „Nicht lesbar“: Dateien, die beim Einlesen nicht gelesen werden konnten – neu lesen bzw. die Einträge aus der Datenbank
 * entfernen (die Dateien bleiben).
 */
#[Layout('layouts.app')]
class MiscPage extends Component
{
    use ShowsMessages;

    /** zweite Leiste */
    public const VIEWS = ['zeichen' => 'Zeichen & Schreibweise', 'jahr' => 'Jahr als Datum', 'replaygain' => 'ReplayGain fehlt', 'duplikate' => 'Duplikate',
        'genre_fehlt' => 'Genre fehlt', 'genre_abweichend' => 'Genre weicht ab', 'genre_uneinheitlich' => 'Album uneinheitlich', 'fingerabdruck' => 'Akustischer Fingerabdruck', 'nicht_lesbar' => 'Nicht lesbar'];

    /** Ansicht => Prüfung in GenreChecks */
    public const GENRE_VIEWS = ['genre_fehlt' => 'missing', 'genre_abweichend' => 'mismatch', 'genre_uneinheitlich' => 'mixed'];

    private const STEP = 50;

    #[Url(as: 'ansicht')]
    public string $view = 'zeichen';

    #[Url(as: 'q', except: '')]
    public string $query = '';

    public int $limit = self::STEP;

    /** eigene Schreibweise je Wert (Hash => Text) */
    public array $custom = [];

    public function updatedQuery(): void
    {
        $this->limit = self::STEP;
    }

    public function more(): void
    {
        $this->limit += self::STEP;
    }

    /** Claude nach Vorschlägen für diesen Wert fragen (auch erneut) */
    public function ask(string $hash, TextRepairs $repairs, TextRepairAi $ai): void
    {
        $problem = $repairs->problems()->firstWhere('hash', $hash);
        if (! $problem) {
            return;
        }
        try {
            $repair = $ai->suggest($problem['value'], $problem['fields'], $repairs->context($problem['value']));
            $n = count($repair->suggestions ?? []);
            $this->say($n ? 'Claude hat '.$n.' '.($n === 1 ? 'Vorschlag' : 'Vorschläge').' gemacht.' : 'Claude hat keinen Vorschlag gefunden.');
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
            $this->message = null;
        }
    }

    /** alle offenen Werte ohne Vorschläge im Hintergrund fragen */
    public function askAll(): void
    {
        AskClaudeTextRepairs::start();
    }

    /** Schreibweise übernehmen (Vorschlag oder eigene) */
    public function take(string $hash, ?string $text, TextRepairs $repairs): void
    {
        $problem = $repairs->problems()->firstWhere('hash', $hash);
        if (! $problem) {
            return;
        }
        $text ??= (string) ($this->custom[$hash] ?? '');
        $this->attempt(function () use ($repairs, $problem, $text) {
            $changed = $repairs->apply($problem['value'], $text);
            $this->message = '„'.trim($text).'“ übernommen – bei '.$changed.' '.($changed === 1 ? 'Lied' : 'Liedern').' (unter Änderungen zurücksetzbar).';
        });
        unset($this->custom[$hash]);
    }

    /** Schreibweise passt so (nur beim Prüfen der Schreibweise) */
    public function accept(string $hash, TextRepairs $repairs): void
    {
        $problem = $repairs->problems()->firstWhere('hash', $hash);
        if ($problem) {
            $repairs->accept($problem['value']);
            $this->say('„'.$problem['value'].'“ passt so – wird nicht mehr angezeigt.');
        }
    }

    /** Jahr als Datum: nur das Jahr übernehmen – ein Album bzw. alle (null) */
    public function fixYears(?string $hash, YearDates $years): void
    {
        $changed = $years->apply($hash);
        $this->say('Nur das Jahr übernommen – bei '.$changed.' '.($changed === 1 ? 'Lied' : 'Liedern').' (unter Änderungen zurücksetzbar).');
    }

    /** ReplayGain messen – ein Album bzw. alle (null), im Hintergrund */
    public function measure(?string $hash): void
    {
        if (! MeasureReplayGain::start($hash === null ? null : [$hash])) {
            $this->error = 'Läuft schon – bitte warten, bis die Messung fertig ist.';
            $this->message = null;

            return;
        }
        $this->dispatch('activity-started');
    }

    #[On('replaygain-finished')]
    public function replayGainFinished(): void {}

    /** Duplikate: Plan vormerken – eine Gruppe bzw. alle offenen (null) */
    public function planDuplicates(?string $key, Duplicates $duplicates): void
    {
        $n = $duplicates->apply($key);
        $this->say($n === 1 ? 'Vorgemerkt – ausgeführt wird beim Schreiben.' : $n.' Gruppen vorgemerkt – ausgeführt wird beim Schreiben.');
    }

    public function revertDuplicates(string $key, Duplicates $duplicates): void
    {
        $duplicates->revert($key);
        $this->say('Vormerkung zurückgenommen.');
    }

    /** Duplikate: „kein Duplikat“ (verschiedene Fassungen) bzw. wieder aufnehmen */
    public function exemptDuplicates(string $key, bool $exempt, Duplicates $duplicates): void
    {
        $duplicates->exempt($key, $exempt);
        $this->say($exempt ? 'Als „kein Duplikat“ ausgenommen.' : 'Wieder aufgenommen.');
    }

    /** Genre übernehmen – Vorschlag ($genre) oder eigenes (aus $custom); für die Einheit bzw. ein Lied darin */
    public function takeGenre(string $key, ?int $trackId, ?string $genre, GenreChecks $genres): void
    {
        $check = self::GENRE_VIEWS[$this->view] ?? null;
        if (! $check) {
            return;
        }
        $field = 'g'.$key.($trackId ? '-'.$trackId : '');
        $genre ??= (string) ($this->custom[$field] ?? '');
        $this->attempt(function () use ($genres, $check, $key, $trackId, $genre) {
            $n = $genres->apply($check, $key, $trackId, $genre);
            $this->message = '„'.trim($genre).'“ übernommen – bei '.$n.' '.($n === 1 ? 'Lied' : 'Liedern').' (unter Änderungen zurücksetzbar).';
        });
        unset($this->custom[$field]);
    }

    /** jeweils den ersten Vorschlag übernehmen – eine Einheit bzw. alle (null) */
    public function takeGenreSuggestions(?string $key, GenreChecks $genres): void
    {
        if ($check = self::GENRE_VIEWS[$this->view] ?? null) {
            $n = $genres->applySuggested($check, $key);
            $this->say('Vorschläge übernommen – bei '.$n.' '.($n === 1 ? 'Lied' : 'Liedern').' (unter Änderungen zurücksetzbar).');
        }
    }

    /** Genre: „Passt so“ bzw. wieder aufnehmen */
    public function exemptGenre(string $key, bool $exempt, GenreChecks $genres): void
    {
        if ($check = self::GENRE_VIEWS[$this->view] ?? null) {
            $genres->exempt($check, $key, $exempt);
            $this->say($exempt ? 'Passt so – wird nicht mehr angezeigt.' : 'Wieder aufgenommen.');
        }
    }

    /** Fingerabdrücke eines Bereichs im Hintergrund ermitteln */
    public function measureFingerprints(string $location): void
    {
        if (! in_array($location, Fingerprints::LOCATIONS, true)) {
            return;
        }
        if (! MeasureFingerprints::start([$location])) {
            $this->error = 'Läuft schon bzw. nichts zu tun.';
            $this->message = null;

            return;
        }
        $this->dispatch('activity-started');
    }

    #[On('fingerprint-finished')]
    public function fingerprintsFinished(): void {}

    /** Nicht lesbar: die betroffenen Bereiche neu einlesen (nicht lesbare Dateien werden dabei immer neu gelesen) */
    public function rereadErrors(): void
    {
        if (Activity::running('scan')) {
            $this->error = 'Es wird gerade eingelesen – bitte warten.';
            $this->message = null;

            return;
        }
        $locations = Track::whereNotNull('error')->distinct()->pluck('location');
        Activity::start('scan', 'Nicht lesbare Dateien neu lesen – wartet');
        foreach ($locations as $location) {
            ScanLocation::dispatch($location);
        }
        $this->dispatch('activity-started');
    }

    /** Nicht lesbar: alle Einträge mit Fehler aus der Datenbank entfernen – die Dateien bleiben unberührt */
    public function removeErrors(): void
    {
        $n = Track::whereNotNull('error')->delete();
        $this->say($n.' '.($n === 1 ? 'Eintrag' : 'Einträge').' entfernt.');
    }

    #[On('scan-finished')]
    public function scanFinished(): void {}

    public function updatedView(): void
    {
        $this->limit = self::STEP;
        $this->query = '';
        $this->message = $this->error = null;
    }

    #[On('zeichen-finished')]
    public function refreshAfterJob(): void {}

    public function render(TextRepairs $repairs, TextRepairAi $ai, YearDates $years, ReplayGain $replayGain, Duplicates $duplicates, GenreChecks $genres)
    {
        $this->view = isset(self::VIEWS[$this->view]) ? $this->view : 'zeichen';
        $counts = ['zeichen' => $repairs->count(), 'jahr' => $years->count(), 'replaygain' => $replayGain->count(), 'duplikate' => $duplicates->count(),
            'nicht_lesbar' => Track::whereNotNull('error')->count(), 'fingerabdruck' => Fingerprints::pending(Fingerprints::LOCATIONS)->count()];
        foreach (self::GENRE_VIEWS as $view => $check) {
            $counts[$view] = $genres->count($check);
        }
        if ($check = self::GENRE_VIEWS[$this->view] ?? null) {
            $all = $genres->findings($check, $this->query);

            return view('livewire.misc-page', [
                'counts' => $counts,
                'check' => $check,
                'total' => $all->count(),
                'units' => $all->take($this->limit),
                'suggested' => $all->filter(fn ($f) => $f['suggestions'] || collect($f['items'])->contains(fn ($i) => $i['suggestions']))->count(),
                'exempted' => in_array($check, GenreChecks::EXEMPTIBLE, true) ? $genres->findings($check, $this->query, true) : collect(),
                'genreIndex' => $genres->searchIndex(),
            ])->title(self::VIEWS[$this->view].' – MusicWiz');
        }
        if ($this->view === 'duplikate') {
            $all = $duplicates->findings($this->query);

            return view('livewire.misc-page', [
                'counts' => $counts,
                'total' => $all->count(),
                'open' => $all->where('planned', false)->count(),
                'groups' => $all->take($this->limit),
                'exempted' => $duplicates->findings($this->query, true),
            ])->title(self::VIEWS[$this->view].' – MusicWiz');
        }
        if ($this->view === 'fingerabdruck') {
            return view('livewire.misc-page', ['counts' => $counts, 'prints' => Fingerprints::counts()])->title(self::VIEWS[$this->view].' – MusicWiz');
        }
        if ($this->view === 'nicht_lesbar') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($this->query)).'%';
            $all = Track::whereNotNull('error')->when(trim($this->query) !== '', fn ($q) => $q->where(fn ($q) => $q->where('path', 'like', $like)->orWhere('error', 'like', $like)));

            return view('livewire.misc-page', [
                'counts' => $counts,
                'total' => (clone $all)->count(),
                'broken' => $all->orderBy('path')->limit($this->limit)->get(['id', 'location', 'path', 'error', 'scanned_at']),
            ])->title(self::VIEWS[$this->view].' – MusicWiz');
        }
        if ($this->view === 'replaygain') {
            return view('livewire.misc-page', ['counts' => $counts, ...$replayGain->albums($this->query, $this->limit)])->title(self::VIEWS[$this->view].' – MusicWiz');
        }
        if ($this->view === 'jahr') {
            return view('livewire.misc-page', ['counts' => $counts, ...$years->albums($this->query, $this->limit)])->title(self::VIEWS[$this->view].' – MusicWiz');
        }
        $all = $repairs->problems($this->query);
        $problems = $all->take($this->limit);
        $stored = TextRepair::whereIn('hash', $problems->pluck('hash'))->get()->keyBy('hash');

        return view('livewire.misc-page', [
            'counts' => $counts,
            'total' => $all->count(),
            'problems' => $problems,
            'stored' => $stored,
            'aiAvailable' => $ai->available(),
            'unasked' => $ai->available() ? $repairs->unasked()->count() : 0,
        ])->title(self::VIEWS[$this->view].' – MusicWiz');
    }
}
