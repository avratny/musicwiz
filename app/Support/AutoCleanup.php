<?php

namespace App\Support;

use App\Jobs\RunAutoCleanup;
use App\Models\Operation;

/**
 * Aufräum-Automatiken: Prüfungen, die in den Einstellungen je Kategorie freigegeben sind,
 * werden ohne Nachfrage behoben – nach jedem Bestand-Scan oder per Knopf. Alles läuft über den Verlauf
 * und ist damit rückgängig machbar.
 */
class AutoCleanup
{
    public const OTHER = '_other';

    /** Riskantere Prüfungen bekommen in der Oberfläche einen Hinweis. */
    public const RISKY = ['duplicates' => 'verschiebt Dateien in den Papierkorb', 'misplaced' => 'verschiebt Dateien im Bestand', 'leftovers' => 'entfernt leere Ordner und Reste', 'broken' => 'verschiebt defekte Dateien in den Papierkorb'];

    public function __construct(private Settings $settings, private CleanupChecks $checks) {}

    /** @return array<string, array<string, bool>> */
    public function config(): array
    {
        // "Sonstige" (außerhalb der Kategorie-Ordner) gibt es nicht mehr – alte Einstellungen ignorieren
        $config = array_map(fn ($cats) => array_filter(array_diff_key((array) $cats, [self::OTHER => true])), (array) $this->settings->get('auto_cleanup', []));

        return array_filter($config);
    }

    public function isEnabled(string $check): bool
    {
        return ! empty($this->config()[$check]);
    }

    /** Spalten: Kategorien. */
    public function columns(): array
    {
        // nur Kategorien: Aufräumen gilt nur im Bestand, außerhalb der Kategorie-Ordner gibt es keine Funde
        return array_column($this->settings->categories(), 'name');
    }

    /** Behebbare Prüfungen. */
    public function fixableChecks(): array
    {
        return array_filter(CleanupChecks::definitions(), fn ($d) => ! empty($d[2]));
    }

    /** Kategorie einer Aktion anhand des betroffenen Pfads. */
    public function categoryOf(array $action): string
    {
        $path = $action['path'] ?? $action['from_dir'] ?? $action['dir'] ?? '';
        if (is_dir($path) || ($action['type'] ?? '') === 'album_files') {
            $path = rtrim($path, '/').'/';
        }

        return $this->settings->categoryForPath($path)['name'] ?? self::OTHER;
    }

    /**
     * Anzahl Aktionen je Prüfung und Kategorie (für die Einstellungsseite).
     *
     * @return array<string, array<string, int>>
     */
    public function preview(): array
    {
        $out = [];
        foreach (array_keys($this->fixableChecks()) as $check) {
            foreach ($this->checks->findings($check) as $f) {
                foreach ($f['actions'] as $a) {
                    $cat = $this->categoryOf($a);
                    $out[$check][$cat] = ($out[$check][$cat] ?? 0) + 1;
                }
            }
        }

        return $out;
    }

    /** Automatiken im Hintergrund starten (ein Job, Schritte nacheinander). */
    public function run(): bool
    {
        if (! array_filter($this->config())) {
            return false;
        }
        RunAutoCleanup::dispatch();

        return true;
    }

    /**
     * Freigegebene Prüfungen in Arbeitsreihenfolge ausführen. Nach jedem Schritt werden die Funde neu berechnet –
     * so verschiebt "Regelort" erst, nachdem die Schreibweisen vereinheitlicht sind.
     *
     * @return list<Operation>
     */
    public function runNow(OperationRunner $runner): array
    {
        $ops = [];
        $definitions = CleanupChecks::definitions();
        $config = $this->config();
        foreach (array_keys($definitions) as $check) {
            $categories = $config[$check] ?? [];
            if (! $categories || ! isset($this->fixableChecks()[$check])) {
                continue;
            }
            $checks = app(CleanupChecks::class); // frisch: Funde nach dem vorigen Schritt neu berechnen
            $this->checks = $checks;
            $actions = [];
            $findings = 0;
            foreach ($this->checks->findings($check) as $f) {
                $selected = array_values(array_filter($f['actions'], fn ($a) => ! empty($categories[$this->categoryOf($a)])));
                if ($selected) {
                    $findings++;
                    array_push($actions, ...$selected);
                }
            }
            if ($actions) {
                $op = Operation::create(['type' => 'auto', 'description' => 'Automatisch: '.$definitions[$check][0].' ('.$findings.' Funde)',
                    'payload' => ['actions' => $actions, 'options' => ['on_conflict' => 'best']], 'total' => count($actions)]);
                $runner->run($op);
                $ops[] = $op->fresh();
            }
        }

        return $ops;
    }
}
