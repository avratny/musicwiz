<?php

namespace App\Support;

use App\Models\Album;
use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Models\ArtistSplit;
use App\Models\CleanupException;
use App\Models\DiscSet;
use App\Models\DuplicateException;
use App\Models\Genre;
use App\Models\GenreMapping;
use App\Models\Leftover;
use App\Models\RejectedAlias;
use App\Models\Setting;
use App\Models\Track;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Exportieren, Importieren und Zurücksetzen aller selbst gepflegten Daten.
 * Musikdateien, Verlauf und Papierkorb bleiben immer unberührt.
 */
class SettingsBackup
{
    public const FORMAT = 'musicwiz-backup';

    public const SECRETS = ['discogs_token', 'fanart_key', 'acoustid_key', 'spotify_client_id', 'spotify_client_secret', 'audiodb_key', 'anthropic_api_key'];

    /** Bereiche: Schlüssel => [Bezeichnung, Hinweis] */
    public const SECTIONS = [
        'settings' => ['Einstellungen', 'Pfade, Kategorien & Muster, Automatiken (zurücksetzen = Startwerte aus der .env)'],
        'genres' => ['Genre-Liste', 'zurücksetzen = Vorlage aus der Planung'],
        'genre_mappings' => ['Genre-Zuordnung', 'Wert im Bestand → Listen-Genre'],
        'artists' => ['Künstlerverzeichnis', 'Namen, Varianten, IDs, Zusätze gleichnamiger Künstler und Künstlerbilder (beim Zurücksetzen bleiben die Bilddateien liegen)'],
        'disc_sets' => ['CD-Zuordnungen', 'von Hand zusammengeführte Mehrfach-CDs'],
        'duplicate_exceptions' => ['Aufräum-Ausnahmen', '„Verschieden – alle behalten“, „Electronic reicht hier“'],
        'index' => ['Scan-Daten (Inbox & Bestand)', 'eingelesener Stand, Reste-Liste, Cover-Vorschauen – Dateien bleiben, danach neu scannen'],
    ];

    /** Bereiche, die in einer Sicherung stehen können (Scan-Daten nicht – die entstehen beim Scannen neu). */
    public const BACKUP_SECTIONS = ['settings', 'genres', 'genre_mappings', 'artists', 'disc_sets', 'duplicate_exceptions'];

    public function __construct(private Settings $settings) {}

    public function export(bool $withSecrets = false): array
    {
        $settings = Setting::pluck('value', 'key')->all();
        if (! $withSecrets) {
            $settings = array_diff_key($settings, array_flip(self::SECRETS));
        }

        return [
            'format' => self::FORMAT,
            'version' => 1,
            'exported_at' => now()->toIso8601String(),
            'settings' => $settings,
            'genres' => Genre::whereNull('parent_id')->orderBy('position')->with('children')->get()
                ->map(fn ($g) => ['name' => $g->name, 'children' => $g->children->pluck('name')->all()])->all(),
            'genre_mappings' => GenreMapping::orderBy('source')->get(['source', 'target'])->toArray(),
            'artists' => Artist::with(['aliases', 'rejectedAliases'])->orderBy('name')->get()->map(fn ($a) => [
                'name' => $a->name, 'base_name' => $a->base_name, 'suffix' => $a->suffix, 'disambiguation' => $a->disambiguation,
                'mbid' => $a->mbid, 'discogs_id' => $a->discogs_id, 'default_genre' => $a->default_genre, 'image' => $a->image,
                // von Hand: nur der Name; von MusicBrainz: mit Herkunft
                'aliases' => $a->aliases->map(fn ($al) => $al->source ? ['alias' => $al->alias, 'source' => $al->source] : $al->alias)->all(),
                'rejected_aliases' => $a->rejectedAliases->pluck('alias')->all(),
            ])->all(),
            // gemerkte Zusammenarbeiten („Yanou & Liz“ → Yanou, Liz) gehören zum Künstlerverzeichnis
            'artist_splits' => ArtistSplit::orderBy('name')->get(['name', 'parts'])->toArray(),
            'disc_sets' => DiscSet::all()->pluck('members')->all(),
            'duplicate_exceptions' => DuplicateException::all(['song_key', 'members', 'label'])->toArray(),
            'cleanup_exceptions' => CleanupException::all(['check', 'item_key', 'label'])->toArray(),
        ];
    }

    /**
     * Sicherung als ZIP: einstellungen.json und – auf Wunsch – alle Künstlerbilder unter artist-images/.
     *
     * @return string Pfad der ZIP-Datei (temporär)
     */
    public function exportZip(bool $withSecrets, bool $withImages): string
    {
        $data = $this->export($withSecrets);
        $zipPath = tempnam(sys_get_temp_dir(), 'mwbackup').'.zip';
        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ZIP-Datei kann nicht angelegt werden.');
        }
        $images = 0;
        if ($withImages) {
            $dir = rtrim((string) $this->settings->get('artist_images'), '/');
            foreach ($data['artists'] as $a) {
                $file = $a['image'] ?? null;
                if ($file && is_file($dir.'/'.$file)) {
                    $zip->addFile($dir.'/'.$file, 'artist-images/'.$file);
                    $images++;
                }
            }
        }
        $data['artist_images'] = $images;
        $zip->addFromString('einstellungen.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->close();

        return $zipPath;
    }

    /**
     * Sicherung lesen: ZIP (mit Bildern) oder reine JSON-Datei.
     *
     * @return array{data: array, images: array<string, string>} images: Dateiname => Inhalt
     */
    public function read(string $path): array
    {
        $head = (string) file_get_contents($path, false, null, 0, 4);
        if ($head === "PK\x03\x04") {
            $zip = new \ZipArchive;
            if ($zip->open($path) !== true) {
                throw new RuntimeException('ZIP-Datei kann nicht gelesen werden.');
            }
            $json = $zip->getFromName('einstellungen.json');
            if ($json === false) {
                throw new RuntimeException('In der ZIP-Datei fehlt einstellungen.json – das ist keine MusicWiz-Sicherung.');
            }
            $images = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (str_starts_with($name, 'artist-images/') && ! str_ends_with($name, '/')) {
                    $images[basename($name)] = $zip->getFromIndex($i);
                }
            }
            $zip->close();
        } else {
            $json = (string) file_get_contents($path);
            $images = [];
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('Die Datei ist kein gültiges JSON.');
        }
        $this->summarize($data);

        return ['data' => $data, 'images' => $images];
    }

    /** Künstlerbilder aus der Sicherung ablegen; ein abweichendes vorhandenes Bild wandert in den Papierkorb. */
    public function restoreImages(array $images): int
    {
        $dir = rtrim((string) $this->settings->get('artist_images'), '/');
        $files = app(FileOps::class);
        $files->ensureDir($dir);
        $n = 0;
        foreach ($images as $name => $content) {
            $name = basename($name);
            $target = $dir.'/'.$name;
            if (is_file($target)) {
                if (md5_file($target) === md5($content)) {
                    continue;
                }
                $files->trash($target);
            }
            file_put_contents($target, $content);
            $n++;
        }

        return $n;
    }

    /** Was steckt in einer Sicherung? (Anzahl je Bereich) */
    public function summarize(array $data): array
    {
        if (($data['format'] ?? null) !== self::FORMAT) {
            throw new RuntimeException('Das ist keine MusicWiz-Sicherung.');
        }

        return [
            'settings' => count($data['settings'] ?? []),
            'genres' => collect($data['genres'] ?? [])->sum(fn ($g) => 1 + count($g['children'] ?? [])),
            'genre_mappings' => count($data['genre_mappings'] ?? []),
            'artists' => count($data['artists'] ?? []),
            'disc_sets' => count($data['disc_sets'] ?? []),
            'duplicate_exceptions' => count($data['duplicate_exceptions'] ?? []) + count($data['cleanup_exceptions'] ?? []),
            // kein eigener Bereich (steckt in den Einstellungen) – nur als Hinweis: freigegebene Automatiken laufen nach dem nächsten Scan
            'auto_cleanup' => collect((array) ($data['settings']['auto_cleanup'] ?? []))->sum(fn ($cats) => count(array_filter((array) $cats))),
        ];
    }

    /** Gewählte Bereiche aus der Sicherung übernehmen (ersetzt den jeweiligen Bereich). */
    public function import(array $data, array $sections): void
    {
        $this->summarize($data);
        DB::transaction(function () use ($data, $sections) {
            if (in_array('settings', $sections, true)) {
                $keep = Setting::whereIn('key', self::SECRETS)->pluck('value', 'key')->all(); // Zugangsdaten bleiben, wenn nicht exportiert
                Setting::query()->delete();
                $imported = $data['settings'] ?? [];
                // ältere Sicherungen: mehrere Inbox-Ordner – der erste gilt
                if (! isset($imported['inbox']) && ! empty($imported['inbox_paths'])) {
                    $imported['inbox'] = (string) ((array) $imported['inbox_paths'])[0];
                }
                foreach ($keep + $imported as $key => $value) {
                    if (in_array($key, Settings::KEYS, true)) {
                        Setting::create(['key' => $key, 'value' => $value]);
                    }
                }
            }
            if (in_array('settings', $sections, true)) {
                // eingespielte Ordner müssen sich vertragen – sonst alles zurücknehmen (Transaktion)
                $settings = app(Settings::class);
                $settings->set([]); // Zwischenspeicher leeren
                if ($problems = (new Settings)->pathProblems()) {
                    throw new RuntimeException('Sicherung nicht eingespielt – Ordner passen nicht: '.implode(' ', $problems));
                }
            }
            if (in_array('genres', $sections, true)) {
                $this->writeGenres($data['genres'] ?? []);
            }
            if (in_array('genre_mappings', $sections, true)) {
                GenreMapping::query()->delete();
                foreach ($data['genre_mappings'] ?? [] as $m) {
                    GenreMapping::create(['source' => $m['source'], 'target' => $m['target'] ?? null]);
                }
            }
            if (in_array('artists', $sections, true)) {
                ArtistAlias::query()->delete();
                Artist::query()->delete();
                foreach ($data['artists'] ?? [] as $a) {
                    $artist = Artist::create(collect($a)->only(['name', 'base_name', 'suffix', 'disambiguation', 'mbid', 'discogs_id', 'default_genre', 'image'])->all());
                    foreach ($a['aliases'] ?? [] as $alias) {
                        $source = is_array($alias) ? ($alias['source'] ?? null) : null;
                        $alias = is_array($alias) ? (string) ($alias['alias'] ?? '') : (string) $alias;
                        if ($alias !== '' && ! ArtistAlias::where('alias', $alias)->exists()) {
                            $artist->aliases()->create(['alias' => $alias, 'source' => $source]);
                        }
                    }
                    foreach ($a['rejected_aliases'] ?? [] as $rejected) {
                        RejectedAlias::remember($artist->id, (string) $rejected);
                    }
                }
                ArtistSplit::query()->delete();
                foreach ($data['artist_splits'] ?? [] as $sp) {
                    if (! empty($sp['name']) && count((array) ($sp['parts'] ?? [])) > 1) {
                        ArtistSplit::remember((string) $sp['name'], (array) $sp['parts']);
                    }
                }
            }
            if (in_array('disc_sets', $sections, true)) {
                DiscSet::query()->delete();
                foreach ($data['disc_sets'] ?? [] as $members) {
                    DiscSet::create(['members' => $members]);
                }
            }
            if (in_array('duplicate_exceptions', $sections, true)) {
                DuplicateException::query()->delete();
                foreach ($data['duplicate_exceptions'] ?? [] as $e) {
                    DuplicateException::create(['song_key' => $e['song_key'], 'members' => $e['members'], 'label' => $e['label'] ?? null]);
                }
                CleanupException::query()->delete();
                foreach ($data['cleanup_exceptions'] ?? [] as $e) {
                    CleanupException::create(['check' => $e['check'], 'item_key' => $e['item_key'], 'label' => $e['label'] ?? null]);
                }
            }
        });
        $this->afterChange();
    }

    /**
     * Werkszustand: Datenbank komplett neu aufbauen (alle Tabellen, auch Verlauf, Aufräumen, Warteschlange),
     * dazu die app-eigenen Dateien (Cover-Vorschauen, Rückgängig-Sicherungen, Uploads).
     * Musik, Papierkorb-Ordner und Künstlerbilder auf dem Share bleiben unberührt.
     */
    public function factoryReset(): void
    {
        // jede Tabelle leeren (Struktur bleibt) – funktioniert auch, während andere Verbindungen offen sind
        $tables = collect(DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' and name <> 'migrations'"))->pluck('name');
        DB::statement('PRAGMA foreign_keys = OFF');
        try {
            foreach ($tables as $table) {
                DB::table($table)->delete();
            }
            if (DB::selectOne("select name from sqlite_master where name = 'sqlite_sequence'")) {
                DB::table('sqlite_sequence')->delete(); // IDs beginnen wieder bei 1
            }
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
        $this->writeGenres(self::genreTemplate());
        GenreTemplate::addMappings();
        foreach (['covers', 'history', 'tmp', 'livewire-tmp'] as $dir) {
            Storage::deleteDirectory($dir);
        }
        Cache::flush();
        ArtistNames::flushProtected();
        app(ArtistDirectory::class)->flush();
        app()->forgetInstance(Settings::class);
    }

    /** Gewählte Bereiche zurücksetzen. */
    public function reset(array $sections): void
    {
        DB::transaction(function () use ($sections) {
            if (in_array('settings', $sections, true)) {
                Setting::query()->delete();
            }
            if (in_array('genres', $sections, true)) {
                $this->writeGenres(self::genreTemplate());
            }
            if (in_array('genre_mappings', $sections, true)) {
                GenreMapping::query()->delete();
                GenreTemplate::addMappings();
            }
            if (in_array('artists', $sections, true)) {
                ArtistAlias::query()->delete();
                Artist::query()->delete();
                ArtistSplit::query()->delete();
            }
            if (in_array('disc_sets', $sections, true)) {
                DiscSet::query()->delete();
            }
            if (in_array('duplicate_exceptions', $sections, true)) {
                DuplicateException::query()->delete();
                CleanupException::query()->delete();
            }
            if (in_array('index', $sections, true)) {
                Track::query()->delete();
                Album::query()->delete();
                Leftover::query()->delete();
            }
        });
        if (in_array('index', $sections, true)) {
            Storage::deleteDirectory('covers');
            Cache::forget(Scanner::STATUS_KEY);
        }
        $this->afterChange();
    }

    private function afterChange(): void
    {
        app(Settings::class)->set([]); // gemeinsame Instanz: Zwischenspeicher leeren (Tabelle direkt geändert)
        app(ArtistDirectory::class)->flush();
        ArtistNames::flushProtected();
        // Mehrfach-CDs neu bilden, falls sich Zuordnungen geändert haben
        app(Scanner::class)->rebuildAlbums('inbox');
    }

    private function writeGenres(array $tree): void
    {
        GenreMapping::query()->whereNotIn('target', collect($tree)->flatMap(fn ($g) => [$g['name'], ...($g['children'] ?? [])])->all())->update(['target' => null]);
        Genre::query()->whereNotNull('parent_id')->delete();
        Genre::query()->delete();
        foreach (array_values($tree) as $pos => $g) {
            $main = Genre::create(['name' => $g['name'], 'position' => $pos]);
            foreach (array_values($g['children'] ?? []) as $i => $child) {
                Genre::create(['name' => $child, 'parent_id' => $main->id, 'position' => $i]);
            }
        }
    }

    /** Vorlage der Genre-Liste. */
    public static function genreTemplate(): array
    {
        return GenreTemplate::tree();
    }
}
