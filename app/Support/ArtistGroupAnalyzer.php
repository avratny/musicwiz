<?php

namespace App\Support;

use App\Models\Track;
use Illuminate\Support\Facades\Cache;

/**
 * Bewertet Vorschlagsgruppen („dieselbe Person?“) anhand der Lieder im Bestand und optional der MusicBrainz-Recherche.
 * Ergebnis sind Wahrscheinlichkeiten mit Begründung – entschieden wird immer per Klick.
 */
class ArtistGroupAnalyzer
{
    public const RESEARCH_KEY = 'artist-research:';

    private ?array $profiles = null;

    public function __construct(private Settings $settings) {}

    /**
     * Profil je Name: Lieder, Alben, Mitwirkende, Genres.
     *
     * @return array<string, array{count:int, titles:array<string,string>, albums:array<string,int>, co:array<string,int>, genres:array<string,int>}>
     */
    public function profiles(): array
    {
        if ($this->profiles !== null) {
            return $this->profiles;
        }

        return $this->profiles = Cache::remember('artist-profiles:v5:'.DataVersion::tracks(), now()->addHour(), function () {
            $va = mb_strtolower((string) $this->settings->get('compilation_artist'));
            $out = [];
            // nur der Bestand – die Inbox wird erst nach dem Einordnen relevant
            $query = Track::where('location', 'library')->select(['id', 'album_id', 'duration', 'artist', 'artists', 'albumartist', 'albumartists', 'title', 'album', 'genres', 'compilation'])
                ->orderBy('album')->orderByRaw('CAST(track AS INTEGER)');
            foreach ($query->cursor() as $t) {
                $trackArtists = $t->artists ?: ArtistNames::split($t->artist);
                $names = array_unique(array_merge($trackArtists, ArtistDirectory::albumArtistNames($t)));
                foreach ($names as $n) {
                    if ($n === '' || mb_strtolower($n) === $va) {
                        continue;
                    }
                    $p = &$out[$n];
                    $p['count'] = ($p['count'] ?? 0) + 1;
                    if (in_array($n, $trackArtists, true) && $t->title) {
                        $p['titles'][self::normTitle($t->title)] = $t->title;
                    }
                    // Hörproben: verschiedene Alben bevorzugen, höchstens 12
                    if (count($p['tracks'] ?? []) < 12) {
                        $p['tracks'][] = ['id' => $t->id, 'album_id' => $t->album_id, 'seconds' => (float) $t->duration, 'title' => (string) $t->title, 'artist' => (string) $t->artist, 'album' => (string) $t->album];
                    }
                    if ($t->album && ! $t->compilation) {
                        $k = self::normTitle($t->album);
                        $p['albums'][$k] = ($p['albums'][$k] ?? 0) + 1;
                    }
                    foreach ($trackArtists as $co) {
                        if ($co !== $n) {
                            $k = ArtistNames::compareKey($co);
                            $p['co'][$k] = ($p['co'][$k] ?? 0) + 1;
                        }
                    }
                    foreach ($t->genres ?? [] as $g) {
                        $p['genres'][$g] = ($p['genres'][$g] ?? 0) + 1;
                    }
                    unset($p);
                }
            }

            return $out;
        });
    }

    /** Bezugsname: Verzeichnis-Eintrag, sonst häufigste Schreibweise der größten Familie (gleicher Vergleichsschlüssel). */
    public static function mainName(array $group): string
    {
        $names = $group['names'];
        if (($group['known'] ?? null) && isset($names[$group['known']])) {
            return $group['known'];
        }
        $families = [];
        foreach ($names as $n => $c) {
            $families[ArtistNames::compareKey($n)] = ($families[ArtistNames::compareKey($n)] ?? 0) + $c;
        }
        arsort($families);
        $bestKey = array_key_first($families);
        $inFamily = array_filter($names, fn ($n) => ArtistNames::compareKey($n) === $bestKey, ARRAY_FILTER_USE_KEY);
        arsort($inFamily);

        return (string) array_key_first($inFamily);
    }

    /** MusicBrainz-Schreibweise für Dateien: typografische Bindestriche → "-". */
    public static function cleanMbName(string $name): string
    {
        return str_replace(["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2019}"], ['-', '-', '-', '-', "'"], $name);
    }

    public static function normTitle(?string $s): string
    {
        $s = mb_strtolower((string) $s);
        $s = preg_replace('/[\(\[].*?[\)\]]/u', ' ', $s);               // Mix-/Versionsangaben
        $s = preg_replace('/\s+(feat\.?|ft\.|featuring)\s.*$/u', '', $s);
        $s = ArtistNames::stripAccents($s);

        return preg_replace('/[^a-z0-9]+/', '', $s) ?? '';
    }

    /**
     * @param  array{key:string, names:array<string,int>, suggestion:string, known:?string}  $group
     */
    public function analyze(array $group): array
    {
        $profiles = $this->profiles();
        $names = $group['names'];
        arsort($names);
        $main = self::mainName($group);
        $research = $this->research($group);
        $mainP = $profiles[$main] ?? [];

        $variants = [];
        foreach ($names as $name => $count) {
            $p = $profiles[$name] ?? [];
            $variants[$name] = [
                'count' => $count,
                'samples' => array_slice(array_values($p['titles'] ?? []), 0, 6),
                'tracks' => $p['tracks'] ?? [],
                'is_main' => $name === $main,
                'probability' => null,
                'reasons' => [],
            ];
            if ($name === $main) {
                continue;
            }
            [$score, $reasons] = $this->pairScore($main, $name, $mainP, $p, $research);
            $variants[$name]['probability'] = 1 / (1 + exp(-($score - 1)));
            $variants[$name]['reasons'] = $reasons;
        }

        $others = array_filter(array_column($variants, 'probability'), fn ($v) => $v !== null);
        [$recommendation, $recReasons, $mbName] = $this->recommend($names, $group, $research, $variants);

        return [
            'main' => $main,
            'variants' => $variants,
            'confidence' => $others ? min($others) : 1.0,
            'recommendation' => $recommendation,
            'recommendation_reasons' => $recReasons,
            'mb_name' => $mbName,
            'research' => $research,
        ];
    }

    private function pairScore(string $main, string $name, array $mainP, array $p, ?array $research): array
    {
        $score = 0.0;
        $reasons = [];
        $sameKey = ArtistNames::compareKey($main) === ArtistNames::compareKey($name);
        if (mb_strtolower($main) === mb_strtolower($name)) {
            $score += 4.5;
            $reasons[] = ['+', 'nur Groß-/Kleinschreibung anders'];
        } elseif ($sameKey) {
            $score += 3;
            $reasons[] = ['+', 'nur Satzzeichen, Leerzeichen oder Akzente anders'];
        } else {
            $d = levenshtein(ArtistNames::compareKey($main), ArtistNames::compareKey($name));
            $score += $d <= 1 ? 0.5 : 0;
            $reasons[] = ['~', 'ähnlich geschrieben ('.$d.' Zeichen Unterschied) – möglicher Tippfehler'];
        }

        // Lieder analysieren: gleiche Titel, Alben, Mitwirkende, Genres
        $sharedTitles = array_intersect_key($mainP['titles'] ?? [], $p['titles'] ?? []);
        $sharedTitles = array_filter($sharedTitles, fn ($v, $k) => strlen($k) >= 3, ARRAY_FILTER_USE_BOTH);
        if ($sharedTitles) {
            $score += min(4, 2 * count($sharedTitles));
            $reasons[] = ['+', count($sharedTitles).' gleiche(s) Lied(er): '.implode(', ', array_slice(array_values($sharedTitles), 0, 3))];
        }
        $sharedAlbums = array_intersect_key($mainP['albums'] ?? [], $p['albums'] ?? []);
        if ($sharedAlbums) {
            $score += 1.5;
            $reasons[] = ['+', 'gleiches Album ('.count($sharedAlbums).')'];
        }
        $sharedCo = array_intersect_key($mainP['co'] ?? [], $p['co'] ?? []);
        if ($sharedCo) {
            $score += 1;
            $reasons[] = ['+', 'gleiche Mitwirkende ('.count($sharedCo).')'];
        }
        $g1 = array_keys($mainP['genres'] ?? []);
        $g2 = array_keys($p['genres'] ?? []);
        if ($g1 && $g2) {
            $jaccard = count(array_intersect($g1, $g2)) / max(1, count(array_unique(array_merge($g1, $g2))));
            if ($jaccard >= 0.3) {
                $score += 0.5;
                $reasons[] = ['+', 'ähnliche Genres'];
            } elseif ($jaccard === 0.0) {
                $score -= 0.75;
                $reasons[] = ['-', 'keine gemeinsamen Genres ('.implode(', ', array_slice($g2, 0, 2)).' vs. '.implode(', ', array_slice($g1, 0, 2)).')'];
            }
        }
        $nothingShared = ! $sameKey && ! $sharedTitles && ! $sharedAlbums && ! $sharedCo;
        $confirmed = false;

        // Recherche
        if ($research && empty($research['error'])) {
            $a = self::mainHit($research, $main);
            $b = $research['artists'][$name] ?? null;
            if ($a && $b && $a['mbid'] === $b['mbid']) {
                $score += 3;
                $reasons[] = ['+', 'MusicBrainz: derselbe Künstler ('.$a['name'].')'];
            } elseif ($a && $b && $a['exact'] && $b['exact']) {
                $score -= 3;
                $reasons[] = ['-', 'MusicBrainz: zwei verschiedene Künstler ('.self::mbLabel($a).' / '.self::mbLabel($b).')'];
            }
            $checks = $research['songs'][$name] ?? [];
            $found = array_keys(array_filter($checks));
            $own = array_keys(array_filter($research['own'][$name] ?? []));
            if ($found) {
                $confirmed = true;
                $score += 2.5;
                $reasons[] = ['+', 'MusicBrainz: „'.$found[0].'“ ist von '.($a ? self::mbLabel($a) : $main)];
                if (! ($b['exact'] ?? false)) {
                    // es gibt den Namen dort gar nicht als Künstler – typischer Tippfehler im Tag
                    $score += 1.5;
                    $reasons[] = ['+', 'MusicBrainz kennt keinen Künstler „'.$name.'“'];
                }
            } elseif ($checks && ! $sameKey) {
                $score -= 1;
                $reasons[] = ['-', 'MusicBrainz: Lieder ('.implode(', ', array_keys($checks)).') nicht bei '.($a['name'] ?? $main).' gefunden'];
            }
            if ($own && ! $found) {
                $score -= 3.5;
                $reasons[] = ['-', 'MusicBrainz: „'.$own[0].'“ ist von '.self::mbLabel($b).' – eigener Künstler'];
            }
        }
        // ohne Gemeinsamkeiten im Bestand eher verschieden – außer MusicBrainz bestätigt die Lieder
        if ($nothingShared && ! $confirmed) {
            $score -= 1;
            $reasons[] = ['-', 'keine gemeinsamen Lieder, Alben oder Mitwirkenden'];
        }

        return [$score, $reasons];
    }

    /** MusicBrainz-Treffer der Bezugsschreibweise, ersatzweise einer gleichwertigen Schreibweise. */
    private static function mainHit(array $research, string $main): ?array
    {
        if (! empty($research['artists'][$main])) {
            return $research['artists'][$main];
        }
        foreach ($research['artists'] ?? [] as $name => $hit) {
            if ($hit && ArtistNames::compareKey($name) === ArtistNames::compareKey($main)) {
                return $hit;
            }
        }

        return null;
    }

    private static function mbLabel(array $a): string
    {
        return trim($a['name'].($a['disambiguation'] ? ' – '.$a['disambiguation'] : '').($a['country'] ? ' ['.$a['country'].']' : ''));
    }

    /** Empfohlene Schreibweise: Verzeichnis > MusicBrainz > Schreibstil > Häufigkeit. */
    private function recommend(array $names, array $group, ?array $research, array $variants): array
    {
        $total = max(1, array_sum($names));
        $mbName = null;
        $main = self::mainName($group);
        $mbHit = self::mainHit($research ?? [], $main);
        if (! $mbHit) {
            $hits = array_filter($research['artists'] ?? [], fn ($a) => $a && $a['exact']);
            $mbHit = $hits ? reset($hits) : null;
        }
        if ($mbHit && $mbHit['exact']) {
            $mbName = self::cleanMbName($mbHit['name']);
        }

        $candidates = $names;
        // MusicBrainz-Schreibweise als eigene Möglichkeit, wenn sie im Bestand nicht vorkommt und dateitauglich ist
        if ($mbName !== null && ! isset($candidates[$mbName]) && preg_match("/^[\\p{L}\\p{N} .,'&!+\\-]+$/u", $mbName)) {
            $candidates[$mbName] = 0;
        }

        $best = null;
        $bestScore = -INF;
        $bestReasons = [];
        foreach ($candidates as $name => $count) {
            if (($variants[$name]['probability'] ?? 1) < 0.4) {
                continue; // vermutlich andere Person
            }
            $score = 2 * $count / $total;
            $reasons = $count ? [round(100 * $count / $total).' % der Dateien'] : [];
            if ($group['known'] === $name) {
                $score += 5;
                $reasons[] = 'steht im Verzeichnis';
            }
            if ($mbName !== null && $mbName === $name) {
                $score += 2;
                $reasons[] = 'Schreibweise wie bei MusicBrainz';
            }
            if (preg_match('/,\s*(The|Die)$/u', $name)) {
                $score -= 1;
                $reasons[] = '„, The“ am Ende (wird nicht umsortiert)';
            }
            if ($name === mb_strtolower($name) && preg_match('/\p{L}{3,}/u', $name) && $mbName !== $name) {
                $score -= 0.6;
                $reasons[] = 'alles klein geschrieben';
            }
            if ($name === mb_strtoupper($name) && mb_strlen(preg_replace('/\W/u', '', $name)) > 4 && $mbName !== $name) {
                $score -= 0.4;
                $reasons[] = 'alles groß geschrieben';
            }
            if ($name !== $main && ArtistNames::compareKey($name) !== ArtistNames::compareKey($main) && $count) {
                $score -= 0.5; // vermutlich Tippfehler-Variante
            }
            if ($score > $bestScore) {
                [$best, $bestScore, $bestReasons] = [$name, $score, $reasons];
            }
        }
        $rawMb = $mbHit['name'] ?? null;
        if ($rawMb !== null && $rawMb !== $best && ! in_array('Schreibweise wie bei MusicBrainz', $bestReasons, true)) {
            $bestReasons[] = 'MusicBrainz schreibt „'.$rawMb.'“';
        }

        return [$best ?? $main, $bestReasons, $mbName];
    }

    public static function researchKey(array $group): string
    {
        $names = array_keys($group['names']);
        sort($names);

        return self::RESEARCH_KEY.md5(implode('|', $names));
    }

    public function research(array $group): ?array
    {
        return Cache::get(self::researchKey($group));
    }

    /** MusicBrainz befragen (langsam, ca. 1 Abfrage/s – läuft als Job). */
    public function runResearch(array $group, Lookup $lookup): array
    {
        $key = self::researchKey($group);
        Cache::forever($key, ['running' => true]);
        $result = ['artists' => [], 'songs' => [], 'at' => now()->toIso8601String()];
        try {
            foreach (array_keys($group['names']) as $name) {
                $hit = null;
                foreach ($lookup->musicbrainzArtists($name) as $a) {
                    if ($a['score'] >= 90) {
                        $hit = $a + ['exact' => ArtistNames::compareKey($a['name']) === ArtistNames::compareKey($name)];
                        break;
                    }
                }
                $result['artists'][$name] = $hit;
            }
            // Lieder analysieren: Wem gehören die Lieder der Variante laut MusicBrainz?
            //  songs: beim Künstler der Hauptschreibweise gefunden → dieselbe Person (Tippfehler im Tag)
            //  own:   bei einem gleichnamigen eigenen Künstler gefunden → verschiedene Personen
            $main = self::mainName($group);
            $mainHit = self::mainHit($result, $main);
            $profiles = $this->profiles();
            foreach (array_keys($group['names']) as $name) {
                $hit = $result['artists'][$name] ?? null;
                if ($name === $main || ($mainHit && ($hit['mbid'] ?? null) === $mainHit['mbid'])) {
                    continue;
                }
                $titles = array_slice(array_values($profiles[$name]['titles'] ?? []), 0, 3);
                foreach ($mainHit ? $titles : [] as $title) {
                    $result['songs'][$name][$title] = $lookup->musicbrainzRecordingByArtist($mainHit['mbid'], $title);
                }
                if ($hit && $hit['exact']) {
                    foreach ($titles as $title) {
                        $result['own'][$name][$title] = $lookup->musicbrainzRecordingByArtist($hit['mbid'], $title);
                    }
                }
            }
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }
        Cache::forever($key, $result);

        return $result;
    }
}
