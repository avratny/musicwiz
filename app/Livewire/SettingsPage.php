<?php

namespace App\Livewire;

use App\Models\Genre;
use App\Models\GenreMapping;
use App\Models\Track;
use App\Support\AutoCleanup;
use App\Support\DataVersion;
use App\Support\Genres;
use App\Support\PathBuilder;
use App\Support\Settings;
use App\Support\SettingsBackup;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app'), Title('Einstellungen')]
class SettingsPage extends Component
{
    use WithFileUploads;

    #[Url]
    public string $tab = 'general';

    public array $s = [];

    public string $newGenre = '';

    public ?int $newGenreParent = null;

    public string $mappingFilter = '';

    // Sichern & Zurücksetzen
    public bool $exportSecrets = false;

    public bool $exportImages = true;

    public int $importImages = 0;

    public $importFile;

    public ?array $importSummary = null;

    public array $importSections = [];

    public array $resetSections = ['settings'];

    /** all = Werkszustand, sections = nur ausgewählte Bereiche */
    public string $resetMode = 'all';

    public string $resetConfirm = '';

    /** Beispiel-Tracks für die Live-Vorschau der Muster: einmal zufällig gewählt, damit sie nicht bei jedem Tastendruck wechseln */
    #[Locked]
    public ?array $sampleIds = null;

    public function mount(Settings $settings): void
    {
        $this->load($settings);
    }

    private function load(Settings $settings): void
    {
        $this->s = $settings->all();
        $this->s['categories'] = $settings->categories();
    }

    public function save(Settings $settings): void
    {
        $this->validate([
            's.base' => 'required|string',
            's.inbox' => 'required|string',
            's.categories.*.name' => 'required|string|distinct',
            's.categories.*.folder' => 'required|string',
            's.categories.*.pattern' => ['required', 'string', 'regex:/\{ext\}$/'],
            's.trash' => 'required|string',
            's.artist_images' => 'required|string',
            's.id3_version' => 'in:3,4',
            's.scan_throttle_ms' => 'integer|min:0|max:5000',
        ], ['s.categories.*.pattern.regex' => 'Das Muster muss mit {ext} enden.']);

        $values = $this->s;
        unset($values['auto_cleanup']); // wird im Reiter „Automatiken“ direkt gespeichert
        $values['id3_version'] = (int) $values['id3_version'];
        $values['scan_throttle_ms'] = (int) $values['scan_throttle_ms'];
        $values['small_cover_px'] = (int) $values['small_cover_px'];
        $values['inbox'] = rtrim(trim($values['inbox']), '/');
        // feste Rollen: gespeichert werden nur Name, Zielordner und Muster
        $values['categories'] = collect($values['categories'])->mapWithKeys(fn ($c) => [$c['role'] => ['name' => trim($c['name']), 'folder' => trim($c['folder'], '/ '), 'pattern' => trim($c['pattern'])]])->all();
        $settings->set($values);
        $this->load($settings);
        session()->flash('status', 'Gespeichert. Neue Muster gelten für neue Einordnungen; die Lib wird nur über „Aufräumen“ verschoben.');
    }

    // ---------------------------------------------------------------- Genres

    public function addGenre(): void
    {
        $name = trim($this->newGenre);
        if ($name === '' || Genre::where('name', $name)->exists()) {
            return;
        }
        Genre::create(['name' => $name, 'parent_id' => $this->newGenreParent ?: null, 'position' => (int) Genre::where('parent_id', $this->newGenreParent ?: null)->max('position') + 1]);
        $this->newGenre = '';
    }

    public function renameGenre(int $id, string $name): void
    {
        $name = trim($name);
        $genre = Genre::find($id);
        if ($genre && $name !== '' && ! Genre::where('name', $name)->where('id', '!=', $id)->exists()) {
            GenreMapping::where('target', $genre->name)->update(['target' => $name]);
            $genre->update(['name' => $name]);
        }
    }

    public function deleteGenre(int $id): void
    {
        $genre = Genre::find($id);
        if ($genre) {
            GenreMapping::where('target', $genre->name)->update(['target' => null]);
            $genre->delete();
        }
    }

    public function moveGenre(int $id, int $dir): void
    {
        $genre = Genre::find($id);
        if (! $genre) {
            return;
        }
        $siblings = Genre::where('parent_id', $genre->parent_id)->orderBy('position')->orderBy('name')->get()->values();
        $i = $siblings->search(fn ($g) => $g->id === $id);
        $j = $i + $dir;
        if ($j < 0 || $j >= $siblings->count()) {
            return;
        }
        $list = $siblings->all();
        [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
        foreach ($list as $pos => $g) {
            $g->update(['position' => $pos]);
        }
    }

    public function updatedImportFile(SettingsBackup $backup): void
    {
        $this->resetErrorBag('importFile');
        $this->importSummary = null;
        try {
            $read = $backup->read($this->importFile->getRealPath());
            $this->importSummary = $backup->summarize($read['data']);
            $this->importImages = count($read['images']);
            $this->importSections = array_keys(array_filter($this->importSummary));
        } catch (\Throwable $e) {
            $this->addError('importFile', $e->getMessage());
        }
    }

    public function runImport(SettingsBackup $backup, Settings $settings): void
    {
        if (! $this->importFile || ! $this->importSections) {
            return;
        }
        $read = $backup->read($this->importFile->getRealPath());
        $backup->import($read['data'], $this->importSections);
        $images = in_array('artists', $this->importSections, true) ? $backup->restoreImages($read['images']) : 0;
        $this->importFile = null;
        $this->importSummary = null;
        $this->load($settings);
        session()->flash('status', 'Sicherung eingespielt: '.implode(', ', array_map(fn ($k) => SettingsBackup::SECTIONS[$k][0], $this->importSections)).'.'
            .($images ? ' '.$images.' Künstlerbild(er) abgelegt.' : ''));
    }

    public function runReset(SettingsBackup $backup, Settings $settings)
    {
        $this->resetErrorBag('resetConfirm');
        if (trim($this->resetConfirm) !== 'ZURÜCKSETZEN') {
            $this->addError('resetConfirm', 'Bitte „ZURÜCKSETZEN“ eintippen.');

            return null;
        }
        if ($this->resetMode === 'all') {
            $backup->factoryReset();
            session()->flash('status', 'MusicWiz ist im Werkszustand – Datenbank geleert. Zum Start: Einstellungen prüfen, dann Inbox und Bestand scannen.');

            return $this->redirectRoute('inbox');
        }
        if (! $this->resetSections) {
            return null;
        }
        $backup->reset($this->resetSections);
        $done = implode(', ', array_map(fn ($k) => SettingsBackup::SECTIONS[$k][0], $this->resetSections));
        $this->resetConfirm = '';
        $this->load($settings);
        session()->flash('status', 'Zurückgesetzt: '.$done.'.');

        return null;
    }

    public function toggleAuto(string $check, string $category, Settings $settings): void
    {
        $config = (array) $settings->get('auto_cleanup', []);
        $config[$check][$category] = empty($config[$check][$category]);
        $config[$check] = array_filter($config[$check]);
        $settings->set(['auto_cleanup' => array_filter($config)]);
        $this->s['auto_cleanup'] = $settings->get('auto_cleanup');
    }

    public function runAuto(AutoCleanup $auto): void
    {
        $started = $auto->run();
        $this->dispatch('activity-changed');
        session()->flash('status', $started ? 'Automatiken gestartet – Schritt für Schritt, Details im Protokoll und Verlauf.' : 'Keine Automatik freigegeben.');
    }

    public function setMapping(string $source, string $target): void
    {
        GenreMapping::updateOrCreate(['source' => $source], ['target' => $target !== '' ? $target : null]);
    }

    public function render(Settings $settings, PathBuilder $paths, Genres $genres)
    {
        $data = ['categoriesPreview' => [], 'genreTree' => collect(), 'mappings' => [], 'genreOptions' => []];

        if ($this->tab === 'categories') {
            $preview = $settings->override(['categories' => $this->s['categories'], 'base' => $this->s['base'], 'no_album' => $this->s['no_album'],
                'non_letter_folder' => $this->s['non_letter_folder'], 'replace_colon' => $this->s['replace_colon'], 'replace_slash' => $this->s['replace_slash']]);
            $builder = new PathBuilder($preview);
            $this->sampleIds ??= Track::inRandomOrder()->whereNotNull('artist')->limit(3)->pluck('id')->all();
            $samples = Track::whereIn('id', $this->sampleIds)->get()->map(fn ($t) => ['tags' => $t->tags(), 'path' => $t->path])->all();
            $samples[] = ['tags' => ['artist' => 'Yanou feat. Liz', 'title' => 'King of My Castle', 'album' => 'Sunrise', 'year' => '2006', 'track' => '5', 'disc' => '2', 'disctotal' => '2'], 'path' => 'x.flac'];
            $samples[] = ['tags' => ['artist' => 'AC/DC', 'title' => 'Thunderstruck', 'album' => '', 'year' => '', 'track' => ''], 'path' => 'x.mp3'];
            foreach ($preview->categories() as $i => $c) {
                foreach ($samples as $sample) {
                    try {
                        $target = $builder->target($c, $sample['tags'], PathBuilder::isMultiDisc([$sample['tags']]), $sample['path']);
                        $data['categoriesPreview'][$i][] = substr($target, strlen(rtrim($this->s['base'], '/')) + 1);
                    } catch (\Throwable $e) {
                        $data['categoriesPreview'][$i][] = 'Fehler: '.$e->getMessage();
                    }
                }
            }
        }
        if ($this->tab === 'auto') {
            $auto = app(AutoCleanup::class);
            $data['autoChecks'] = $auto->fixableChecks();
            $data['autoColumns'] = $auto->columns();
            $data['autoConfig'] = $auto->config();
            $data['autoPreview'] = $auto->preview();
            $data['risky'] = AutoCleanup::RISKY;
        }
        if ($this->tab === 'genres' || $this->tab === 'mapping') {
            $data['genreTree'] = Genre::whereNull('parent_id')->with('children')->orderBy('position')->orderBy('name')->get();
            $data['genreOptions'] = $genres->options();
        }
        if ($this->tab === 'mapping') {
            // Genre-Häufigkeiten über den ganzen Bestand nur bei geändertem Datenstand neu zählen
            $all = Cache::remember('genre-counts:'.DataVersion::tracks(), now()->addHour(), function () {
                $all = [];
                foreach (Track::where('location', '!=', 'blackhole')->select('genres')->cursor() as $t) {
                    foreach ($t->genres ?? [] as $g) {
                        $all[$g] = ($all[$g] ?? 0) + 1;
                    }
                }

                return $all;
            });
            $counts = array_filter($all, fn ($g) => ! $genres->isListed($g), ARRAY_FILTER_USE_KEY);
            foreach (GenreMapping::pluck('source') as $src) {
                $counts[$src] ??= 0;
            }
            if ($this->mappingFilter !== '') {
                $counts = array_filter($counts, fn ($k) => stripos($k, $this->mappingFilter) !== false, ARRAY_FILTER_USE_KEY);
            }
            arsort($counts);
            $data['mappings'] = $counts;
            $data['mapped'] = GenreMapping::pluck('target', 'source')->all();
        }

        return view('livewire.settings', $data);
    }
}
