<?php

namespace App\Support;

use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Models\Setting;
use App\Models\TagChange;
use App\Models\Track;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Sortiernamen ARTISTSORT/ALBUMARTISTSORT (MP3: TSOP/TSO2): Artikel nach hinten („The Beatles“ → „Beatles, The“, Artikel
 * aus den Einstellungen) oder der eigene Sortiername beim Interpreten. Personen werden nie umgedreht („Udo Jürgens“).
 * Gleicht der Sortiername dem Anzeigenamen, braucht es kein Tag. Bei „A feat. B“ wird jeder Name einzeln ersetzt;
 * Various Artists bekommt keinen.
 *
 * Was eindeutig ist, trägt MusicWiz selbst ein (System-Änderung): fehlender Sortiername; ein vorhandener, der nur die
 * Wörter umstellt („Jürgens, Udo“) oder von MusicWiz selbst gesetzt wurde. Anderes bleibt offen (Prüflisten unter
 * Interpreten und Alben). Dazu: umgedrehte Anzeigenamen („Art of Noise, The“) werden zu „The Art of Noise“.
 * Nur Inbox und Lib.
 */
class SortNames
{
    /** Tag-Feld => Feld mit dem Anzeigenamen */
    public const FIELDS = ['artistsort' => 'artist', 'albumartistsort' => 'albumartist'];

    public const DEFAULT_ARTICLES = 'The, Die, Der, Das, A, An, Les, Le, La, Los, Las, El, Il';

    /** @var array<string, string>|null Name bzw. Schreibweise (klein) => eigener Sortiername */
    private ?array $overrides = null;

    private ?array $articles = null;

    public function __construct(private ArtistCredits $credits) {}

    public function forget(): void
    {
        $this->overrides = $this->articles = null;
        $this->credits->forget();
    }

    /** Sortiernamen aus den rohen Tags der Datei (MP3 TSOP/TSO2, Vorbis, M4A soar/soaa, WMA) */
    public static function fromRawTags(array $raw): array
    {
        $upper = array_change_key_case($raw, CASE_UPPER);
        $find = function (array $keys) use ($upper) {
            foreach ($keys as $key) {
                foreach ((array) ($upper[$key] ?? []) as $v) {
                    if (($v = trim((string) $v)) !== '') {
                        return mb_substr($v, 0, 1024);
                    }
                }
            }

            return null;
        };

        return [
            'artistsort' => $find(['TSOP', 'ARTISTSORT', 'SOAR', 'WM/ARTISTSORTORDER']),
            'albumartistsort' => $find(['TSO2', 'ALBUMARTISTSORT', 'SOAA', 'WM/ALBUMARTISTSORTORDER']),
        ];
    }

    /** @return list<string> Artikel, klein */
    public function articles(): array
    {
        // direkt aus der Tabelle: die Einstellung kann sich in derselben Anfrage ändern
        return $this->articles ??= array_values(array_filter(array_map(fn ($a) => mb_strtolower(trim($a)),
            explode(',', (string) (Setting::where('key', 'sort_articles')->value('value') ?: self::DEFAULT_ARTICLES)))));
    }

    /** „The Beatles“ → „Beatles, The“; sonst unverändert */
    public function byRule(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), 2);

        return count($parts) === 2 && in_array(mb_strtolower($parts[0]), $this->articles(), true) ? $parts[1].', '.$parts[0] : trim($name);
    }

    /** umgedrehter Anzeigename „Art of Noise, The“ → „The Art of Noise“ (null = nicht umgedreht) */
    public function uninverted(string $name): ?string
    {
        if (preg_match('/^(.*\S),\s*(\S+)$/u', trim($name), $m) && in_array(mb_strtolower($m[2]), $this->articles(), true)) {
            return $m[2].' '.$m[1];
        }

        return null;
    }

    /** Sortiername eines Interpreten: eigener Eintrag, sonst nach der Artikel-Regel */
    public function forName(string $name): string
    {
        if ($this->overrides === null) {
            $this->overrides = [];
            foreach (Artist::whereNotNull('sort_name')->with('aliases')->get(['id', 'name', 'sort_name']) as $a) {
                foreach ([$a->name, ...$a->aliases->pluck('alias')] as $n) {
                    $this->overrides[mb_strtolower($n)] = $a->sort_name;
                }
            }
        }

        return $this->overrides[mb_strtolower(trim($name))] ?? $this->byRule($name);
    }

    /**
     * Sortiername zu einem Anzeigetext („The Beatles feat. Liz“ → „Beatles, The feat. Liz“) – null, wenn keiner nötig.
     * Nur der Name am Anfang bekommt seine Sortierform; dahinter bleibt alles, wie es angezeigt wird
     * („Thomas Gottschalk Und Die Besorgten Väter“ bleibt so).
     */
    public function expected(?string $text, ?string $list = null): ?string
    {
        $text = trim((string) $text);
        if ($text === '' || $text === NameRules::VARIOUS) {
            return null;
        }
        foreach (ArtistCredits::list($list) ?: $this->credits->credits($text) as $name) {
            if (preg_match('/^'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u', $text)) {
                $sort = $this->forName($name);

                return $sort === $name ? null : $sort.mb_substr($text, mb_strlen($name));
            }
        }

        return null;
    }

    /** gleiche Wörter, nur umgestellt („Jürgens, Udo“ zu „Udo Jürgens“) */
    public static function sameWords(string $a, string $b): bool
    {
        $words = function (string $s) {
            $w = preg_split('/[\s,]+/u', mb_strtolower(trim($s)), -1, PREG_SPLIT_NO_EMPTY);
            sort($w);

            return $w;
        };

        return $words($a) === $words($b);
    }

    /**
     * Alle Lieder (Inbox, Lib) prüfen.
     *
     * @return array{auto: list<array>, open: list<array>} je Eintrag: id, album_hash, field, text, current, expected
     */
    public function check(): array
    {
        $this->forget();
        $auto = $open = [];
        $query = Track::whereIn('location', SplitAlbums::LOCATIONS)->select(['id', 'album_hash', 'artist', 'artists', 'albumartist', 'albumartists', 'artistsort', 'albumartistsort']);
        foreach ($query->lazyById(2000) as $t) {
            foreach (self::FIELDS as $field => $source) {
                $current = $t->getAttribute($field);
                $expected = $this->expected($t->getAttribute($source), $t->getAttribute(ArtistCredits::FIELDS[$source]));
                if ((string) $current === (string) $expected) {
                    continue;
                }
                $item = ['id' => $t->id, 'album_hash' => $t->album_hash, 'field' => $field, 'text' => (string) $t->getAttribute($source),
                    'list' => $t->getAttribute(ArtistCredits::FIELDS[$source]), 'current' => $current, 'expected' => $expected];
                $sure = $current === null || self::sameWords($current, (string) $t->getAttribute($source)) || ($expected !== null && self::sameWords($current, $expected));
                if ($sure) {
                    $auto[] = $item;
                } else {
                    $open[] = $item;
                }
            }
        }
        // von MusicWiz selbst gesetzt (letzte Änderung des Felds = jetziger Wert): auch eindeutig
        if ($open) {
            $last = TagChange::whereIn('track_id', array_column($open, 'id'))->whereIn('field', array_keys(self::FIELDS))
                ->orderBy('id')->get(['track_id', 'field', 'new_value'])->mapWithKeys(fn ($c) => [$c->track_id.'|'.$c->field => $c->new_value]);
            foreach ($open as $i => $item) {
                if (($last[$item['id'].'|'.$item['field']] ?? false) === $item['current']) {
                    $auto[] = $item;
                    unset($open[$i]);
                }
            }
        }

        return ['auto' => $auto, 'open' => array_values($open)];
    }

    /** Offene Abweichungen (für die Prüflisten) – zwischengespeichert, solange sich nichts ändert */
    public function open(): array
    {
        $state = implode('|', [Track::count(), Track::max('updated_at'), TagChange::max('id'), Artist::max('updated_at'), ArtistAlias::max('id'),
            implode(',', $this->articles()), Cache::get('sortnames:version', 0)]);

        return Cache::remember('sortnames:'.md5($state), now()->addDay(), fn () => $this->check()['open']);
    }

    /**
     * Offene Abweichungen je Interpret (Name im Anzeigetext → Eintrag im Verzeichnis).
     *
     * @return array<int, array{artist: Artist, expected: ?string, values: array<string, int>, tracks: int}>
     */
    public function openByArtist(): array
    {
        $out = [];
        $ids = [];
        foreach ($this->open() as $item) {
            foreach (ArtistCredits::list($item['list']) ?: $this->credits->credits($item['text']) as $name) {
                $ids[$name] ??= Artist::where('name', $name)->value('id') ?? ArtistAlias::where('alias', $name)->value('artist_id');
                if (! $ids[$name]) {
                    continue;
                }
                $entry = &$out[$ids[$name]];
                $entry['values'][(string) $item['current']] = ($entry['values'][(string) $item['current']] ?? 0) + 1;
                $entry['track_ids'][$item['id']] = true;
                $entry['single'] = ($entry['single'] ?? true) && $item['text'] === $name;
                unset($entry);
            }
        }
        $artists = Artist::whereIn('id', array_keys($out))->get()->keyBy('id');
        foreach ($out as $id => &$entry) {
            $entry = ['artist' => $artists[$id], 'expected' => $this->forName($artists[$id]->name), 'values' => $entry['values'],
                'tracks' => count($entry['track_ids']), 'single' => $entry['single']];
        }

        return collect($out)->sortBy(fn ($e) => mb_strtolower($e['artist']->name))->all();
    }

    /** Offene Abweichungen eines Interpreten nach der Regel übernehmen */
    public function resolveArtist(Artist $artist): int
    {
        $names = array_map('mb_strtolower', [$artist->name, ...$artist->aliases()->pluck('alias')]);

        return $this->resolve(fn ($i) => (bool) array_intersect($names, array_map('mb_strtolower', ArtistCredits::list($i['list']) ?: $this->credits->credits($i['text']))));
    }

    /**
     * Offene Abweichungen je Album, nach Ordner sortiert.
     *
     * @return array{total: int, albums: list<array>}
     */
    public function openAlbums(string $search = '', int $limit = 50): array
    {
        $byAlbum = collect($this->open())->groupBy('album_hash');
        $tracks = Track::whereIn('location', SplitAlbums::LOCATIONS)->whereIn('album_hash', $byAlbum->keys())
            ->get(['id', 'location', 'album_hash', 'album_dir', 'album', 'albumartist', 'artist', 'has_cover'])->groupBy('album_hash');
        $like = mb_strtolower(trim($search));
        $mode = fn ($t, string $f) => $t->pluck($f)->filter()->countBy()->sortDesc()->keys()->first();
        $albums = $byAlbum->map(function ($items, $hash) use ($tracks, $mode) {
            $t = $tracks[$hash];
            $first = $t->first();

            return [
                'hash' => $hash,
                'location' => $first->location,
                'dir' => ltrim(mb_substr($first->album_dir, mb_strlen(Locations::root($first->location))), '/'),
                'album' => $mode($t, 'album'),
                'albumartist' => $mode($t, 'albumartist') ?? $mode($t, 'artist'),
                'cover' => $t->firstWhere('has_cover', true)?->id ?? $first->id,
                // je Feld, Anzeigetext, jetziger und erwarteter Wert: Zahl der Lieder
                'rows' => $items->groupBy(fn ($i) => $i['field'].'|'.$i['text'].'|'.$i['current'].'|'.$i['expected'])
                    ->map(fn ($g) => [...collect($g->first())->only(['field', 'text', 'current', 'expected'])->all(), 'count' => $g->count()])->values()->all(),
            ];
        })->filter(fn ($a) => $like === '' || str_contains(mb_strtolower($a['album'].' '.$a['albumartist'].' '.$a['dir'].' '.implode(' ', array_column($a['rows'], 'text'))), $like))
            ->sortBy(fn ($a) => mb_strtolower($a['dir']))->values();

        return ['total' => $albums->count(), 'albums' => $albums->take($limit)->all()];
    }

    /**
     * Eindeutiges selbst eintragen: umgedrehte Anzeigenamen richten, dann fehlende bzw. eindeutig falsche Sortiernamen.
     *
     * @return int Zahl der geänderten Lieder
     */
    public function apply(): int
    {
        $this->uninvertArtists();
        $items = $this->check()['auto'];

        return $this->set($items, TagEditor::SYSTEM);
    }

    /** Offene Abweichungen übernehmen (Sortiername laut Regel) – gefiltert. @return int Zahl der geänderten Lieder */
    public function resolve(callable $filter): int
    {
        return $this->set(array_values(array_filter($this->check()['open'], $filter)), TagEditor::MANUAL);
    }

    /** Umgedrehte Anzeigenamen im Künstlerverzeichnis („Art of Noise, The“ → „The Art of Noise“). @return int Zahl */
    public function uninvertArtists(): int
    {
        $registry = app(ArtistRegistry::class);
        $n = 0;
        foreach (Artist::get(['id', 'name']) as $artist) {
            $target = $this->uninverted($artist->name);
            if ($target === null) {
                continue;
            }
            $owner = $registry->ownerOf($target);
            try {
                $owner && $owner->id !== $artist->id ? $registry->merge($owner, $artist) : $registry->rename($artist, $target);
                $n++;
            } catch (InvalidArgumentException) {
                // bleibt, wie es ist
            }
        }
        if ($n) {
            $this->forget();
        }

        return $n;
    }

    /** Eigenen Sortiernamen beim Interpreten setzen (leer = wieder nach der Regel), dann Eindeutiges eintragen */
    public function setOverride(Artist $artist, ?string $sortName): int
    {
        $sortName = trim((string) $sortName);
        $artist->update(['sort_name' => $sortName === '' || $sortName === $this->byRule($artist->name) ? null : $sortName]);
        Cache::forever('sortnames:version', (int) Cache::get('sortnames:version', 0) + 1);
        $this->forget();

        return $this->apply();
    }

    private function set(array $items, string $source): int
    {
        $editor = app(TagEditor::class);
        $byTrack = [];
        foreach ($items as $item) {
            $byTrack[$item['id']][$item['field']] = $item;
        }
        $n = 0;
        foreach (array_chunk(array_keys($byTrack), 500) as $chunk) {
            foreach (Track::whereIn('id', $chunk)->get() as $track) {
                $values = array_map(fn ($i) => $i['expected'], $byTrack[$track->id]);
                $reason = 'Sortiername: '.implode(', ', array_map(fn ($i) => '„'.$i['text'].'“ → '.($i['expected'] === null ? 'keiner nötig' : '„'.$i['expected'].'“'), $byTrack[$track->id]));
                $n += $editor->update($track, $values, $source, $reason) ? 1 : 0;
            }
        }

        return $n;
    }
}
