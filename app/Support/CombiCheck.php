<?php

namespace App\Support;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Track;
use Illuminate\Support\Facades\Cache;

/**
 * Kombi-Prüfung: Ist „A & B“ ein Act (Simon & Garfunkel) oder eine Zusammenarbeit (Dr. Motte & WestBam)?
 *
 * Entscheidend ist, wie die Dienste eure Lieder gutschreiben: ein Künstler mit dem Kombi-Namen = Act, mehrere getrennte
 * Künstler = Zusammenarbeit. Gefragt werden Deezer und Spotify (Hauptkünstler je Lied) sowie MusicBrainz (eingetragene Künstler),
 * für bis zu drei Lieder des Namens. Der eigene Bestand ist Gegenprobe (eigene Alben unter dem Kombi-Namen → eher Act,
 * Teile auch solo → eher Zusammenarbeit). Das Ergebnis wird zwischengespeichert; die Seiten lesen nur den Speicher.
 */
class CombiCheck
{
    private const KEY = 'combi-check:v1:';

    public function __construct(private Lookup $lookup, private ArtistDirectory $directory) {}

    /** Braucht der Name eine Prüfung? (&, und, and, x, Komma, feat., vs. …) */
    public static function applies(string $name): bool
    {
        return ArtistNames::looksCollaboration($name);
    }

    /**
     * Gespeichertes Ergebnis (ohne Abfragen).
     *
     * @return array{verdict: string, parts: list<string>, reason: string, evidence: list<array>, local: array, checked_at: string}|null
     */
    public function cached(string $name): ?array
    {
        return Cache::get(self::KEY.md5($name));
    }

    /**
     * @param  list<string>  $names
     * @return array<string, array|null>
     */
    public function cachedMany(array $names): array
    {
        if (! $names) {
            return [];
        }
        $keys = array_combine($names, array_map(fn ($n) => self::KEY.md5($n), $names));
        $found = Cache::many(array_values($keys));

        return array_map(fn ($k) => $found[$k] ?? null, $keys);
    }

    /** Jetzt prüfen (fragt die Dienste) und speichern. */
    public function check(string $name, bool $fresh = false): array
    {
        if (! $fresh && ($hit = $this->cached($name))) {
            return $hit;
        }
        $evidence = [];
        foreach ($this->titles($name) as $title) {
            $sources = ['Deezer' => fn () => $this->lookup->deezerCredit($name, $title), 'MusicBrainz' => fn () => $this->lookup->musicbrainzCredit($name, $title)];
            if ($this->lookup->hasSpotify()) {
                $sources['Spotify'] = fn () => $this->lookup->spotifyCredit($name, $title);
            }
            foreach ($sources as $source => $fn) {
                $credit = rescue($fn, null, report: false);
                if ($credit) {
                    $evidence[] = ['source' => $source, 'title' => $title, 'credited' => $credit, 'vote' => $this->vote($name, $credit)];
                }
            }
        }
        $result = $this->verdict($name, $evidence, $this->local($name));
        Cache::put(self::KEY.md5($name), $result, now()->addDays(max(1, (int) config('musicwiz.lookup_cache_days', 10)) * 3));
        // steht der Name im Verzeichnis: „Name passt“ neu bewerten (automatisch bestätigen bzw. zurücknehmen)
        if ($artist = Artist::where('name', $name)->first()) {
            app(NameConfirmation::class)->evaluate($artist, null, $result, true);
        }

        return $result;
    }

    /**
     * Stimme eines Lieds: steht der volle Kombi-Name als ein Künstler da (auch mit Gast, „Above & Beyond“ + Sänger) =
     * Act; mehrere getrennte Künstler, von denen einer zum Namen passt (auch bei Tippfehlern wie „Dr. Mote“) =
     * Zusammenarbeit; sonst (anderer Künstler) keine Stimme.
     */
    private function vote(string $name, array $credit): ?string
    {
        $key = ArtistNames::compareKey($name);
        $keys = array_map(fn ($c) => ArtistNames::compareKey($c), $credit);
        if (in_array($key, $keys, true)) {
            return 'act';
        }
        if (count($credit) < 2) {
            return null;
        }
        $parts = array_filter(array_map(fn ($p) => ArtistNames::compareKey($p), ArtistNames::suggestSplit($name) ?: [$name]));
        foreach ($keys as $k) {
            foreach ($parts as $p) {
                if ($k !== '' && ($k === $p || str_contains($k, $p) || str_contains($p, $k))) {
                    return 'collab';
                }
            }
        }

        return null;
    }

    private function verdict(string $name, array $evidence, array $local): array
    {
        $votes = array_count_values(array_filter(array_column($evidence, 'vote')));
        $act = $votes['act'] ?? 0;
        $collab = $votes['collab'] ?? 0;
        // Teile, wie die Dienste sie eintragen, sonst aus dem Namen
        // Schreibweise der Teile: MusicBrainz vor Spotify vor Deezer (Deezer schreibt öfter daneben, z. B. „Dr. Mote“);
        // steht ein Teil im Verzeichnis, gilt dessen Schreibweise
        $rank = ['MusicBrainz' => 0, 'Spotify' => 1, 'Deezer' => 2];
        $credited = collect($evidence)->where('vote', 'collab')->sortBy(fn ($e) => $rank[$e['source']] ?? 9)->pluck('credited')->first();
        $parts = array_map(function ($p) {
            $c = $this->directory->check($p);

            return in_array($c['status'], ['ok', 'variant'], true) ? $c['canonical'] : $p;
        }, $credited ?: ArtistNames::suggestSplit($name));
        if ($act > $collab) {
            $verdict = 'act';
        } elseif ($collab > $act) {
            $verdict = 'collab';
        } else {
            $verdict = 'unclear';
        }
        $bySource = collect($evidence)->groupBy('source')->map(fn ($e, $s) => $s.': '.$e->where('vote', 'act')->count().'× Act, '.$e->where('vote', 'collab')->count().'× getrennt')->values()->implode(' · ');
        $reason = match ($verdict) {
            'act' => 'als ein Künstler eingetragen',
            'collab' => 'als getrennte Künstler eingetragen ('.implode(' + ', $parts).')',
            default => $evidence ? 'Dienste uneinig' : 'eure Lieder bei keinem Dienst gefunden',
        };
        // Bestand als Gegenprobe (entscheidet nur, wenn die Dienste nichts sagen – wird aber immer angezeigt)
        if ($verdict === 'unclear' && ! $evidence) {
            if ($local['own_albums'] > 0 && ! $local['parts_solo']) {
                $reason .= ' – im Bestand eigene Alben unter dem Namen (eher ein Act)';
            } elseif ($local['parts_solo']) {
                $reason .= ' – die Teile kommen im Bestand auch solo vor (eher Zusammenarbeit)';
            }
        }

        return ['verdict' => $verdict, 'parts' => array_values($parts), 'reason' => $reason.($bySource ? ' · '.$bySource : ''),
            'evidence' => $evidence, 'local' => $local, 'checked_at' => now()->toIso8601String()];
    }

    /** bis zu drei verschiedene Liedtitel des Namens aus dem Bestand (auch Inbox) */
    private function titles(string $name): array
    {
        $titles = array_values(app(ArtistGroupAnalyzer::class)->profiles()[$name]['titles'] ?? []);
        if (! $titles) {
            $titles = Track::where('artist', $name)->orWhere('albumartist', $name)->whereNotNull('title')->limit(10)->pluck('title')->unique()->values()->all();
        }

        return array_slice(array_values(array_filter($titles)), 0, 3);
    }

    /** Gegenprobe im Bestand: eigene Alben unter dem Kombi-Namen, Teile auch solo */
    private function local(string $name): array
    {
        $ownAlbums = Album::where('location', 'library')->where('albumartist', $name)->where('is_compilation', false)->where('track_count', '>=', 3)->count();
        $counts = $this->directory->namesInCollection();
        $solo = [];
        foreach (ArtistNames::suggestSplit($name) as $p) {
            $solo[$p] = (int) ($counts[$p] ?? 0) + ($this->directory->check($p)['status'] === 'ok' ? 1 : 0);
        }

        return ['own_albums' => $ownAlbums, 'parts' => $solo, 'parts_solo' => $solo && count(array_filter($solo)) === count($solo)];
    }
}
