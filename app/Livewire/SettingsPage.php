<?php

namespace App\Livewire;

use App\Jobs\UpdateMusicBrainzData;
use App\Models\NameRule;
use App\Support\Duplicates;
use App\Support\Locations;
use App\Support\MusicBrainzData;
use App\Support\NameRules;
use App\Support\PathTemplate;
use App\Support\Settings;
use App\Support\SortNames;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Einstellungen in Tabs: Zugangsdaten anderer Plattformen, Ordner (aus der .env) mit Vorlagen für die Dateinamen und
 * Schreibweisen (Regeln für Interpreten: was zu „Various Artists“ wird, Ersetzungen wie „d.r.“ → „Dr.“) und Sonstiges
 * (z. B. Mindestgröße der Cover).
 */
#[Layout('layouts.app')]
class SettingsPage extends Component
{
    public const TABS = ['zugangsdaten' => 'Zugangsdaten', 'ordner' => 'Ordner', 'schreibweisen' => 'Schreibweisen', 'musicbrainz' => 'MusicBrainz', 'sonstiges' => 'Sonstiges'];

    public const SECTIONS = ['various' => 'Various Artists', 'names' => 'Namen', 'sortierung' => 'Sortiernamen'];

    #[Url]
    public string $tab = 'zugangsdaten';

    #[Url]
    public string $section = 'various';

    public array $secrets = [];

    /** Vorlagen und Optionen für die Dateinamen (Schlüssel aus PathTemplate::TEMPLATES und ::OPTIONS) */
    public array $paths = [];

    public array $pathErrors = [];

    public ?string $pathsSaved = null;

    /** Einstellungen › Sonstiges (Schlüssel aus Settings::OTHER) */
    public array $other = [];

    public array $otherErrors = [];

    public ?string $otherSaved = null;

    /** Duplikate: allgemeine Titel (kein Ersetzen über Albumgrenzen ohne Fingerabdruck), kommagetrennt */
    public string $genericTitles = '';

    public ?string $saved = null;

    /** Schreibweisen › Sortiernamen: Artikel, die nach hinten kommen („The Beatles“ → „Beatles, The“) */
    public string $articles = '';

    public string $newRule = '';

    public string $newReplacement = '';

    public bool $newWholeWord = true;

    public ?string $message = null;

    public ?string $error = null;

    public function mount(Settings $settings): void
    {
        $this->articles = (string) $settings->get('sort_articles', SortNames::DEFAULT_ARTICLES);
        foreach (array_keys(Settings::SECRETS) as $key) {
            $this->secrets[$key] = (string) $settings->get($key, '');
        }
        foreach (PathTemplate::TEMPLATES as $key => [, , , $default]) {
            $this->paths[$key] = (string) $settings->get($key, $default);
        }
        foreach (PathTemplate::OPTIONS as $key => [, $default]) {
            $this->paths[$key] = (string) $settings->get($key, $default);
        }
        foreach (array_keys(Settings::OTHER) as $key) {
            $this->other[$key] = (string) $settings->other($key);
        }
        $this->genericTitles = (string) $settings->get('generic_titles', Duplicates::GENERIC_TITLES);
    }

    public function saveOther(Settings $settings): void
    {
        $this->otherSaved = null;
        $this->otherErrors = [];
        foreach (Settings::OTHER as $key => [, , , $min, $max]) {
            $value = trim((string) ($this->other[$key] ?? ''));
            if (! preg_match('/^\d+$/', $value) || (int) $value < $min || (int) $value > $max) {
                $this->otherErrors[$key] = 'Ganze Zahl von '.$min.' bis '.$max.'.';
            }
        }
        if ($this->otherErrors) {
            return;
        }
        $settings->set(array_map(fn ($v) => (string) (int) $v, array_intersect_key($this->other, Settings::OTHER)));
        $titles = implode(', ', array_unique(array_filter(array_map('trim', explode(',', $this->genericTitles)))));
        $settings->set(['generic_titles' => $titles === Duplicates::GENERIC_TITLES ? null : $titles]);
        $this->genericTitles = $titles;
        $this->otherSaved = 'Gespeichert.';
    }

    /** Artikel speichern, dann Eindeutiges eintragen (umgedrehte Namen, Sortiernamen) */
    public function saveArticles(Settings $settings, SortNames $sorts): void
    {
        $list = implode(', ', array_unique(array_filter(array_map('trim', explode(',', $this->articles)))));
        $settings->set(['sort_articles' => $list === '' ? null : $list]);
        $this->articles = $list;
        $sorts->forget();
        $n = $sorts->apply();
        $this->message = 'Gespeichert'.($n ? ' – Sortiernamen bei '.$n.' '.($n === 1 ? 'Lied' : 'Liedern').' angepasst.' : '.');
        $this->error = null;
    }

    public function save(Settings $settings): void
    {
        $settings->set(array_intersect_key($this->secrets, Settings::SECRETS));
        $this->saved = 'Gespeichert.';
    }

    public function savePaths(Settings $settings): void
    {
        $this->pathsSaved = null;
        $this->pathErrors = [];
        foreach (array_keys(PathTemplate::TEMPLATES) as $key) {
            if ($problem = PathTemplate::problem((string) ($this->paths[$key] ?? ''))) {
                $this->pathErrors[$key] = $problem;
            }
        }
        foreach (PathTemplate::OPTIONS as $key => [$label]) {
            if (preg_match('/[\/\\\\:?*<>|"]/', (string) ($this->paths[$key] ?? ''))) {
                $this->pathErrors[$key] = 'Keine Zeichen, die in Namen nicht erlaubt sind (/ \\ : ? * < > | ").';
            }
        }
        if ($this->pathErrors) {
            return;
        }
        $settings->set(array_intersect_key($this->paths, PathTemplate::TEMPLATES + PathTemplate::OPTIONS));
        $this->pathsSaved = 'Gespeichert.';
    }

    /** Vorlage auf die Vorgabe zurücksetzen (noch nicht gespeichert) */
    public function resetTemplate(string $key): void
    {
        if (isset(PathTemplate::TEMPLATES[$key])) {
            $this->paths[$key] = PathTemplate::TEMPLATES[$key][3];
        }
    }

    /** Regel hinzufügen und gleich auf alle Lieder anwenden (System-Änderungen) */
    public function addRule(): void
    {
        $kind = $this->section === 'names' ? 'word' : 'various';
        $value = trim(preg_replace('/\s+/u', ' ', $this->newRule));
        $replacement = trim(preg_replace('/\s+/u', ' ', $this->newReplacement));
        $this->message = $this->error = null;
        if ($value === '' || mb_strlen($value) > 128 || ($kind === 'word' && ($replacement === '' || mb_strlen($replacement) > 128))) {
            $this->error = $kind === 'word' ? 'Bitte Suchtext und Ersetzung eingeben.' : 'Bitte einen Wert eingeben.';

            return;
        }
        if ($kind === 'word' && $value === $replacement) {
            $this->error = 'Suchtext und Ersetzung sind gleich.';

            return;
        }
        if ($kind === 'various' && NameRules::normalize($value) === NameRules::normalize(NameRules::VARIOUS)) {
            $this->error = '„'.NameRules::VARIOUS.'“ ist das Ziel selbst.';

            return;
        }
        $existing = NameRule::where('kind', $kind)->get()->first(fn ($r) => $kind === 'various'
            ? NameRules::normalize($r->value) === NameRules::normalize($value)
            : mb_strtolower($r->value) === mb_strtolower($value));
        if ($existing) {
            $this->error = '„'.$value.'“ '.($kind === 'various'
                ? 'ist schon abgedeckt ('.($existing->value === $value ? 'gibt es schon' : 'durch „'.$existing->value.'“').').'
                : 'wird schon ersetzt (durch „'.$existing->replacement.'“).');

            return;
        }
        NameRule::create(['kind' => $kind, 'value' => $value] + ($kind === 'word' ? ['replacement' => $replacement, 'whole_word' => $this->newWholeWord] : []));
        $this->newRule = $this->newReplacement = '';
        $this->newWholeWord = true;
        $n = app(NameRules::class)->apply();
        $this->message = '„'.$value.'“'.($kind === 'word' ? ' → „'.$replacement.'“' : '').' hinzugefügt – '.$this->changedText($n);
    }

    /** Weitere Schreibweise, die zu $replacement wird (in der Gruppe „Dr.“ wird geschrieben, wenn …) */
    public function addTo(string $replacement, string $search): void
    {
        $this->section = 'names';
        $this->newReplacement = $replacement;
        $this->newRule = $search;
        $this->newWholeWord = true;
        $this->addRule();
    }

    /** Namen-Regel: nur als eigenes Wort ↔ auch innerhalb eines Worts */
    public function toggleWholeWord(int $id): void
    {
        $rule = NameRule::where('kind', 'word')->findOrFail($id);
        $rule->update(['whole_word' => ! $rule->whole_word]);
        $n = app(NameRules::class)->apply();
        $this->error = null;
        $this->message = '„'.$rule->value.'“ gilt jetzt '.($rule->whole_word ? 'nur als eigenes Wort' : 'auch innerhalb eines Worts').' – '.$this->changedText($n);
    }

    private function changedText(int $n): string
    {
        return $n.' '.($n === 1 ? 'Lied' : 'Lieder').' angepasst'.($n ? ' (siehe Änderungen)' : '').'.';
    }

    /** Regel entfernen – bereits angepasste Lieder bleiben so */
    public function removeRule(int $id): void
    {
        $rule = NameRule::findOrFail($id);
        $rule->delete();
        $this->error = null;
        $this->message = '„'.$rule->value.'“ entfernt.';
    }

    public function updatedSection(): void
    {
        $this->newRule = $this->newReplacement = '';
        $this->newWholeWord = true;
        $this->message = $this->error = null;
    }

    /** Beispiele je Vorlage – mit den eingegebenen (noch nicht gespeicherten) Werten */
    private function examples(): array
    {
        $builder = new PathTemplate(array_intersect_key($this->paths, PathTemplate::OPTIONS));
        $out = [];
        foreach (PathTemplate::TEMPLATES as $key => [$location, $compilation]) {
            $template = (string) ($this->paths[$key] ?? '');
            $out[$key] = PathTemplate::problem($template) ? [] : $builder->examples($template, $location, $compilation);
        }

        return $out;
    }

    /** MusicBrainz-Daten aktualisieren (im Hintergrund); $force: auch wenn der Stand schon aktuell ist */
    public function updateMusicBrainz(bool $force = false): void
    {
        UpdateMusicBrainzData::start($force);
        $this->dispatch('activity-started');
    }

    #[On('musicbrainz-finished')]
    public function musicBrainzFinished(): void {}

    public function render()
    {
        $folders = [];
        foreach (Locations::LABELS as $location => $label) {
            $folders[$label] = Locations::root($location);
        }
        $folders['Interpretenbilder'] = (string) config('musicwiz.artist_images');

        return view('livewire.settings-page', [
            'folders' => array_map(fn ($path) => ['path' => $path, 'ok' => is_dir($path)], $folders),
            'workers' => config('musicwiz.scan_workers'),
            'rules' => NameRule::orderBy('value')->get()->groupBy('kind'),
            'examples' => $this->tab === 'ordner' ? $this->examples() : [],
            'musicbrainz' => $this->tab === 'musicbrainz' ? app(MusicBrainzData::class)->status() : null,
        ])->title('Einstellungen – MusicWiz');
    }
}
