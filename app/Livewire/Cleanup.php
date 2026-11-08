<?php

namespace App\Livewire;

use App\Jobs\IntegrityJob;
use App\Jobs\RefreshCleanup;
use App\Models\CleanupException;
use App\Models\DuplicateException;
use App\Models\Operation;
use App\Support\AutoCleanup;
use App\Support\CleanupChecks;
use App\Support\FileOps;
use App\Support\Integrity;
use App\Support\OperationRunner;
use App\Support\Quality;
use App\Support\Scanner;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app'), Title('Aufräumen')]
class Cleanup extends Component
{
    #[Url]
    public string $check = 'broken';

    public array $selected = [];

    /** Duplikate: welche Datei behalten wird */
    public array $keep = [];

    public ?array $preview = null;

    public string $onConflict = 'best';

    public int $limit = 200;

    /** Aktionen, deren Ende wir abwarten, um Liste und Badges neu zu berechnen */
    public array $watching = [];

    public ?string $done = null;

    public bool $showDismissed = false;

    public function updatedCheck(): void
    {
        $this->selected = [];
        $this->preview = null;
        $this->limit = 200;
    }

    public function selectAll(CleanupChecks $checks): void
    {
        $this->selected = array_column(array_filter($checks->findings($this->check), fn ($f) => $this->actionsFor($f)), 'id');
    }

    /** Gerade per Zeile gestartete Funde: finding-id => operation-id */
    public array $running = [];

    /** Einzelnen Fund direkt übernehmen (die Zeile zeigt, was geändert wird). */
    public function applyOne(string $findingId, CleanupChecks $checks): void
    {
        $f = collect($checks->findings($this->check))->firstWhere('id', $findingId);
        $actions = $f ? $this->actionsFor($f) : [];
        if (! $actions) {
            return;
        }
        $label = CleanupChecks::definitions()[$this->check][0] ?? $this->check;
        $op = OperationRunner::dispatch('cleanup', $label.': '.$f['title'], $actions, ['on_conflict' => $this->onConflict]);
        $this->watching[] = $op->id;
        $this->running[$findingId] = $op->id;
        $this->selected = array_values(array_diff($this->selected, [$findingId]));
        $this->dispatch('activity-changed');
    }

    public function previewFix(CleanupChecks $checks, bool $all = false): void
    {
        $findings = $checks->findings($this->check);
        $ids = array_column($findings, 'id');
        $this->selected = array_values(array_intersect($this->selected, $ids));
        if (! $all) {
            $findings = array_filter($findings, fn ($f) => in_array($f['id'], $this->selected, true));
        }
        $actions = [];
        foreach ($findings as $f) {
            array_push($actions, ...$this->actionsFor($f));
        }
        // Belegte Ziele: vorab zeigen, was mit "bessere Version behalten" passieren würde
        $notes = [];
        $byTarget = [];
        foreach ($actions as $i => $a) {
            if (! empty($a['target']) && $a['target'] !== ($a['path'] ?? null)) {
                $byTarget[$a['target']][] = $i;
                if (file_exists($a['target']) && ! FileOps::sameFile($a['path'], $a['target'])) {
                    $notes[$i] = Quality::compare($a['path'], $a['target'])['reason'];
                }
            }
        }
        foreach ($byTarget as $target => $idx) {
            if (count($idx) > 1) {
                foreach ($idx as $i) {
                    $notes[$i] ??= count($idx).' Dateien haben dasselbe Ziel – die bessere bleibt';
                }
            }
        }
        // Aktionen nur im Server-Zwischenspeicher: im Seitenzustand würden tausende Pfade bei jedem Aktualisieren
        // hin- und hergeschickt (Livewire-Grenze 1 MB) – angezeigt werden ohnehin nur die ersten 300
        $token = bin2hex(random_bytes(8));
        Cache::put(self::PREVIEW_KEY.$token, ['actions' => $actions, 'count' => count($findings)], now()->addHours(2));
        $this->preview = [
            'token' => $token, 'count' => count($findings), 'all' => $all, 'total' => count($actions),
            'shown' => array_slice($actions, 0, self::PREVIEW_SHOWN),
            'notes' => array_filter($notes, fn ($i) => $i < self::PREVIEW_SHOWN, ARRAY_FILTER_USE_KEY),
            'notesCount' => count($notes),
            'hasMoves' => collect($actions)->contains(fn ($a) => in_array($a['type'], ['move', 'apply'], true) && ! empty($a['target'])),
        ];
    }

    private const PREVIEW_KEY = 'cleanup.preview.';

    private const PREVIEW_SHOWN = 300;

    public function confirm(): void
    {
        $stored = $this->preview ? Cache::pull(self::PREVIEW_KEY.$this->preview['token']) : null;
        if (! $stored || ! $stored['actions']) {
            $this->preview = null;

            return;
        }
        $label = CleanupChecks::definitions()[$this->check][0] ?? $this->check;
        $op = OperationRunner::dispatch('cleanup', 'Aufräumen: '.$label.' ('.$stored['count'].' Funde)', $stored['actions'], ['on_conflict' => $this->onConflict]);
        $this->watching[] = $op->id;
        $this->preview = null;
        $this->selected = [];
        $this->dispatch('activity-changed');
        $this->done = null;
    }

    private function actionsFor(array $finding): array
    {
        if ($this->check === 'duplicates' && ($finding['choosable'] ?? false) && ($this->keep[$finding['id']] ?? $finding['default_keep'] ?? null)) {
            // nicht angefasst: vorgeschlagene Fassung (gespeichert wird die Auswahl nur für sichtbare Funde)
            $keep = $this->keep[$finding['id']] ?? $finding['default_keep'];

            return array_values(array_map(fn ($o) => ['type' => 'trash', 'path' => $o['path']], array_filter($finding['options'], fn ($o) => $o['path'] !== $keep)));
        }

        return $finding['actions'];
    }

    /** Duplikat: die Fassungen sind verschieden und sollen alle bleiben. */
    public function markDifferent(string $findingId, CleanupChecks $checks): void
    {
        $f = collect($checks->findings('duplicates'))->firstWhere('id', $findingId);
        if (! $f) {
            return;
        }
        DuplicateException::create(['song_key' => $f['song_key'], 'members' => $f['fingerprints'], 'label' => $f['title']]);
        $this->selected = array_values(array_diff($this->selected, [$findingId]));
        $this->done = '„'.$f['title'].'“ als verschieden markiert – wird nicht mehr als Duplikat gemeldet.';
    }

    /** Album bewusst so lassen (z. B. „Electronic reicht hier“): wird bei dieser Prüfung nicht mehr gemeldet. */
    public function dismissAlbum(string $findingId, CleanupChecks $checks): void
    {
        $f = collect($checks->findings($this->check))->firstWhere('id', $findingId);
        if (! $f || empty($f['dismiss_key'])) {
            return;
        }
        CleanupException::firstOrCreate(['check' => $this->check, 'item_key' => $f['dismiss_key']], ['label' => $f['title']]);
        $this->selected = array_values(array_diff($this->selected, [$findingId]));
        $this->done = '„'.$f['title'].'“ – Electronic reicht hier, wird nicht mehr gemeldet.';
    }

    public function undismissAlbum(string $key): void
    {
        CleanupException::where('check', $this->check)->where('item_key', $key)->delete();
    }

    public function undismiss(string $songKey, array $fingerprints): void
    {
        foreach (DuplicateException::where('song_key', $songKey)->get() as $e) {
            if (CleanupChecks::isSubMultiset($fingerprints, $e->members)) {
                $e->delete();
            }
        }
    }

    /** Audiodaten der ganzen Lib prüfen (Hintergrund, inkrementell). */
    public function startIntegrity(Integrity $integrity): void
    {
        if ($integrity->status()['running'] ?? false) {
            return;
        }
        $integrity->status(['running' => true, 'done' => 0, 'total' => null, 'error' => null, 'finished_at' => null]);
        IntegrityJob::dispatch();
    }

    #[On('activity-changed')]
    public function refreshList(): void {}

    public function render(CleanupChecks $checks, Settings $settings)
    {
        // laufende Aktionen (auch von woanders gestartet, z. B. Automatiken) beobachten – außer Downloads (eigene
        // Warteschlange, fassen die Lib nicht an) und erst später eingeplanten: die sperren Aufräumen nicht
        $active = Operation::whereIn('status', ['pending', 'running'])->where('type', '!=', 'download')->orderBy('id')->get()
            ->reject(fn (Operation $op) => $op->isPlanned())->values();
        foreach ($active as $op) {
            if (! in_array($op->id, $this->watching, true)) {
                $this->watching[] = $op->id;
            }
        }
        $finished = Operation::whereIn('id', $this->watching)->whereNotIn('status', ['pending', 'running'])->get();
        if ($finished->isNotEmpty()) {
            $this->done = $finished->map(fn ($op) => ($op->status === 'failed' ? '⚠ ' : '✓ ').$op->description.': '.$op->done.' von '.$op->total.' Schritten'
                .($op->failed ? ', '.$op->failed.' übersprungen' : ''))->implode("\n");
            $this->watching = array_values(array_diff($this->watching, $finished->pluck('id')->all()));
            $this->running = array_filter($this->running, fn ($opId) => ! $finished->pluck('id')->contains($opId));
            $this->preview = null;
        }
        $defs = CleanupChecks::definitions();
        // nie alle Prüfungen im Seitenaufruf rechnen: veraltet → letzten Stand zeigen, im Hintergrund neu berechnen –
        // die gerade offene Prüfung zuerst
        Cache::put(CleanupChecks::FOCUS, $this->check, now()->addMinutes(30));
        $fresh = $checks->isFresh();
        if (! $fresh) {
            RefreshCleanup::request();
            $fresh = $checks->isFresh(); // synchrone Warteschlange (Tests): schon fertig
        }
        $checks->withDismissed = in_array($this->check, ['duplicates', 'electronic_sub'], true) && $this->showDismissed;
        [$all, $staleList, $pending] = $checks->withDismissed && $fresh ? [$checks->findings($this->check), false, false] : $checks->displayFindings($this->check);
        $dismissed = array_values(array_filter($all, fn ($f) => $f['dismissed'] ?? false));
        $findings = array_values(array_filter($all, fn ($f) => ! ($f['dismissed'] ?? false)));
        // vorgeschlagene Fassung vorauswählen – nur für sichtbare Funde (der Seitenzustand bleibt klein); für alle
        // übrigen gilt beim Beheben der Vorschlag (actionsFor)
        foreach (array_slice($findings, 0, $this->limit) as $f) {
            if (($f['choosable'] ?? false) && ! isset($this->keep[$f['id']])) {
                $this->keep[$f['id']] = $f['default_keep'];
            }
        }
        $this->selected = array_values(array_intersect($this->selected, array_column($findings, 'id')));

        return view('livewire.cleanup', [
            'defs' => $defs,
            'counts' => $checks->displayCounts(),
            // veraltet: Liste/Anzahlen vom letzten Stand, Beheben gesperrt bis zur Neuberechnung
            // gesperrt wird nur, solange die offene Prüfung selbst veraltet ist – die Zahlen links dürfen nachziehen
            'stale' => $staleList,
            'countsStale' => ! $fresh,
            'pending' => $pending,
            'progress' => $fresh ? null : CleanupChecks::progress(),
            'scanRunning' => (bool) (app(Scanner::class)->status()['running'] ?? false),
            'openBefore' => $checks->openBefore($this->check),
            'step' => CleanupChecks::stepOf($this->check),
            'active' => $active,
            'auto' => app(AutoCleanup::class)->config(),
            'autoHeld' => app(AutoCleanup::class)->held(),
            'findings' => array_slice($findings, 0, $this->limit),
            'total' => count($findings),
            'dismissed' => $dismissed,
            'dismissedCount' => match ($this->check) {
                'duplicates' => DuplicateException::count(),
                'electronic_sub' => CleanupException::where('check', 'electronic_sub')->count(),
                default => 0,
            },
            'fixable' => (bool) ($defs[$this->check][2] ?? null),
            'integrity' => $this->check === 'integrity' ? app(Integrity::class)->status() : null,
            'base' => $settings->base(),
        ]);
    }
}
