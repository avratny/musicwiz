<?php

namespace App\Support;

use App\Jobs\PrefetchArtistLookup;
use App\Jobs\StoreArtistImage;
use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Models\ArtistSplit;

/**
 * „Schnell erfassen“: noch nicht erfasste Namen mit ihrem MusicBrainz-Treffer aus dem Vorladen – eindeutige Fälle lassen
 * sich gesammelt anlegen statt einzeln im Assistenten.
 *
 * Eindeutig = genau ein Treffer hat eure Lieder (Liedprüfung) und heißt so wie der Name (bis auf Groß/klein, Satzzeichen,
 * Akzente). Alles, was eine Entscheidung braucht (ähnliche Namen im Bestand, Kombi „A & B“, mehrere Treffer), bleibt
 * beim Assistenten.
 */
class ArtistQuickMatch
{
    /** Arten, von sicher nach unklar */
    public const KINDS = [
        'sure' => 'Eindeutig',
        'name' => 'Nur der Name passt',
        'unsure' => 'Unklar',
        'pending' => 'Noch nicht nachgeschlagen',
    ];

    public function __construct(private ArtistDirectory $directory) {}

    /**
     * Alle offenen Namen (häufigste zuerst) mit Art und Treffer.
     *
     * @return list<array{name: string, key: string, count: int, kind: string, reason: string, hit: ?array}>
     */
    public function rows(): array
    {
        $counts = $this->directory->namesInCollection();
        arsort($counts);
        $open = [];
        foreach ($counts as $name => $n) {
            $status = $this->directory->check($name)['status'];
            if (in_array($status, ['new', 'similar'], true)) {
                $open[$name] = ['n' => $n, 'status' => $status];
            }
        }
        // gleicher Vergleichsschlüssel wie ein anderer offener Name: erst entscheiden, welche Schreibweise gilt
        $byKey = array_count_values(array_map(fn ($n) => ArtistNames::compareKey((string) $n), array_keys($open)));
        $results = PrefetchArtistLookup::results(array_map('strval', array_keys($open)));

        $rows = [];
        foreach ($open as $name => $o) {
            $name = (string) $name;
            [$kind, $reason, $hit] = $this->classify($name, $results[$name] ?? null);
            if ($kind !== 'pending' && $o['status'] === 'similar') {
                [$kind, $reason] = ['unsure', 'ähnlicher Eintrag im Verzeichnis – im Assistenten zuordnen'];
            } elseif ($kind !== 'pending' && ($byKey[ArtistNames::compareKey($name)] ?? 0) > 1) {
                [$kind, $reason] = ['unsure', 'mehrere Schreibweisen im Bestand – im Assistenten entscheiden'];
            } elseif ($kind !== 'pending' && ArtistNames::looksCombined($name)) {
                [$kind, $reason] = ['unsure', 'sieht nach mehreren Künstlern aus – im Assistenten trennen oder bestätigen'];
            }
            // Kombi („Gerhard Polt, Gisela Schneeberger“): Trennen gleich in der Liste anbieten
            $split = in_array($kind, ['unsure', 'pending'], true) && ArtistNames::looksCombined($name) ? ArtistNames::suggestSplit($name) : [];
            $rows[] = ['name' => $name, 'key' => md5($name), 'count' => (int) $o['n'], 'kind' => $kind, 'reason' => $reason, 'hit' => $hit,
                'split' => count($split) >= 2 ? $split : []];
        }

        return $rows;
    }

    /**
     * Bis zu drei Bildvorschläge je Zeile – nur aus dem Zwischenspeicher, die Liste wartet nie auf Dienste.
     *
     * @param  list<array>  $rows
     * @return array<string, list<array>> md5(Name) => Bilder
     */
    public function images(array $rows, Lookup $lookup): array
    {
        $out = [];
        foreach ($rows as $r) {
            if ($r['hit']) {
                $out[$r['key']] = $lookup->cachedArtistImages(array_values(array_unique([$r['name'], $r['hit']['name']])), $r['hit']['mbid']);
            }
        }

        return $out;
    }

    /** @return array{0: string, 1: string, 2: ?array} [Art, Begründung, Treffer] */
    public function classify(string $name, ?array $result): array
    {
        if ($result === null) {
            return ['pending', '', null];
        }
        $hits = $result['hits'] ?? [];
        $withSongs = array_values(array_filter($hits, fn ($h) => ! empty($h['songs'])));
        $exact = array_values(array_filter($hits, fn ($h) => ! empty($h['exact'])));
        if (count($withSongs) === 1 && ! empty($withSongs[0]['exact'])) {
            return ['sure', 'Lied „'.$withSongs[0]['songs'][0].'“ gefunden', $withSongs[0]];
        }
        if (count($withSongs) > 1) {
            return ['unsure', count($withSongs).' Treffer mit euren Liedern – im Assistenten wählen', $withSongs[0]];
        }
        if ($withSongs) {
            return ['unsure', 'Lieder passen zu „'.$withSongs[0]['name'].'“ – anderer Name', $withSongs[0]];
        }
        if (count($exact) === 1) {
            return ['name', 'gleicher Name, Lieder nicht bestätigt', $exact[0]];
        }
        if (count($exact) > 1) {
            return ['unsure', count($exact).' gleichnamige Künstler – im Assistenten wählen', null];
        }

        return ['unsure', $hits ? 'kein passender Name bei MusicBrainz' : 'bei MusicBrainz nicht gefunden', null];
    }

    /**
     * Kombi trennen (gemerkte Zusammenarbeit, wie im Aufräumen): Tags der Titel werden vorgemerkt, die einzelnen Namen
     * gleich vorgeladen – sie stehen danach selbst in der Liste.
     *
     * @param  list<string>  $parts
     * @return int vorgemerkte Titel
     */
    public function split(string $name, array $parts): int
    {
        ArtistSplit::remember($name, $parts);
        $this->directory->flush();
        $staged = app(PendingChanges::class)->stageForNames([$name], 'Trennen');
        $profiles = app(ArtistGroupAnalyzer::class)->profiles();
        foreach ($parts as $part) {
            if ($this->directory->check($part)['status'] === 'new' && ! PrefetchArtistLookup::isFresh($part) && config('queue.default') !== 'sync') {
                PrefetchArtistLookup::dispatch($part, array_slice(array_values($profiles[$part]['titles'] ?? $profiles[$name]['titles'] ?? []), 0, 3));
            }
        }

        return $staged;
    }

    /**
     * Ausgewählte Namen anlegen: Eintrag mit MusicBrainz-ID, Standard-Genre (Bestand, sonst MusicBrainz) und den
     * Schreibweisen von MusicBrainz; mit MusicBrainz-Schreibweise bleibt der Bestandsname als Schreibweise erhalten.
     *
     * @param  list<array{name: string, hit: array, mb_spelling: bool, image?: ?string}>  $picks  image: gewähltes Bild (URL)
     * @return array{created: int, staged: int}
     */
    public function save(array $picks, Lookup $lookup, ArtistGenreSuggestions $genres): array
    {
        $created = 0;
        $changed = [];
        foreach ($picks as $p) {
            $orig = $p['name'];
            $hit = $p['hit'];
            $final = $p['mb_spelling'] ? trim((string) $hit['name']) : $orig;
            $artist = Artist::where('name', $final)->first();
            if (! $artist) {
                $artist = Artist::create(['name' => $final]);
                $created++;
            }
            $artist->fill(array_filter(['mbid' => $artist->mbid ?: $hit['mbid']]))->save();
            if ($final !== $orig) {
                ArtistAlias::updateOrCreate(['alias' => $orig], ['artist_id' => $artist->id, 'source' => 'musicbrainz']);
                $changed[] = $orig;
                $changed[] = $final;
            }
            // Schreibweisen und Genres liegen vom Vorladen im Zwischenspeicher
            $aliases = rescue(fn () => $lookup->musicbrainzArtistAliases($hit['mbid']), [], report: false);
            $added = $aliases ? $this->directory->importMusicBrainzAliases($artist, $aliases) : [];
            if ($added) {
                array_push($changed, $final, ...$added);
            }
            if (! empty($p['image']) && ! $artist->image) {
                StoreArtistImage::dispatch($artist->id, $p['image']);
            }
            if (! $artist->default_genre) {
                $genre = ArtistGenreSuggestions::best($genres->for($final, $artist->mbid, null, $artist->fresh('aliases'))['proposals']);
                $genre && $artist->update(['default_genre' => $genre]);
            }
        }
        $this->directory->flush();
        // Tags der betroffenen Titel gleich vormerken (wie im Assistenten)
        $staged = $changed ? app(PendingChanges::class)->stageForNames($changed, 'Schnell erfassen') : 0;

        return ['created' => $created, 'staged' => $staged];
    }
}
