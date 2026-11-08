<?php

namespace App\Support;

use App\Models\Album;
use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Models\CleanupException;
use App\Models\DuplicateException;
use App\Models\GenreMapping;
use App\Models\HistoryEntry;
use App\Models\Leftover;
use App\Models\Track;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Aufräum-Prüfungen auf den Scan-Daten in SQLite – nur für den Bestand; die Inbox wird beim Einordnen bereinigt.
 * Jede Prüfung liefert Funde; behoben wird erst nach Klick
 * (über eine Vorschau und den Verlauf, also rückgängig machbar).
 *
 * Fund: ['id', 'title', 'detail', 'link' => ?url, 'actions' => [...]]
 */
class CleanupChecks
{
    private ?Collection $tracks = null;

    private ?Collection $albums = null;

    private array $exceptionCache = [];

    /** @var array<string, array{genre:string, reason:string}|null> Genre-Vorschlag je Künstlername */
    private array $genreHints = [];

    public function __construct(
        private Settings $settings,
        private PathBuilder $paths,
        private ArtistDirectory $directory,
        private Genres $genres,
    ) {}

    /** Prüfungen in sinnvoller Arbeitsreihenfolge (Schritt 1 → 5). */
    public static function definitions(): array
    {
        return [
            'broken' => ['Defekte Dateien', 'Ohne Audiodaten (Länge 0) oder nicht lesbar – z. B. abgebrochene Rips.', 'In den Papierkorb'],
            'duplicates' => ['Mögliche Duplikate', 'Gleicher Interpret, Titel und Länge, „(2)“-Dateien, gleiche Aufnahme als MP3 und FLAC.', 'Beste Version behalten, Rest in den Papierkorb'],
            'extension' => ['Uneinheitliche Endungen', 'z. B. „.Mp3“ statt „.mp3“.', 'Endung klein schreiben'],
            'ambiguous' => ['Gleichnamige Künstler ohne Zuordnung', 'Der Name gibt es mehrfach (z. B. „Adam [DE]“ / „Adam [GB]“) – diese Lieder sind noch keiner Person zugeordnet.', null],
            'combined_manual' => ['Kombi mit &, und, and, Komma', 'Wird nie automatisch getrennt – im Künstlerverzeichnis entscheiden.', null],
            'spelling' => ['Uneinheitliche Interpreten-Schreibweisen', 'Namen, die laut Künstlerverzeichnis anders geschrieben werden.', 'Richtige Schreibweise schreiben'],
            'combined' => ['Kombi-Interpreten ohne ARTISTS', 'z. B. „Yanou feat. Liz“ ohne einzelne ARTISTS-Einträge.', 'ARTISTS erzeugen'],
            'album_mixed' => ['Album mit uneinheitlichem Album-Interpret oder Album-Namen', 'Tracks eines Ordners haben verschiedene Werte.', 'Häufigsten Wert fürs ganze Album setzen'],
            'sampler_flags' => ['Sampler ohne „Various Artists“ oder Compilation-Flag', 'Alben in Compilation-Kategorien.', 'Tags setzen'],
            'comment' => ['Kategorie-Kommentar fehlt oder passt nicht zum Ablageort', 'Kommentar-Tag ≠ Kategorie des Ordners.', 'Kommentar setzen'],
            'missing_tags' => ['Fehlende Tags', 'Interpret, Album oder Tracknummer fehlt.', null],
            'genre_unlisted' => ['Genres nicht auf der Liste', 'Behebung über die Genre-Zuordnung.', 'Zugeordnetes Genre schreiben'],
            'genre_mixed' => ['Alben mit gemischten Genres', 'Tracks eines Albums (kein Sampler) haben verschiedene Genres.', 'Häufigstes Genre fürs ganze Album'],
            'genre_missing' => ['Alben ohne Genre', 'Kein Genre im Tag – Vorschlag aus dem Standard-Genre des Künstlers bzw. seinen anderen Liedern.', 'Vorgeschlagenes Genre schreiben'],
            'electronic_sub' => ['Electronic ohne Unterstil', 'Electronic-Alben brauchen einen Unterstil (z. B. Trance) – oder „Electronic reicht hier“.', null],
            'misplaced' => ['Datei liegt nicht am Regelort', 'Pfad weicht vom Namensschema der Kategorie ab.', 'An den richtigen Ort verschieben'],
            'leftovers' => ['Leere Ordner und Reste', 'Leere Ordner, „-Interpret@1-“, .DS_Store.', 'Entfernen'],
            'cover' => ['Fehlendes oder sehr kleines Cover', 'Kein Cover bzw. kleiner als die eingestellte Größe.', null],
            'cover_embed' => ['Cover nicht in allen Dateien', 'Ein Cover ist da (in anderen Tracks oder als cover.jpg), fehlt aber in einzelnen Dateien.', 'Cover einbetten'],
            'artist_image' => ['Künstler ohne Bild', 'Einträge im Künstlerverzeichnis ohne Bild.', null],
        ];
    }

    /** Schritte: Reihenfolge, in der aufgeräumt werden sollte. */
    public const STEPS = [
        1 => ['Dateien', 'Defektes und Doppeltes zuerst aussortieren', ['broken', 'duplicates', 'extension']],
        2 => ['Künstler & Tags', 'Schreibweisen und Tags vereinheitlichen – die Ordner richten sich danach', ['ambiguous', 'combined_manual', 'spelling', 'combined', 'album_mixed', 'sampler_flags', 'comment', 'missing_tags']],
        3 => ['Genres', 'Genre-Liste anwenden', ['genre_unlisted', 'genre_mixed', 'genre_missing', 'electronic_sub']],
        4 => ['Ablage', 'Erst jetzt an den Regelort verschieben', ['misplaced']],
        5 => ['Abschluss', 'Reste, Cover, Künstlerbilder', ['leftovers', 'cover', 'cover_embed', 'artist_image']],
    ];

    public static function stepOf(string $check): int
    {
        foreach (self::STEPS as $n => [, , $checks]) {
            if (in_array($check, $checks, true)) {
                return $n;
            }
        }

        return 5;
    }

    /**
     * Offene, behebbare Funde in früheren Schritten (für den Hinweis vor dem Verschieben).
     *
     * @return array<string, int> check => Anzahl
     */
    public function openBefore(string $check): array
    {
        $step = self::stepOf($check);
        $counts = $this->counts();
        $out = [];
        foreach (self::STEPS as $n => [, , $checks]) {
            if ($n >= $step) {
                break;
            }
            foreach ($checks as $c) {
                if (($counts[$c] ?? 0) > 0 && ! in_array($c, ['missing_tags', 'combined_manual'], true)) {
                    $out[$c] = $counts[$c];
                }
            }
        }

        return $out;
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return Cache::remember('cleanup.counts.'.$this->version(), now()->addHour(), function () {
            $out = [];
            foreach (array_keys(self::definitions()) as $key) {
                // ausgeblendete Funde nicht mitzählen (auch wenn die Liste der Ausnahmen gerade aufgeklappt ist)
                $out[$key] = count(array_filter($this->findings($key), fn ($f) => ! ($f['dismissed'] ?? false)));
            }

            return $out;
        });
    }

    private ?string $version = null;

    public function version(): string
    {
        // pro Instanz gemerkt: eine Instanz lebt nur für einen Render bzw. einen Automatik-Schritt
        return $this->version ??= md5(implode('|', [filemtime(__FILE__), Track::count(), Track::max('id'), DuplicateException::count(), DuplicateException::max('id'), CleanupException::count(), CleanupException::max('id'), Track::max('updated_at'), HistoryEntry::max('id'), Album::max('updated_at'), Leftover::count(), Leftover::max('updated_at'),
            ArtistAlias::max('updated_at'), Artist::max('updated_at'), Artist::count(), GenreMapping::max('updated_at'), json_encode($this->settings->all())]));
    }

    public function findings(string $key): array
    {
        $method = 'check'.str_replace('_', '', ucwords($key, '_'));
        if (! method_exists($this, $method)) {
            return [];
        }

        // Funde gehen über den ganzen Bestand (und teils aufs Share): nur bei geändertem Datenstand neu berechnen
        return Cache::remember('cleanup.findings.'.$key.'.'.(int) $this->withDismissed.'.'.$this->version(), now()->addHour(), fn () => $this->{$method}());
    }

    // ---------------------------------------------------------------- Daten

    private function tracks(): Collection
    {
        return $this->tracks ??= Track::where('location', 'library')->get();
    }

    private function albums(): Collection
    {
        return $this->albums ??= Album::where('location', 'library')->get()->keyBy('id');
    }

    private function byAlbum(): Collection
    {
        return $this->tracks()->groupBy('album_id');
    }

    private function rel(string $path): string
    {
        $base = $this->settings->base().'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    private function albumTitle(?Album $album): string
    {
        return $album ? trim(($album->albumartist ? $album->albumartist.' – ' : '').$album->title) : '?';
    }

    private function link(?Album $album): ?string
    {
        return $album ? route('library.album', $album) : null;
    }

    private function applyActions(Collection $tracks, callable $newTags): array
    {
        $actions = [];
        foreach ($tracks as $t) {
            if (! $t->writable) {
                continue;
            }
            $tags = $newTags($t);
            if ($tags) {
                $actions[] = ['type' => 'apply', 'path' => $t->path, 'tags' => $tags];
            }
        }

        return $actions;
    }

    // ---------------------------------------------------------------- Prüfungen

    private function checkMisplaced(): array
    {
        $out = [];
        foreach ($this->byAlbum() as $albumId => $tracks) {
            $album = $this->albums()[$albumId] ?? null;
            $category = $this->settings->category($album?->category);
            if (! $category) {
                continue;
            }
            $multi = PathBuilder::isMultiDisc($tracks->map->tags()->all());
            $moves = [];
            $retagged = [];
            foreach ($tracks as $t) {
                if (trim((string) $t->artist) === '' && ! $category['compilation']) {
                    continue;
                }
                $tags = $t->tags();
                $fix = $category['compilation'] ? [] : $this->unifyArtistSpelling($tags);
                // "Kein Album": Track- und CD-Nummern leeren (auch im Dateinamen)
                $fix += app(AlbumPlanner::class)->diff($tags, AlbumPlanner::clearNumbersWithoutAlbum($tags));
                $target = $this->paths->target($category, array_merge($tags, $fix), $multi, $t->path);
                if ($fix) {
                    $retagged[PathBuilder::folderArtist($tags)] = PathBuilder::folderArtist(array_merge($tags, $fix));
                    $moves[] = ['type' => 'apply', 'path' => $t->path, 'tags' => $fix, 'target' => $target !== $t->path ? $target : null];
                } elseif ($target !== $t->path && ! self::isKeptVariant($t->path, $target)) {
                    $moves[] = ['type' => 'move', 'path' => $t->path, 'target' => $target];
                }
            }
            $moves = array_map(fn ($m) => array_filter($m, fn ($v) => $v !== null), $moves);
            if (! $moves) {
                continue;
            }
            $withTarget = array_filter($moves, fn ($m) => ! empty($m['target']));
            $dirs = array_unique(array_map(fn ($m) => Scanner::albumDir(dirname($m['target'])), $withTarget));
            if ($withTarget && count($withTarget) === $tracks->count() && count($dirs) === 1 && reset($dirs) !== $album->dir) {
                $moves[] = ['type' => 'album_files', 'from_dir' => $album->dir, 'to_dir' => reset($dirs)];
            }
            $first = reset($withTarget) ?: $moves[0];
            $detail = count($withTarget).' Datei(en)'.(! empty($first['target']) ? ': '.$this->rel($first['path']).' → '.$this->rel($first['target']) : '');
            $cleared = count(array_filter($moves, fn ($m) => array_key_exists('track', $m['tags'] ?? []) || array_key_exists('disc', $m['tags'] ?? [])));
            if ($cleared) {
                $detail .= "\n".$cleared.' Einzellied(er): Track- und CD-Nummer leeren („'.$this->settings->get('no_album').'“)';
            }
            if ($retagged) {
                $detail .= "\nSchreibweise vereinheitlichen (Tag + Ordner): ".implode(', ', array_map(fn ($a, $b) => '„'.$a.'“ → „'.$b.'“', array_keys($retagged), $retagged));
            }
            $out[] = [
                'id' => 'a'.$albumId,
                'title' => $this->albumTitle($album),
                'detail' => $detail,
                'link' => $this->link($album),
                'actions' => $moves,
            ];
        }

        return $out;
    }

    /**
     * Ordner = Künstler im Tag. Weicht die Schreibweise nur in Groß/klein bzw. Unicode-Form von der einheitlichen ab
     * ("Atb" statt "ATB"), müssen die Tags angeglichen werden – zwei solche Ordner kann es auf dem Share nicht geben.
     *
     * @return array geänderte Tags (leer, wenn alles passt)
     */
    private function unifyArtistSpelling(array $tags): array
    {
        $current = PathBuilder::folderArtist($tags);
        if ($current === '') {
            return [];
        }
        $wanted = $this->paths->folderName($current);
        if ($wanted === $current || mb_strtolower(FileOps::nfc($wanted)) !== mb_strtolower(FileOps::nfc($current))) {
            return []; // gleich, oder andere Schreibweise im Verzeichnis (das regelt "Uneinheitliche Schreibweisen")
        }
        $same = fn ($n) => mb_strtolower(FileOps::nfc((string) $n)) === mb_strtolower(FileOps::nfc($current));
        $replaceText = fn (?string $text) => implode('', array_map(fn ($part) => $same(trim($part)) ? str_replace(trim($part), $wanted, $part) : $part,
            preg_split('/(\s*;\s*|\s+(?:feat\.?|ft\.|featuring|vs\.?|versus)\s+)/iu', (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE)));
        $fix = [];
        foreach (['artist', 'albumartist'] as $k) {
            $new = $replaceText($tags[$k] ?? '');
            if ($new !== (string) ($tags[$k] ?? '')) {
                $fix[$k] = $new;
            }
        }
        foreach (['artists', 'albumartists'] as $k) {
            $list = array_map(fn ($n) => $same($n) ? $wanted : $n, (array) ($tags[$k] ?? []));
            if ($list !== array_values((array) ($tags[$k] ?? []))) {
                $fix[$k] = $list;
            }
        }

        return $fix;
    }

    /**
     * Liegt die Datei als bewusst behaltene zweite Fassung ("Titel (2).mp3") direkt neben dem belegten Ziel?
     * Dann ist sie richtig abgelegt – sonst würde sie bei jedem Lauf zwischen "(2)" und "(3)" wandern.
     */
    public static function isKeptVariant(string $path, string $target): bool
    {
        if (strcasecmp(FileOps::nfc(dirname($path)), FileOps::nfc(dirname($target))) !== 0 || ! file_exists($target) || FileOps::sameFile($path, $target)) {
            return false;
        }
        $base = preg_quote(FileOps::nfc(pathinfo($target, PATHINFO_FILENAME)), '/');
        $ext = preg_quote(pathinfo($target, PATHINFO_EXTENSION), '/');

        return (bool) preg_match('/^'.$base.' \(\d+\)\.'.$ext.'$/iu', FileOps::nfc(basename($path)));
    }

    private function checkSpelling(): array
    {
        $out = [];
        foreach ($this->byAlbum() as $albumId => $tracks) {
            $changes = [];
            $actions = $this->applyActions($tracks, function (Track $t) use (&$changes) {
                $tags = [];
                $artist = $this->directory->correctText((string) $t->artist);
                if ($artist !== (string) $t->artist) {
                    $tags['artist'] = $artist;
                    $tags['artists'] = $this->directory->resolveNames($artist, (array) ($t->artists ?? []))['names'];
                    $changes[$t->artist] = $artist;
                }
                $aa = $this->directory->correctText((string) $t->albumartist);
                if ($aa !== (string) $t->albumartist) {
                    $tags['albumartist'] = $aa;
                    $tags['albumartists'] = $this->directory->resolveNames($aa, (array) ($t->albumartists ?? []))['names'];
                    $changes[$t->albumartist] = $aa;
                }

                return $tags;
            });
            if ($actions) {
                $album = $this->albums()[$albumId] ?? null;
                $out[] = ['id' => 'a'.$albumId, 'title' => $this->albumTitle($album),
                    'detail' => implode(' · ', array_map(fn ($a, $b) => $a.' → '.$b, array_keys($changes), $changes)), 'link' => $this->link($album), 'actions' => $actions];
            }
        }

        return $out;
    }

    private function checkCombined(): array
    {
        $out = [];
        foreach ($this->byAlbum() as $albumId => $tracks) {
            $examples = [];
            $actions = $this->applyActions($tracks, function (Track $t) use (&$examples) {
                $tags = [];
                $r = $this->directory->resolveNames($t->artist, (array) ($t->artists ?? []));
                $split = $r['names'];
                if (! $r['unresolved'] && count($split) > 1 && array_values($t->artists ?? []) !== $split) {
                    $tags['artists'] = $split;
                    $examples[$t->artist] = implode(' | ', $split);
                }
                $r = $this->directory->resolveNames($t->albumartist, (array) ($t->albumartists ?? []));
                $splitAa = $r['names'];
                if (! $r['unresolved'] && count($splitAa) > 1 && array_values($t->albumartists ?? []) !== $splitAa) {
                    $tags['albumartists'] = $splitAa;
                    $examples[$t->albumartist] = implode(' | ', $splitAa);
                }

                return $tags;
            });
            if ($actions) {
                $album = $this->albums()[$albumId] ?? null;
                $out[] = ['id' => 'a'.$albumId, 'title' => $this->albumTitle($album),
                    'detail' => implode(' · ', array_slice(array_map(fn ($a, $b) => $a.' → '.$b, array_keys($examples), $examples), 0, 3)), 'link' => $this->link($album), 'actions' => $actions];
            }
        }

        return $out;
    }

    private function checkAmbiguous(): array
    {
        $out = [];
        foreach ($this->tracks()->groupBy('album_id') as $albumId => $tracks) {
            $names = [];
            foreach ($tracks as $t) {
                $r1 = $this->directory->resolveNames($t->artist, (array) ($t->artists ?? []));
                $r2 = $this->directory->resolveNames($t->albumartist, (array) ($t->albumartists ?? []));
                foreach ([...$r1['unresolved'], ...$r2['unresolved']] as $n) {
                    $names[$n] = ($names[$n] ?? 0) + 1;
                }
            }
            if (! $names) {
                continue;
            }
            $album = $this->albums()[$albumId] ?? null;
            $base = array_key_first($names);
            $person = $this->directory->personsFor($base)[0] ?? null;
            $out[] = [
                'id' => 'a'.$albumId,
                'title' => $this->albumTitle($album),
                'detail' => implode(' · ', array_map(fn ($n, $c) => '„'.$n.'“ in '.$c.' Track(s)', array_keys($names), $names)),
                'link' => $person ? route('artists', ['selected' => $person->id]) : $this->link($album),
                'actions' => [],
            ];
        }

        return $out;
    }

    private function checkCombinedManual(): array
    {
        $names = [];
        foreach ($this->tracks() as $t) {
            foreach (array_merge(ArtistNames::split($t->artist), ArtistNames::split($t->albumartist)) as $n) {
                if (ArtistNames::looksCombined($n) && $this->directory->check($n)['status'] === 'new') {
                    $names[$n] = ($names[$n] ?? 0) + 1;
                }
            }
        }
        arsort($names);
        $out = [];
        foreach ($names as $n => $count) {
            $out[] = ['id' => 'n'.md5($n), 'title' => $n, 'detail' => $count.' Datei(en) – als ein Künstler übernehmen oder Schreibweise mit „;“/„feat.“ korrigieren', 'link' => route('artists', ['tab' => 'unknown', 'q' => $n]), 'actions' => []];
        }

        return $out;
    }

    private function checkAlbumMixed(): array
    {
        $out = [];
        foreach ($this->byAlbum() as $albumId => $tracks) {
            $album = $this->albums()[$albumId] ?? null;
            $titles = $tracks->pluck('album')->map(fn ($v) => (string) $v)->countBy()->sortDesc();
            $aas = $tracks->pluck('albumartist')->map(fn ($v) => (string) $v)->countBy()->sortDesc();
            if ($titles->count() <= 1 && $aas->count() <= 1) {
                continue;
            }
            // "Kein Album"-Ordner mit Einzeltiteln sind gewollt uneinheitlich
            if (basename($album?->dir ?? '') === $this->settings->get('no_album')) {
                continue;
            }
            $title = (string) $titles->keys()->first();
            $aa = (string) $aas->keys()->first();
            $actions = $this->applyActions($tracks, function (Track $t) use ($title, $aa, $titles, $aas) {
                $tags = [];
                if ($titles->count() > 1 && (string) $t->album !== $title) {
                    $tags['album'] = $title;
                }
                if ($aas->count() > 1 && (string) $t->albumartist !== $aa) {
                    $tags['albumartist'] = $aa;
                    $tags['albumartists'] = ArtistNames::split($aa);
                }

                return $tags;
            });
            $detail = [];
            if ($titles->count() > 1) {
                $detail[] = 'Album: '.$titles->keys()->map(fn ($k) => $k === '' ? '∅' : $k)->implode(' / ');
            }
            if ($aas->count() > 1) {
                $detail[] = 'Album-Interpret: '.$aas->keys()->map(fn ($k) => $k === '' ? '∅' : $k)->implode(' / ');
            }
            $out[] = ['id' => 'a'.$albumId, 'title' => $this->albumTitle($album), 'detail' => implode(' · ', $detail), 'link' => $this->link($album), 'actions' => $actions];
        }

        return $out;
    }

    private function checkSamplerFlags(): array
    {
        $va = (string) $this->settings->get('compilation_artist');
        $out = [];
        foreach ($this->byAlbum() as $albumId => $tracks) {
            $album = $this->albums()[$albumId] ?? null;
            if (! ($this->settings->category($album?->category)['compilation'] ?? false)) {
                continue;
            }
            $actions = $this->applyActions($tracks, function (Track $t) use ($va) {
                $tags = [];
                if ((string) $t->albumartist !== $va) {
                    $tags['albumartist'] = $va;
                    $tags['albumartists'] = [$va];
                }
                if (! $t->compilation) {
                    $tags['compilation'] = true;
                }

                return $tags;
            });
            if ($actions) {
                $out[] = ['id' => 'a'.$albumId, 'title' => $this->albumTitle($album), 'detail' => count($actions).' Track(s) ohne „'.$va.'“ bzw. Flag', 'link' => $this->link($album), 'actions' => $actions];
            }
        }

        return $out;
    }

    private function checkComment(): array
    {
        $out = [];
        foreach ($this->byAlbum() as $albumId => $tracks) {
            $album = $this->albums()[$albumId] ?? null;
            $category = $this->settings->category($album?->category);
            if (! $category) {
                continue;
            }
            $wrong = $tracks->filter(fn ($t) => (string) $t->comment !== $category['name']);
            if ($wrong->isEmpty()) {
                continue;
            }
            $actions = $this->applyActions($wrong, fn ($t) => ['comment' => $category['name']] + ((bool) $t->compilation !== $category['compilation'] ? ['compilation' => $category['compilation']] : []));
            $values = $wrong->pluck('comment')->map(fn ($c) => $c ?: '∅')->unique()->implode(', ');
            $out[] = ['id' => 'a'.$albumId, 'title' => $this->albumTitle($album), 'detail' => $wrong->count().' Track(s): '.$values.' → '.$category['name'], 'link' => $this->link($album), 'actions' => $actions];
        }

        return $out;
    }

    private function checkBroken(): array
    {
        return $this->tracks()->filter(fn (Track $t) => $t->duration === null || $t->duration < 0.1)->sortBy('path')
            ->map(fn (Track $t) => [
                'id' => 't'.$t->id,
                'title' => $this->rel($t->path),
                'detail' => 'nur '.number_format($t->size / 1024, 0, ',', '.').' KB, keine abspielbaren Audiodaten',
                'link' => $t->album_id ? route('library.album', $t->album_id) : null,
                'actions' => [['type' => 'trash', 'path' => $t->path]],
            ])->values()->all();
    }

    private function checkMissingTags(): array
    {
        $out = [];
        foreach ($this->byAlbum() as $albumId => $tracks) {
            $missing = [];
            $album = $this->albums()[$albumId] ?? null;
            // Einzelner Track bzw. "Kein Album": Tracknummer braucht es nicht (dort wird sie sogar geleert)
            $needsNumber = count($tracks) > 1 && ! ($album && $this->isLooseFolder($album));
            foreach ($tracks as $t) {
                if (trim((string) $t->artist) === '') {
                    $missing['Interpret'] = ($missing['Interpret'] ?? 0) + 1;
                }
                if (trim((string) $t->album) === '') {
                    $missing['Album'] = ($missing['Album'] ?? 0) + 1;
                }
                if ($needsNumber && trim((string) $t->track) === '') {
                    $missing['Tracknummer'] = ($missing['Tracknummer'] ?? 0) + 1;
                }
            }
            if ($missing) {
                $out[] = ['id' => 'a'.$albumId, 'title' => $this->albumTitle($album), 'detail' => implode(' · ', array_map(fn ($k, $n) => $k.' fehlt ('.$n.')', array_keys($missing), $missing)), 'link' => $this->link($album), 'actions' => []];
            }
        }

        return $out;
    }

    private function checkCover(): array
    {
        $min = (int) $this->settings->get('small_cover_px', 300);
        $out = [];
        foreach ($this->albums() as $album) {
            if ($this->isLooseFolder($album)) {
                continue; // "Kein Album": kein gemeinsames Cover
            }
            if (! $album->hasCover()) {
                $out[] = ['id' => 'a'.$album->id, 'title' => $this->albumTitle($album), 'detail' => 'kein Cover', 'link' => $this->link($album), 'actions' => []];
            } elseif ($album->cover_size && $album->cover_size < $min) {
                $out[] = ['id' => 'a'.$album->id, 'title' => $this->albumTitle($album), 'detail' => 'Cover nur '.$album->cover_size.' px', 'link' => $this->link($album), 'actions' => []];
            }
        }

        return $out;
    }

    private function checkCoverEmbed(): array
    {
        $out = [];
        foreach ($this->tracks()->groupBy('album_id') as $albumId => $tracks) {
            $album = $this->albums()[$albumId] ?? null;
            if (! $album || $this->isLooseFolder($album)) {
                continue;
            }
            $missing = Covers::missingEmbedded($tracks);
            $source = Covers::embedSource($album, $tracks);
            if (! $missing || ! $source) {
                continue;
            }
            $from = in_array(strtolower(pathinfo($source, PATHINFO_EXTENSION)), config('musicwiz.audio_extensions'), true)
                ? 'aus „'.basename($source).'“' : 'aus '.basename($source);
            $out[] = [
                'id' => 'a'.$album->id,
                'title' => $this->albumTitle($album),
                'detail' => count($missing).' von '.$tracks->count().' Tracks ohne eingebettetes Cover – Cover '.$from,
                'link' => $this->link($album),
                'actions' => array_map(fn ($t) => ['type' => 'apply', 'path' => $t->path, 'embed_from' => $source], $missing),
            ];
        }

        return $out;
    }

    private function checkDuplicates(): array
    {
        $norm = fn ($s) => preg_replace('/[^a-z0-9]+/', '', strtolower(ArtistNames::stripAccents((string) $s)));
        $groups = [];
        foreach ($this->tracks() as $t) {
            if (! $t->artist || ! $t->title || ! $t->duration) {
                continue;
            }
            $key = ArtistNames::compareKey($t->artist).'|'.$norm($t->title).'|'.(int) round($t->duration / 2);
            $groups[$key][] = $t;
        }
        // Dateien mit "(2)" im Namen neben dem Original
        foreach ($this->tracks() as $t) {
            if (preg_match('/^(.*?)\s*\(\d\)\.(\w+)$/', $t->filename, $m)) {
                $orig = $t->dir.'/'.$m[1].'.'.$m[2];
                $other = $this->tracks()->firstWhere('path', $orig);
                if ($other && abs(($other->duration ?? 0) - ($t->duration ?? 0)) <= Quality::LENGTH_TOLERANCE) {
                    $groups['n|'.$orig] = [$other, $t];
                }
            }
        }
        $out = [];
        $seen = [];
        foreach ($groups as $key => $list) {
            if (count($list) < 2) {
                continue;
            }
            $ids = collect($list)->pluck('id')->sort()->implode(',');
            if (isset($seen[$ids])) {
                continue;
            }
            $seen[$ids] = true;
            $songKey = self::songKey($list[0]);
            $fingerprints = collect($list)->map(fn ($t) => self::fingerprint($t))->sort()->values()->all();
            $exception = $this->duplicateExceptions()[$songKey] ?? [];
            if (collect($exception)->contains(fn ($members) => self::isSubMultiset($fingerprints, $members))) {
                if ($this->withDismissed) {
                    $out[] = ['id' => 'x'.md5($ids), 'title' => $list[0]->artist.' – '.$list[0]->title, 'detail' => 'als verschieden markiert', 'link' => null,
                        'actions' => [], 'choosable' => false, 'dismissed' => true, 'song_key' => $songKey, 'fingerprints' => $fingerprints,
                        'options' => collect($list)->map(fn ($t) => ['path' => $t->path, 'label' => $this->rel($t->path), 'id' => $t->id, 'seconds' => $t->duration,
                            'title' => $t->title, 'artist' => $t->artist, 'album' => $t->album, 'icon' => '•', 'role' => 'bleibt (verschieden)',
                            'info' => $this->qualityLabel($t).', '.$t->durationLabel()])->values()->all()];
                }

                continue; // bewusst als verschieden markiert
            }
            $plan = $this->planDuplicateGroup(collect($list));
            if (! $plan['actions'] && ! $plan['note']) {
                continue; // z. B. dasselbe Lied auf Single und Best-of: gewollt, jedes Album bleibt vollständig
            }
            $keep = $plan['keep'];
            $out[] = [
                'id' => 'd'.md5($ids),
                'title' => $keep->artist.' – '.$keep->title,
                'detail' => collect($plan['roles'])->map(fn ($r) => $r['icon'].' '.$this->rel($r['track']->path).' ('.$r['track']->ext.') – '.$r['text'])->implode("\n")
                    .($plan['note'] ? "\n".$plan['note'] : ''),
                'link' => $this->link($this->albums()[$keep->album_id] ?? null),
                'actions' => $plan['actions'],
                'choosable' => $plan['choosable'],
                'song_key' => $songKey,
                'fingerprints' => $fingerprints,
                'default_keep' => $plan['keep']->path,
                'options' => collect($plan['roles'])->map(fn ($r) => ['path' => $r['track']->path, 'label' => $this->rel($r['track']->path), 'id' => $r['track']->id,
                    'seconds' => $r['track']->duration, 'title' => $r['track']->title, 'artist' => $r['track']->artist, 'album' => $r['track']->album,
                    'icon' => $r['icon'], 'role' => $r['text'],
                    'info' => $this->qualityLabel($r['track']).', '.$r['track']->durationLabel()])->values()->all(),
            ];
        }

        return $out;
    }

    /** Ausgeblendete ("verschieden") Duplikate zusätzlich liefern – für die Anzeige zum Wieder-Einblenden. */
    public bool $withDismissed = false;

    private ?array $exceptions = null;

    /** @return array<string, list<list<string>>> */
    private function duplicateExceptions(): array
    {
        if ($this->exceptions === null) {
            $this->exceptions = [];
            foreach (DuplicateException::all() as $e) {
                $this->exceptions[$e->song_key][] = $e->members;
            }
        }

        return $this->exceptions;
    }

    /** Sind alle Fassungen (mit Anzahl) in der Markierung enthalten? Eine zusätzliche Kopie lässt den Fund wieder erscheinen. */
    public static function isSubMultiset(array $items, array $of): bool
    {
        $have = array_count_values($of);
        foreach (array_count_values($items) as $item => $n) {
            if (($have[$item] ?? 0) < $n) {
                return false;
            }
        }

        return true;
    }

    public static function songKey(Track $t): string
    {
        return ArtistNames::compareKey($t->artist).'|'.preg_replace('/[^a-z0-9]+/', '', strtolower(ArtistNames::stripAccents((string) $t->title)));
    }

    /** Fingerabdruck einer Fassung, der Verschieben und Tag-Änderungen übersteht. */
    public static function fingerprint(Track $t): string
    {
        // FLAC & Co.: Bitrate hängt von Tags/Cover ab – nur Format und Länge
        $lossless = in_array($t->ext, ['flac', 'wav', 'aiff', 'alac'], true);

        return $t->ext.'|'.($lossless ? '-' : (int) round(($t->bitrate ?? 0) / 8000)).'|'.number_format((float) $t->duration, 1, '.', '');
    }

    /** "Kein Album"-Ordner: Sammlung von Einzelliedern, kein echtes Album. */
    private function isLooseFolder(?Album $album): bool
    {
        return $album && basename($album->dir) === $this->settings->get('no_album');
    }

    /** Liegt der Track in einem echten Album (nicht "Kein Album", Albumname vorhanden)? */
    private function inAlbum(Track $t): bool
    {
        return trim((string) $t->album) !== '' && basename($t->dir) !== $this->settings->get('no_album');
    }

    /** Rangfolge ohne Dateizugriff: intakt, verlustfrei, Bitrate (nur verlustbehaftet), ohne "(2)". */
    private function qualityRank(Track $t): array
    {
        $lossless = in_array($t->ext, ['flac', 'wav', 'aiff', 'alac'], true);

        return [($t->duration ?? 0) >= 0.1 ? 1 : 0, $lossless ? 1 : 0, $lossless ? 0 : (int) round(($t->bitrate ?? 0) / 16000), preg_match('/\(\d\)\.\w+$/', $t->filename) ? 0 : 1];
    }

    private function qualityLabel(Track $t): string
    {
        if (($t->duration ?? 0) < 0.1) {
            return 'defekt';
        }

        return strtoupper($t->ext).(in_array($t->ext, ['flac', 'wav', 'aiff'], true) ? '' : ', '.round(($t->bitrate ?? 0) / 1000).' kbit/s');
    }

    /**
     * Duplikate auflösen, ohne Alben zu "zerschießen": Jedes Album behält seinen Track.
     *  - Kopien in "Kein Album" fliegen raus, wenn die Album-Fassung mindestens gleich gut ist.
     *  - Ist die lose Kopie besser, ersetzt sie den Album-Track (Upgrade, mit dessen Tags an dessen Platz).
     *  - Dasselbe Lied in mehreren Alben bleibt überall erhalten.
     */
    private function planDuplicateGroup(Collection $list): array
    {
        $better = fn (Track $a, Track $b) => $this->qualityRank($a) <=> $this->qualityRank($b);
        $bestOf = fn ($items) => collect($items)->sort(fn ($a, $b) => $better($b, $a))->first();
        $roles = [];
        $actions = [];
        $note = null;

        $slots = $list->filter(fn ($t) => $this->inAlbum($t))->groupBy('album_id');
        $loose = $list->reject(fn ($t) => $this->inAlbum($t))->values();

        // innerhalb eines Albums doppelt (z. B. "Titel (2).flac" im Albumordner): beste Fassung bleibt im Album
        $slotBest = [];
        foreach ($slots as $albumId => $members) {
            $b = $bestOf($members);
            $slotBest[$albumId] = $b;
            foreach ($members as $m) {
                if ($m->id !== $b->id) {
                    $roles[$m->id] = ['track' => $m, 'icon' => '✗', 'text' => 'zweite Kopie im selben Album → Papierkorb'];
                    $actions[] = ['type' => 'trash', 'path' => $m->path];
                }
            }
        }

        if (! $slots->count()) {
            // nur lose Dateien: die beste bleibt (frei wählbar)
            $keep = $bestOf($loose);
            foreach ($loose as $t) {
                $roles[$t->id] = $t->id === $keep->id ? ['track' => $t, 'icon' => '✓', 'text' => 'bleibt (beste Fassung)'] : ['track' => $t, 'icon' => '✗', 'text' => 'Papierkorb'];
                if ($t->id !== $keep->id) {
                    $actions[] = ['type' => 'trash', 'path' => $t->path];
                }
            }

            return ['keep' => $keep, 'roles' => array_values($roles), 'actions' => $actions, 'note' => null, 'choosable' => true];
        }

        $bestLoose = $loose->count() ? $bestOf($loose) : null;
        $keep = $bestOf($slotBest);
        foreach ($slotBest as $b) {
            $roles[$b->id] = ['track' => $b, 'icon' => '✓', 'text' => 'bleibt im Album'];
        }

        if ($bestLoose && count($slotBest) === 1 && $better($bestLoose, $keep) > 0) {
            if (TagTool::canWrite($bestLoose->path)) {
                // Upgrade: bessere lose Fassung übernimmt Platz und Tags des Album-Tracks
                $tags = $keep->tags();
                $target = preg_replace('/\.[^.\/]+$/', '.'.$bestLoose->ext, $keep->path);
                $diff = app(AlbumPlanner::class)->diff($bestLoose->tags(), $tags);
                $actions[] = ['type' => 'trash', 'path' => $keep->path];
                $actions[] = array_filter(['type' => 'apply', 'path' => $bestLoose->path, 'tags' => $diff, 'target' => $target]);
                $roles[$keep->id] = ['track' => $keep, 'icon' => '✗', 'text' => 'wird durch die bessere Fassung ersetzt → Papierkorb'];
                $roles[$bestLoose->id] = ['track' => $bestLoose, 'icon' => '⬆', 'text' => 'Upgrade: übernimmt Platz und Tags im Album'];
                $keep = $bestLoose;
            } else {
                $roles[$bestLoose->id] = ['track' => $bestLoose, 'icon' => '•', 'text' => 'bessere Fassung, Format nicht beschreibbar – bleibt'];
                $note = 'Bessere Fassung kann nicht ins Album übernommen werden – bitte von Hand entscheiden.';
            }
        } elseif ($bestLoose && count($slotBest) > 1 && $better($bestLoose, $keep) > 0) {
            $roles[$bestLoose->id] = ['track' => $bestLoose, 'icon' => '•', 'text' => 'bessere Fassung, aber mehrere Alben betroffen – bleibt'];
            $note = 'Die lose Fassung ist besser, das Lied steckt aber in mehreren Alben – bitte von Hand entscheiden.';
        }
        foreach ($loose as $t) {
            if (! isset($roles[$t->id])) {
                $roles[$t->id] = ['track' => $t, 'icon' => '✗', 'text' => 'Kopie in „'.$this->settings->get('no_album').'“ → Papierkorb'];
                $actions[] = ['type' => 'trash', 'path' => $t->path];
            }
        }
        if (count($slotBest) > 1) {
            foreach ($slotBest as $b) {
                if ($roles[$b->id]['icon'] === '✓') {
                    $roles[$b->id]['text'] = 'bleibt in „'.$b->album.'“';
                }
            }
        }

        return ['keep' => $keep, 'roles' => array_values($roles), 'actions' => $actions, 'note' => $note, 'choosable' => false];
    }

    private function checkLeftovers(): array
    {
        $labels = ['junk' => 'Rest-Datei', 'empty_dir' => 'leerer Ordner', 'bad_dir' => 'Ordner ohne Interpret', 'no_music' => 'Ordner ohne Musik'];

        return Leftover::orderBy('path')->get()->filter(fn ($l) => ! $this->settings->isInInbox($l->path) && file_exists($l->path))->map(function ($l) use ($labels) {
            $detail = $labels[$l->kind] ?? $l->kind;
            if ($l->kind === 'no_music' || $l->kind === 'bad_dir') {
                $inside = $this->contentsOf($l->path);
                $detail .= $inside ? ' (enthält: '.implode(', ', array_slice($inside, 0, 4)).(count($inside) > 4 ? ' …' : '').')' : '';
            }
            $real = in_array($l->kind, ['no_music', 'bad_dir'], true) && $this->hasRealContent($l->path);
            // Bilder eines umgezogenen Albums gehören zum Album: Ziel aus dem Verlauf
            $album = $l->kind === 'no_music' ? $this->movedAlbumDir($l->path) : null;
            if ($album) {
                $detail .= "\n→ ins Album „".$this->rel($album).'“ übernehmen (gleiche Bilder werden aussortiert, andere kommen mit)';
                $action = ['type' => 'album_files', 'from_dir' => $l->path, 'to_dir' => $album];
            } else {
                $action = $real ? ['type' => 'trash', 'path' => $l->path, 'reason' => $detail] : ['type' => 'delete_junk', 'path' => $l->path];
            }

            return [
                'id' => 'l'.$l->id,
                'title' => $this->rel($l->path),
                'detail' => $detail,
                'link' => null,
                'actions' => [$action],
            ];
        })->values()->all();
    }

    /**
     * Wohin sind die Lieder aus diesem Ordner umgezogen? (letzter Eintrag im Verlauf, Album-Ordner muss noch Musik enthalten)
     */
    private function movedAlbumDir(string $dir): ?string
    {
        $same = fn ($a, $b) => strcasecmp(FileOps::nfc((string) $a), FileOps::nfc((string) $b)) === 0;
        $isImage = fn ($p) => (bool) preg_match('/\.(jpe?g|png|gif)$/i', (string) $p);
        // SQLite-LIKE ignoriert Groß/klein – der Ordner kann damals anders geschrieben gewesen sein
        $entry = HistoryEntry::where('kind', 'move')->where('old_path', 'like', str_replace(['%', '_'], ['\%', '\_'], $dir).'/%')
            ->latest('id')->get()->first(fn ($e) => ! $isImage($e->old_path) && $same(dirname($e->old_path), $dir));
        if (! $entry || ! $entry->new_path) {
            return null;
        }
        // der Datei durch spätere Umzüge folgen (z. B. "Ateens" -> "A-Teens")
        $path = $entry->new_path;
        for ($i = 0; $i < 10 && ! is_file($path); $i++) {
            $next = HistoryEntry::where('kind', 'move')->where('id', '>', $entry->id)->where('old_path', 'like', $path)->orderBy('id')->first();
            if (! $next || ! $next->new_path) {
                break;
            }
            [$entry, $path] = [$next, $next->new_path];
        }
        $actual = FileOps::actualPath(Scanner::albumDir(dirname($path)));

        return $actual && ! $same($actual, $dir) && Track::where('dir', $actual)->orWhere('dir', 'like', $actual.'/%')->exists() ? $actual : null;
    }

    /** @return list<string> Dateien (ohne Junk) in einem Ordner, relativ */
    private function contentsOf(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && ! in_array($f->getFilename(), config('musicwiz.junk_files'), true)) {
                $out[] = ltrim(substr($f->getPathname(), strlen($dir)), '/');
            }
        }

        return $out;
    }

    private function hasRealContent(string $dir): bool
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (! in_array($f->getFilename(), config('musicwiz.junk_files'), true)) {
                return true;
            }
        }

        return false;
    }

    private function checkExtension(): array
    {
        return $this->tracks()->filter(fn ($t) => pathinfo($t->filename, PATHINFO_EXTENSION) !== strtolower(pathinfo($t->filename, PATHINFO_EXTENSION)))
            ->map(fn ($t) => [
                'id' => 't'.$t->id,
                'title' => $this->rel($t->path),
                'detail' => '→ .'.$t->ext,
                'link' => $this->link($this->albums()[$t->album_id] ?? null),
                'actions' => [['type' => 'move', 'path' => $t->path, 'target' => $t->dir.'/'.pathinfo($t->filename, PATHINFO_FILENAME).'.'.$t->ext]],
            ])->values()->all();
    }

    private function checkGenreUnlisted(): array
    {
        $mapping = GenreMapping::whereNotNull('target')->pluck('target', 'source');
        $bySource = [];
        foreach ($this->tracks() as $t) {
            foreach ($t->genres ?? [] as $g) {
                if (! $this->genres->isListed($g)) {
                    $bySource[$g][] = $t;
                }
            }
        }
        uasort($bySource, fn ($a, $b) => count($b) <=> count($a));
        $out = [];
        foreach ($bySource as $source => $tracks) {
            $target = $mapping[$source] ?? null;
            $actions = [];
            if ($target) {
                $actions = $this->applyActions(collect($tracks), function (Track $t) use ($mapping) {
                    $selection = [];
                    foreach ($t->genres ?? [] as $g) {
                        $selection[] = $this->genres->isListed($g) ? $g : ($mapping[$g] ?? null);
                    }
                    $new = $this->genres->expand($this->genres->selection($this->genres->expand(array_values(array_unique(array_filter($selection))))));

                    return $new !== array_values($t->genres ?? []) ? ['genres' => $new] : [];
                });
            }
            $out[] = ['id' => 'g'.md5($source), 'title' => $source, 'detail' => count($tracks).' Datei(en) · '.($target ? '→ '.$target : 'noch nicht zugeordnet'),
                'link' => route('settings', ['tab' => 'mapping']), 'actions' => $actions];
        }

        return $out;
    }

    private function checkGenreMixed(): array
    {
        $out = [];
        foreach ($this->byAlbum() as $albumId => $tracks) {
            $album = $this->albums()[$albumId] ?? null;
            if (! $album || $album->is_compilation || $this->isLooseFolder($album)) {
                continue; // Sampler und "Kein Album": verschiedene Genres sind dort normal
            }
            $sets = $tracks->map(fn ($t) => implode(' ; ', $t->genres ?? []))->countBy()->sortDesc();
            if ($sets->count() <= 1) {
                continue;
            }
            $best = (string) $sets->keys()->first();
            $genres = $best === '' ? [] : explode(' ; ', $best);
            $actions = $best === '' ? [] : $this->applyActions($tracks, fn ($t) => array_values($t->genres ?? []) !== $genres ? ['genres' => $genres] : []);
            $out[] = ['id' => 'a'.$albumId, 'title' => $this->albumTitle($album), 'detail' => $sets->keys()->map(fn ($k) => $k ?: '∅')->implode(' / '), 'link' => $this->link($album), 'actions' => $actions];
        }

        return $out;
    }

    private function checkGenreMissing(): array
    {
        $out = [];
        foreach ($this->byAlbum() as $albumId => $tracks) {
            $album = $this->albums()[$albumId] ?? null;
            if ($this->isLooseFolder($album)) {
                // Einzellieder: pro Lied zählen, Vorschlag nach dem jeweiligen Interpreten
                $missing = $tracks->filter(fn ($t) => empty($t->genres));
                if ($missing->isEmpty()) {
                    continue;
                }
                $hints = $missing->mapWithKeys(fn ($t) => [$t->id => $this->genreHint((string) $t->artist)])->filter();
                $actions = $this->applyActions($missing, fn ($t) => isset($hints[$t->id]) ? ['genres' => $this->genres->expand([$hints[$t->id]['genre']])] : []);
                $detail = $missing->count().' von '.$tracks->count().' Einzelliedern ohne Genre';
                $detail .= $hints->isEmpty() ? ' – kein Vorschlag (im Editor pro Lied)'
                    : "\n".'Vorschlag für '.$hints->count().': '.$hints->pluck('genre')->countBy()->sortDesc()->map(fn ($n, $g) => $g.' ('.$n.')')->implode(', ');
                $out[] = ['id' => 'a'.$albumId, 'title' => $this->albumTitle($album), 'detail' => $detail, 'link' => $this->link($album), 'actions' => $actions];

                continue;
            }
            if (! $tracks->every(fn ($t) => empty($t->genres))) {
                continue;
            }
            $hint = $this->albumGenreHint($album, $tracks);
            $out[] = ['id' => 'a'.$albumId, 'title' => $this->albumTitle($album),
                'detail' => $hint ? 'kein Genre · Vorschlag: '.$hint['genre'].' ('.$hint['reason'].')' : 'kein Genre – kein Vorschlag (im Editor setzen)',
                'link' => $this->link($album),
                'actions' => $hint ? $this->applyActions($tracks, fn () => ['genres' => $this->genres->expand([$hint['genre']])]) : []];
        }

        return $out;
    }

    /**
     * Genre-Vorschlag fürs ganze Album: nach dem Album-Interpreten, bei Samplern das häufigste der Interpreten.
     *
     * @return array{genre:string, reason:string}|null
     */
    private function albumGenreHint(?Album $album, Collection $tracks): ?array
    {
        if (! ($album?->is_compilation)) {
            $first = $tracks->first();

            return $this->genreHint((string) ($first->albumartist ?: $first->artist));
        }
        $hints = $tracks->map(fn ($t) => $this->genreHint((string) $t->artist))->filter();
        if ($hints->isEmpty()) {
            return null;
        }
        $counts = $hints->pluck('genre')->countBy()->sortDesc();
        $genre = (string) $counts->keys()->first();

        return ['genre' => $genre, 'reason' => 'häufigstes bei den Interpreten: '.$counts->first().' von '.$tracks->count().' Liedern'];
    }

    /**
     * Genre-Vorschlag für einen Interpreten (erster Name bei Kombis): Standard-Genre aus dem Künstlerverzeichnis,
     * sonst das häufigste Genre seiner anderen Lieder im Bestand.
     *
     * @return array{genre:string, reason:string}|null
     */
    private function genreHint(string $display): ?array
    {
        $name = ArtistNames::split($display)[0] ?? '';
        if ($name === '') {
            return null;
        }
        if (array_key_exists($name, $this->genreHints)) {
            return $this->genreHints[$name];
        }
        $canonical = $this->directory->check($name)['canonical'] ?? $name;
        $artist = Artist::where('name', $canonical)->first();
        $hint = null;
        if ($artist?->default_genre && $this->genres->isListed($artist->default_genre)) {
            $hint = ['genre' => $artist->default_genre, 'reason' => 'Standard-Genre von '.$artist->name];
        } elseif ($own = $this->genres->suggestForArtist($artist ?? new Artist(['name' => $canonical]))) {
            $hint = ['genre' => $own['genre'], 'reason' => $own['count'].' von '.$own['total'].' Liedern von '.$canonical];
        }

        return $this->genreHints[$name] = $hint;
    }

    private function checkElectronicSub(): array
    {
        $map = $this->genres->map();
        $out = [];
        foreach ($this->byAlbum() as $albumId => $tracks) {
            $album = $this->albums()[$albumId] ?? null;
            if ($this->isLooseFolder($album)) {
                $without = $tracks->filter(fn ($t) => in_array('Electronic', $t->genres ?? [], true)
                    && ! collect($t->genres)->contains(fn ($g) => ($map[$g] ?? null) === 'Electronic'));
                if ($without->isNotEmpty()) {
                    $out[] = ['id' => 'a'.$albumId, 'title' => $this->albumTitle($album), 'detail' => $without->count().' Einzellied(er) mit Electronic ohne Unterstil', 'link' => $this->link($album), 'actions' => []];
                }

                continue;
            }
            $all = $tracks->pluck('genres')->flatten()->unique()->values();
            if (! $all->contains('Electronic')) {
                continue;
            }
            $hasSub = $all->contains(fn ($g) => ($map[$g] ?? null) === 'Electronic');
            if (! $hasSub) {
                $album = $this->albums()[$albumId] ?? null;
                $key = self::albumKey($album, $tracks);
                $dismissed = isset($this->exceptions('electronic_sub')[$key]);
                if ($dismissed && ! $this->withDismissed) {
                    continue; // „Electronic reicht hier“
                }
                $out[] = ['id' => 'a'.$albumId, 'title' => $this->albumTitle($album), 'detail' => 'Electronic ohne Unterstil', 'link' => $this->link($album), 'actions' => [],
                    'dismiss_key' => $key, 'dismissed' => $dismissed];
            }
        }

        return $out;
    }

    /** Album-Schlüssel für Ausnahmen: Album-Interpret|Album normalisiert – übersteht Verschieben und Neueinlesen. */
    public static function albumKey(?Album $album, Collection $tracks): string
    {
        $first = $tracks->first();
        $artist = $album?->albumartist ?: ($first?->albumartist ?: ($album?->folder_artist ?: $first?->artist));

        return ArtistNames::compareKey((string) $artist).'|'.ArtistNames::compareKey((string) ($album?->title ?: $first?->album));
    }

    /** @return array<string, string> item_key => label */
    private function exceptions(string $check): array
    {
        return $this->exceptionCache[$check] ??= CleanupException::where('check', $check)->pluck('label', 'item_key')->all();
    }

    private function checkArtistImage(): array
    {
        return Artist::whereNull('image')->orderBy('name')->get()->map(fn ($a) => [
            'id' => 'r'.$a->id, 'title' => $a->name, 'detail' => 'kein Bild', 'link' => route('artists', ['selected' => $a->id]), 'actions' => [],
        ])->all();
    }
}
