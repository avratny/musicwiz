<?php

namespace App\Support;

use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Models\Track;
use Illuminate\Support\Facades\Cache;

/**
 * Künstlerverzeichnis: jeder Künstler genau einmal mit richtigem Namen und bekannten Varianten.
 * Alles hier sind nur Vorschläge – übernommen wird erst per Klick.
 */
class ArtistDirectory
{
    private ?array $exact = null;

    private ?array $byKey = null;

    /** Mehrdeutige Namen: Vergleichsschlüssel des Grundnamens => [Grundname, Liste der Personen] */
    private ?array $ambiguous = null;

    public function __construct(private Settings $settings) {}

    public function flush(): void
    {
        $this->exact = $this->byKey = $this->ambiguous = $this->names = null;
        ArtistNames::flushProtected();
    }

    /** Zähler für Änderungen am Verzeichnis (Künstler/Schreibweise gespeichert oder gelöscht), siehe AppServiceProvider */
    private static int $generation = 0;

    private int $loadedGeneration = -1;

    /** Verzeichnis hat sich geändert: der nächste Zugriff lädt neu (nicht mitten in einem laufenden Vorgang). */
    public static function invalidate(): void
    {
        self::$generation++;
    }

    private function load(): void
    {
        if ($this->exact !== null && $this->loadedGeneration === self::$generation) {
            return;
        }
        $this->loadedGeneration = self::$generation;
        $this->exact = [];
        $this->byKey = [];
        $this->ambiguous = [];
        foreach (Artist::whereNotNull('base_name')->orderBy('name')->get() as $a) {
            $key = ArtistNames::compareKey($a->base_name);
            $this->ambiguous[$key]['base'] = $a->base_name;
            $this->ambiguous[$key]['persons'][] = $a;
        }
        foreach (Artist::all(['id', 'name', 'compare_key']) as $a) {
            $this->exact[mb_strtolower($a->name)] = $a->name;
            $this->byKey[$a->compare_key] ??= $a->name;
        }
        foreach (ArtistAlias::with('artist:id,name')->get() as $al) {
            // ein mehrdeutiger Grundname darf nie automatisch einer Person zugeordnet werden
            if ($al->artist && ! isset($this->ambiguous[$al->compare_key])) {
                $this->exact[mb_strtolower($al->alias)] = $al->artist->name;
                $this->byKey[$al->compare_key] ??= $al->artist->name;
            }
        }
    }

    /**
     * Abgleich eines Namens mit dem Verzeichnis.
     *
     * @return array{status:string, canonical:?string} status: ok | variant | similar | ambiguous | new
     */
    public function check(string $name): array
    {
        $this->load();
        $name = trim($name);
        if ($this->personsFor($name)) {
            return ['status' => 'ambiguous', 'canonical' => null];
        }
        $hit = $this->exact[mb_strtolower($name)] ?? null;
        if ($hit !== null) {
            return ['status' => $hit === $name ? 'ok' : 'variant', 'canonical' => $hit];
        }
        $key = ArtistNames::compareKey($name);
        if ($key !== '' && isset($this->byKey[$key])) {
            return ['status' => 'similar', 'canonical' => $this->byKey[$key]];
        }

        return ['status' => 'new', 'canonical' => null];
    }

    /**
     * Personen zu einem mehrdeutigen Grundnamen (leer, wenn der Name eindeutig ist).
     *
     * @return list<Artist>
     */
    public function personsFor(string $name): array
    {
        $this->load();

        return $this->ambiguous[ArtistNames::compareKey($name)]['persons'] ?? [];
    }

    /** @return array<string, array{base:string, persons:list<Artist>}> */
    public function ambiguousNames(): array
    {
        $this->load();

        return $this->ambiguous;
    }

    /**
     * ARTISTS aus dem Anzeigetext bilden. Mehrdeutige Namen werden über die Auswahl (Grundname => voller Name)
     * oder eine schon vorhandene Zuordnung in den Tags aufgelöst.
     *
     * @param  list<string>  $existing  bisherige ARTISTS bzw. ALBUMARTISTS
     * @param  array<string, string>  $choices  Grundname (beliebige Schreibweise) => voller Name, z. B. "Adam" => "Adam [DE]"
     * @return array{names: list<string>, unresolved: list<string>}
     */
    public function resolveNames(?string $display, array $existing = [], array $choices = []): array
    {
        $names = [];
        $unresolved = [];
        $choiceByKey = [];
        foreach ($choices as $base => $full) {
            if ($full) {
                $choiceByKey[ArtistNames::compareKey($base)] = $full;
            }
        }
        foreach (ArtistNames::split($display) as $name) {
            $persons = $this->personsFor($name);
            if (! $persons) {
                $names[] = $name;

                continue;
            }
            $key = ArtistNames::compareKey($name);
            $full = $choiceByKey[$key] ?? null;
            if (! $full) {
                // bereits zugeordnet? (ein Eintrag in den Tags, der zu einer der Personen gehört)
                $personNames = array_map(fn ($p) => $p->name, $persons);
                $full = collect($existing)->first(fn ($e) => in_array($e, $personNames, true));
            }
            if ($full) {
                $names[] = $full;
            } else {
                $names[] = $name;
                $unresolved[] = $name;
            }
        }

        return ['names' => array_values(array_unique($names)), 'unresolved' => array_values(array_unique($unresolved))];
    }

    /**
     * Welche Künstlernamen in diesen Anzeigetexten laut Verzeichnis anders heißen ("umbenennen").
     *
     * @param  list<?string>  $displays  ARTIST- bzw. ALBUMARTIST-Werte
     * @return array<string, array{to:string, source:string}> alter Name => neuer Name, Quelle (MusicBrainz|Verzeichnis)
     */
    public function renamesFor(array $displays): array
    {
        $out = [];
        foreach ($displays as $display) {
            foreach (ArtistNames::split($display) as $name) {
                if (isset($out[$name])) {
                    continue;
                }
                $c = $this->check($name);
                if (in_array($c['status'], ['variant', 'similar'], true) && $c['canonical'] !== $name) {
                    $source = ArtistAlias::where('alias', $name)->value('source');
                    $out[$name] = ['to' => $c['canonical'], 'source' => $source === 'musicbrainz' ? 'MusicBrainz' : 'Verzeichnis'];
                }
            }
        }

        return $out;
    }

    /**
     * Anzeigetext mit richtigen Schreibweisen (nur bekannte Varianten werden ersetzt).
     */
    public function correctText(string $display): string
    {
        $out = $display;
        foreach (ArtistNames::split($display) as $name) {
            $c = $this->check($name);
            if (in_array($c['status'], ['variant', 'similar'], true) && $c['canonical'] !== $name) {
                $out = preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u', $c['canonical'], $out, 1);
            }
        }

        return $out;
    }

    /**
     * Namen aus dem Bestand zählen (aus ARTISTS bzw. aufgetrenntem Interpreten).
     *
     * @return array<string, int>
     */
    public function namesInCollection(): array
    {
        $skip = mb_strtolower((string) $this->settings->get('compilation_artist'));

        return $this->names ??= Cache::remember('artist-names:v4:'.DataVersion::artistContent().':'.md5($skip), now()->addDays(30), fn () => $this->countNames($skip));
    }

    private ?array $names = null;

    /** @return array<string, int> */
    /** Namen aus dem Album-Interpreten – ohne Sampler-Schreibweisen („V.A.“, „Diverse“ …), die sind kein Künstler. */
    public static function albumArtistNames(object $track): array
    {
        return app(Compilations::class)->isVariousArtists($track->albumartist) ? [] : ($track->albumartists ?: ArtistNames::split($track->albumartist));
    }

    private function countNames(string $skip): array
    {
        $counts = [];
        // nur der Bestand: Inbox-Musik zählt erst, wenn sie eingeordnet ist
        // gleiche Namensfelder zusammenfassen: aus 15.000 Titeln werden ein paar tausend Kombinationen
        $groups = Track::where('location', 'library')->toBase()
            ->selectRaw('artist, artists, albumartist, albumartists, count(*) as n')
            ->groupBy('artist', 'artists', 'albumartist', 'albumartists')->get();
        foreach ($groups as $g) {
            $g->artists = json_decode((string) $g->artists, true) ?: [];
            $g->albumartists = json_decode((string) $g->albumartists, true) ?: [];
            $names = array_merge($g->artists ?: ArtistNames::split($g->artist), $this->albumArtistNames($g));
            foreach (array_unique($names) as $n) {
                if ($n !== '' && mb_strtolower($n) !== $skip) {
                    $counts[$n] = ($counts[$n] ?? 0) + (int) $g->n;
                }
            }
        }

        return $counts;
    }

    /**
     * Kandidaten für dieselbe Person: gleicher Vergleichsschlüssel oder sehr ähnlich (Tippfehler).
     *
     * @return list<array{names: array<string,int>, suggestion:string, known:?string}>
     */
    public function candidateGroups(): array
    {
        // teuer (Ähnlichkeitssuche über alle Namen) – neu nur, wenn sich Bestand oder Verzeichnis ändern
        return Cache::remember('artist-groups:v3:'.DataVersion::artistContent().':'.DataVersion::directory(), now()->addDays(30), fn () => $this->buildCandidateGroups());
    }

    /**
     * Namen mit gleichem Vergleichsschlüssel bzw. Tippfehler-Abstand zusammengefasst (nur Gruppen ab zwei Namen).
     * Der teure Teil – hängt nur am Inhalt der Lib, nicht am Verzeichnis: übernommene Vorschläge rechnen ihn nicht neu.
     *
     * @return array<string, array<string, int>> Schlüssel => [Name => Anzahl]
     */
    private function rawGroups(): array
    {
        return Cache::remember('artist-raw-groups:v1:'.DataVersion::artistContent(), now()->addDays(30), function () {
            $counts = $this->namesInCollection();
            $groups = [];
            foreach ($counts as $name => $n) {
                $groups[ArtistNames::compareKey($name)][$name] = $n;
            }

            // Ähnlichkeitssuche gegen Tippfehler (nur innerhalb gleicher Anfangsbuchstaben)
            $keys = array_keys(array_filter($groups, fn ($k) => strlen($k) >= 7, ARRAY_FILTER_USE_KEY));
            $buckets = [];
            foreach ($keys as $k) {
                $buckets[substr($k, 0, 1)][] = $k;
            }
            $merged = [];
            foreach ($buckets as $list) {
                $c = count($list);
                for ($i = 0; $i < $c; $i++) {
                    for ($j = $i + 1; $j < $c; $j++) {
                        $a = $list[$i];
                        $b = $list[$j];
                        if (abs(strlen($a) - strlen($b)) > 1 || isset($merged[$b]) || isset($merged[$a])) {
                            continue;
                        }
                        $max = strlen($a) > 10 ? 2 : 1;
                        if (levenshtein($a, $b) <= $max && ! preg_match('/\d/', $a.$b)) {
                            $groups[$a] += $groups[$b];
                            unset($groups[$b]);
                            $merged[$b] = true;
                        }
                    }
                }
            }

            return array_filter($groups, fn ($names) => count($names) >= 2);
        });
    }

    /** @return list<array{names: array<string,int>, suggestion:string, known:?string}> */
    private function buildCandidateGroups(): array
    {
        $this->load();
        // eine Abfrage statt einer pro Name
        $withBase = Artist::whereNotNull('base_name')->pluck('name')->flip()->all();
        $out = [];
        foreach ($this->rawGroups() as $key => $names) {
            // gleichnamige Personen ("Adam [DE]" / "Adam [GB]") sind gewollt verschieden
            $persons = array_filter(array_keys($names), fn ($n) => isset($withBase[$n]));
            if (count($persons) === count($names)) {
                continue;
            }
            // Gruppen auslassen, über deren Namen schon entschieden ist: alle stehen im Verzeichnis bzw. sind zugeordnet –
            // derselbe Eintrag (Schreibweise festgelegt) oder verschiedene Einträge ("Taucher" ≠ "Tauscher")
            $resolved = array_map(fn ($n) => $this->exact[mb_strtolower($n)] ?? null, array_keys($names));
            if (! in_array(null, $resolved, true)) {
                continue;
            }
            arsort($names);
            $known = $this->byKey[$key] ?? null;
            $out[] = ['key' => $key, 'names' => $names, 'suggestion' => $known ?? (string) array_key_first($names), 'known' => $known];
        }
        usort($out, fn ($a, $b) => array_sum($b['names']) <=> array_sum($a['names']));

        return $out;
    }

    /**
     * Schreibweisen eines Künstlers von MusicBrainz ins Verzeichnis übernehmen (Herkunft "musicbrainz"), damit künftige
     * Schreibweisen – z. B. aus der Inbox – erkannt werden. Nichts wird überschrieben: Namen, die schon ein Künstler
     * oder eine Schreibweise sind, bleiben, wie sie sind; Grundnamen gleichnamiger Künstler ("Adam") werden nie Alias.
     *
     * @param  list<string>  $aliases  z. B. aus Lookup::musicbrainzArtistAliases()
     * @return list<string> neu gespeicherte Schreibweisen
     */
    public function importMusicBrainzAliases(Artist $artist, array $aliases): array
    {
        $this->load();
        $added = [];
        $otherKeys = Artist::where('id', '!=', $artist->id)->pluck('compare_key')->flip();
        foreach ($aliases as $alias) {
            $alias = trim((string) $alias);
            $key = ArtistNames::compareKey($alias);
            // Zusammenarbeiten nie als Schreibweise (würden geschützt und nicht mehr getrennt) – außer der Name ist selbst so gebaut
            if (ArtistNames::looksCollaboration($alias) && ! ArtistNames::looksCollaboration($artist->name)) {
                continue;
            }
            if ($alias === '' || $key === '' || $alias === $artist->name || isset($this->exact[mb_strtolower($alias)])
                || isset($otherKeys[$key]) || isset($this->ambiguous[$key]) || ArtistAlias::where('alias', $alias)->exists()) {
                continue;
            }
            $artist->aliases()->create(['alias' => $alias, 'source' => 'musicbrainz']);
            $this->exact[mb_strtolower($alias)] = $artist->name;
            $added[] = $alias;
        }
        if ($added) {
            $this->flush();
        }

        return $added;
    }

    /**
     * Namen als eigene Künstler eintragen, sofern sie noch nicht im Verzeichnis stehen
     * ("gehört nicht dazu" bzw. "alle verschieden" – damit die Gruppe nicht wieder vorgeschlagen wird).
     *
     * @param  list<string>  $names
     * @return list<string> neu eingetragene Namen
     */
    public function keepSeparate(array $names): array
    {
        $added = [];
        foreach ($names as $n) {
            $n = trim($n);
            if ($n !== '' && ! in_array($this->check($n)['status'], ['ok', 'variant', 'ambiguous'], true)) {
                Artist::firstOrCreate(['name' => $n]);
                $added[] = $n;
            }
        }
        $this->flush();

        return $added;
    }

    /** Richtige Schreibweise festlegen und Varianten als Alias speichern. */
    public function resolve(string $canonical, array $variants): Artist
    {
        $canonical = trim($canonical);
        $artist = Artist::firstOrCreate(['name' => $canonical]);
        foreach ($variants as $v) {
            $v = trim($v);
            if ($v === '' || $v === $canonical) {
                continue;
            }
            $existing = ArtistAlias::where('alias', $v)->first();
            if ($existing) {
                $existing->update(['artist_id' => $artist->id]);
            } else {
                $artist->aliases()->create(['alias' => $v]);
            }
            // ein Künstler, der jetzt Variante ist, wird in den neuen überführt
            $old = Artist::where('name', $v)->where('id', '!=', $artist->id)->first();
            if ($old) {
                ArtistAlias::where('artist_id', $old->id)->update(['artist_id' => $artist->id]);
                $artist->fill(array_filter($artist->only(['mbid', 'discogs_id', 'default_genre'])) + array_filter($old->only(['mbid', 'discogs_id', 'default_genre'])))->save();
                $old->delete();
            }
        }
        $this->flush();

        return $artist;
    }
}
