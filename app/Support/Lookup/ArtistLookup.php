<?php

namespace App\Support\Lookup;

use App\Jobs\FetchPreviews;
use App\Models\Artist;
use App\Models\ArtistImage;
use App\Models\ArtistSource;
use App\Support\ArtistGenres;
use App\Support\ArtistMatcher;
use App\Support\MusicBrainzData;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Abgleich eines Interpreten: zuerst MusicBrainz (MBID, Schreibweisen, Links), dann Spotify und Discogs über diese
 * Links (sonst per Namenssuche), TheAudioDB, Fanart.tv und Last.fm über die MBID (Last.fm sonst per Name). Ergebnisse landen als Treffer mit
 * Schreibweisen und Bildern in der Datenbank (Vorschauen lokal) – am Interpreten selbst ändert sich nichts.
 */
class ArtistLookup
{
    /** höchstens so viele Treffer je Dienst */
    private const PER_SERVICE = 3;

    public function __construct(
        private MusicBrainz $musicbrainz,
        private Spotify $spotify,
        private Discogs $discogs,
        private AudioDb $audiodb,
        private FanartTv $fanart,
        private Client $client,
        private LastFm $lastfm,
    ) {}

    /** @return array<string, string> Dienst => Status (ok, nicht_gefunden, fehler, gesperrt, uebersprungen) */
    public function run(Artist $artist): array
    {
        $spellings = $artist->spellings();
        $status = [];

        $mb = $this->attempt($status, 'musicbrainz', fn () => $this->matching($this->musicbrainz->search($artist->name), $spellings, true));
        $mbids = array_column($mb ?? [], 'mbid');
        $linked = ['spotify' => [], 'discogs' => []];
        foreach ($mb ?? [] as $c) {
            foreach (['spotify', 'discogs'] as $service) {
                foreach ($c['links'][$service] ?? [] as $id) {
                    $linked[$service][$id] = $c['mbid'];
                }
            }
        }

        // Spotify nur, wenn es etwas beiträgt: bei eindeutigen Interpreten (ein MusicBrainz-Eintrag, keine Gleichnamigen)
        // bringt es nichts Neues – die Spotify-ID steht dann schon als Verweis bei MusicBrainz. Spotify ist zudem oft langsam.
        $clear = $this->clear($artist, $mb ?? []);
        $spotify = ! $this->spotify->available() ? $this->skip($status, 'spotify')
            : ($clear ? $this->skip($status, 'spotify', 'nicht nötig – eindeutig über MusicBrainz') : $this->attempt($status, 'spotify', function () use ($linked, $spellings, $artist) {
                if ($linked['spotify']) {
                    return array_map(fn ($c) => $c + ['mbid' => $linked['spotify'][$c['external_id']] ?? null, 'match' => 'verknuepft'] + $this->matchInfo($c['name'], [], $spellings),
                        $this->spotify->byIds(array_keys($linked['spotify'])));
                }

                return $this->matching($this->spotify->search($artist->name), $spellings);
            }));

        // Discogs ebenso nur bei Bedarf: bringt bei eindeutigen Interpreten nichts Neues (die ID steht als Verweis bei
        // MusicBrainz), kostet aber die meisten Abfragen (Suche, Details, Veröffentlichungen) und ist der Engpass.
        // Für Kombis fragt die Namensprüfung Discogs weiterhin immer.
        $discogs = ! $this->discogs->available() ? $this->skip($status, 'discogs')
            : ($clear ? $this->skip($status, 'discogs', 'nicht nötig – eindeutig über MusicBrainz') : $this->attempt($status, 'discogs', function () use ($linked, $spellings, $artist) {
                if ($linked['discogs']) {
                    $out = [];
                    foreach (array_slice(array_keys($linked['discogs']), 0, self::PER_SERVICE) as $id) {
                        if ($c = $this->discogs->artist((string) $id)) {
                            $out[] = $c + ['mbid' => $linked['discogs'][$id], 'match' => 'verknuepft'] + $this->matchInfo($c['name'], [], $spellings);
                        }
                    }

                    return $out;
                }
                $hits = $this->matching(array_map(fn ($h) => ['external_id' => $h['id'], 'name' => $h['name'], 'score' => 0, 'aliases' => []], $this->discogs->search($artist->name)), $spellings);

                return array_values(array_filter(array_map(fn ($h) => ($c = $this->discogs->artist($h['external_id'], $h['score'])) ? $c + ['match' => $h['match']] : null,
                    array_slice($hits, 0, 2))));
            }));

        $audiodb = $this->attempt($status, 'audiodb', function () use ($mbids, $spellings, $artist) {
            if ($mbids) {
                return array_values(array_filter(array_map(fn ($mbid) => ($c = $this->audiodb->byMbid($mbid)) ? $c + $this->matchInfo($c['name'], [], $spellings) + ['match' => 'verknuepft'] : null, $mbids)));
            }

            return $this->matching($this->audiodb->search($artist->name), $spellings);
        });

        $fanart = ! $this->fanart->available() ? $this->skip($status, 'fanart')
            : (! $mbids ? $this->skip($status, 'fanart', 'ohne MusicBrainz-ID') : $this->attempt($status, 'fanart', fn () => array_values(array_filter(array_map(
                fn ($mbid) => ($c = $this->fanart->byMbid($mbid)) ? $c + ['match' => 'verknuepft'] : null, $mbids)))));

        // Last.fm: über die MusicBrainz-ID genau derselbe, sonst nach Namen (Last.fm korrigiert die Schreibweise)
        $lastfm = ! $this->lastfm->available() ? $this->skip($status, 'lastfm') : $this->attempt($status, 'lastfm', function () use ($mbids, $spellings, $artist) {
            foreach (array_slice($mbids, 0, self::PER_SERVICE) as $mbid) {
                if ($c = $this->lastfm->byMbid($mbid)) {
                    return [$c + ['mbid' => $mbid, 'match' => 'verknuepft'] + self::matchInfo($c['name'], [], $spellings)];
                }
            }

            return $this->matching(array_filter([$this->lastfm->byName($artist->name)]), $spellings);
        });

        // Veröffentlichungen je Treffer (MusicBrainz: Release-Groups, Discogs) – hilft beim Einordnen
        $mb = $this->withReleases($mb, fn ($c) => $this->musicbrainz->releaseCount($c['external_id']));
        // Verweise auf Spotify und Discogs mitspeichern: übersprungene Dienste – die IDs kommen beim Wählen von dort
        $mb = $mb === null ? null : array_map(fn ($c) => array_filter($c['links'] ?? []) ? array_replace_recursive($c, ['data' => ['links' => array_filter($c['links'])]]) : $c, $mb);
        $discogs = $this->withReleases($discogs, fn ($c) => $this->discogs->releaseCount((string) $c['external_id']));

        foreach (['musicbrainz' => $mb, 'spotify' => $spotify, 'discogs' => $discogs, 'audiodb' => $audiodb, 'fanart' => $fanart, 'lastfm' => $lastfm] as $service => $candidates) {
            if ($candidates !== null) {
                $this->store($artist, $service, $candidates);
            }
        }
        // Stand je Dienst merken (auch „nicht gefunden“ und Fehler) – bestimmt, wann wieder gefragt wird
        $messages = $status['__messages'] ?? [];
        unset($status['__messages']);
        foreach ($status as $service => $state) {
            DB::table('artist_lookups')->updateOrInsert(['artist_id' => $artist->id, 'service' => $service],
                ['status' => $state, 'message' => isset($messages[$service]) ? mb_substr($messages[$service], 0, 255) : null, 'fetched_at' => now()]);
        }
        $artist->forceFill(['looked_up_at' => now()])->save();
        app(ArtistGenres::class)->sync($artist);
        FetchPreviews::dispatch($artist->id);   // Vorschaubilder im Hintergrund (eigener Worker)

        return $status;
    }

    /**
     * Eindeutig über MusicBrainz? Lokale Daten, genau ein passender Treffer (Name genau, Groß/klein oder nur Zeichen
     * anders) und kein anderer Interpret heißt so.
     */
    private function clear(Artist $artist, array $mb): bool
    {
        return app(MusicBrainzData::class)->available() && count($mb) === 1
            && in_array($mb[0]['match'], ['exakt', 'gross_klein', 'zeichen'], true) && $this->musicbrainz->namesakes($artist->name) === 1;
    }

    /** Zahl der Veröffentlichungen in data['releases'] – fehlt sie (Fehler, Sperre), bleibt der Treffer trotzdem */
    private function withReleases(?array $candidates, callable $count): ?array
    {
        return $candidates === null ? null : array_map(function ($c) use ($count) {
            try {
                $n = $count($c);
            } catch (Throwable) {
                $n = null;
            }

            return $n === null ? $c : array_replace_recursive($c, ['data' => ['releases' => $n]]);
        }, $candidates);
    }

    /**
     * Treffer, die zum Namen passen – Bewertung: exakt, groß/klein, gleich bis auf Zeichen, bekannte Schreibweise (Alias),
     * ähnlich. Andere fallen weg.
     */
    private function matching(array $candidates, array $spellings, bool $keepMbScore = false): array
    {
        $out = [];
        foreach ($candidates as $c) {
            $info = $this->matchInfo($c['name'], array_column($c['aliases'] ?? [], 'alias'), $spellings);
            if ($info['match'] === null) {
                continue;
            }
            $out[] = array_merge($c, $info, ['score' => $keepMbScore ? min((int) $c['score'], $info['score']) : $info['score']]);
        }
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($out, 0, self::PER_SERVICE);
    }

    /** @return array{match: ?string, score: int} */
    public static function matchInfo(string $name, array $aliases, array $spellings): array
    {
        $key = ArtistMatcher::key($name);
        foreach ($spellings as $s) {
            if ($name === $s) {
                return ['match' => 'exakt', 'score' => 100];
            }
        }
        foreach ($spellings as $s) {
            if (mb_strtolower($name) === mb_strtolower($s)) {
                return ['match' => 'gross_klein', 'score' => 95];
            }
            if ($key !== '' && $key === ArtistMatcher::key($s)) {
                return ['match' => 'zeichen', 'score' => 90];
            }
        }
        foreach ($aliases as $alias) {
            foreach ($spellings as $s) {
                if (ArtistMatcher::key((string) $alias) === ArtistMatcher::key($s)) {
                    return ['match' => 'schreibweise', 'score' => 85];
                }
            }
        }
        foreach ($spellings as $s) {
            $k = ArtistMatcher::key($s);
            if (mb_strlen($k) >= 4 && levenshtein($key, $k) <= (mb_strlen($k) >= 10 ? 2 : 1)) {
                return ['match' => 'aehnlich', 'score' => 70];
            }
        }

        return ['match' => null, 'score' => 0];
    }

    /** Dienst abfragen; Fehler und Sperren werden festgehalten, der Abgleich der anderen Dienste läuft weiter. */
    private function attempt(array &$status, string $service, callable $fn): ?array
    {
        try {
            $result = $fn();
            $status[$service] = $result ? 'ok' : 'nicht_gefunden';

            return $result;
        } catch (Throttled $e) {
            // gesperrt: wird nachgeholt, sobald der Dienst wieder antwortet (LookupArtists::pending)
            $status[$service] = 'gesperrt';
            $status['__messages'][$service] = $e->getMessage();

            return null;
        } catch (Throwable $e) {
            // jeder andere Fehler (auch keine Antwort) betrifft nur diesen Dienst – die übrigen Ergebnisse werden gespeichert
            $status[$service] = 'fehler';
            $status['__messages'][$service] = mb_substr($e->getMessage(), 0, 255);

            return null;
        }
    }

    private function skip(array &$status, string $service, string $why = 'kein Zugang in den Einstellungen'): ?array
    {
        $status[$service] = 'uebersprungen';
        $status['__messages'][$service] = $why;

        return null;
    }

    /** Treffer eines Dienstes speichern: neue anlegen, vorhandene aktualisieren, nicht mehr gelieferte entfernen (außer gewählte). */
    private function store(Artist $artist, string $service, array $candidates): void
    {
        DB::transaction(function () use ($artist, $service, $candidates) {
            $keep = [];
            foreach ($candidates as $c) {
                $source = ArtistSource::updateOrCreate(
                    ['artist_id' => $artist->id, 'service' => $service, 'external_id' => (string) $c['external_id']],
                    ['name' => mb_substr((string) $c['name'], 0, 255), 'match' => $c['match'] ?? null, 'score' => (int) ($c['score'] ?? 0),
                        'mbid' => $c['mbid'] ?? null, 'data' => $c['data'] ?? [], 'fetched_at' => now()],
                );
                $keep[] = $source->id;
                $source->aliases()->delete();
                foreach (array_slice($c['aliases'] ?? [], 0, 60) as $a) {
                    if (trim((string) $a['alias']) !== '') {
                        $source->aliases()->create(['alias' => mb_substr(trim($a['alias']), 0, 255), 'type' => $a['type'] ? mb_substr($a['type'], 0, 32) : null,
                            'locale' => $a['locale'] ? mb_substr($a['locale'], 0, 16) : null, 'primary' => (bool) $a['primary']]);
                    }
                }
                $known = $source->images()->get()->keyBy('url');
                $urls = [];
                foreach (array_values($c['images'] ?? []) as $rank => $img) {
                    $urls[] = $img['url'];
                    if (! isset($known[$img['url']])) {
                        $source->images()->create(['kind' => $img['kind'], 'url' => $img['url'], 'preview_url' => $img['preview_url'] ?? null,
                            'width' => $img['width'], 'height' => $img['height'], 'rank' => $rank]);
                    }
                }
                foreach ($known as $url => $old) {
                    if (! in_array($url, $urls, true)) {
                        $this->forgetImage($old);
                    }
                }
            }
            ArtistSource::where('artist_id', $artist->id)->where('service', $service)->whereNotIn('id', $keep)->where('chosen', false)
                ->get()->each(function (ArtistSource $s) {
                    $s->images->each(fn ($i) => $this->forgetImage($i));
                    $s->delete();
                });
        });
    }

    private function forgetImage(ArtistImage $image): void
    {
        if ($path = $image->previewPath()) {
            @unlink($path);
        }
        $image->delete();
    }
}
