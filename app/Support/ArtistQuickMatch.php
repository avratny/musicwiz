<?php

namespace App\Support;

use App\Jobs\PrefetchArtistLookup;
use App\Jobs\PrefetchUnknownArtists;
use App\Jobs\StoreArtistImage;
use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Models\ArtistSplit;
use Illuminate\Support\Facades\Cache;

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
        'standard' => 'Standard',
        'sure' => 'Eindeutig',
        'service' => 'Deezer/Spotify',
        'typo' => 'Tippfehler?',
        'name' => 'Nur der Name passt',
        'unsure' => 'Unklar',
        'pending' => 'Noch nicht nachgeschlagen',
    ];

    /** Arten mit Häkchen und Bildauswahl (gesammelt anlegen) */
    public const SELECTABLE = ['sure', 'service', 'name'];

    public function __construct(private ArtistDirectory $directory) {}

    /**
     * Alle offenen Namen (häufigste zuerst) mit Art und Treffer.
     *
     * @return list<array{name: string, key: string, count: int, kind: string, reason: string, hit: ?array}>
     */
    /** Zähler: neue Vorlade- bzw. Kombi-Ergebnisse (die gespeicherte Liste wird dann neu gebildet) */
    public const RESULTS_BUMP = 'artist-results:bump';

    private ?array $rows = null;

    /**
     * Wie rows(), aber als Ganzes gespeichert – vorher wurden bei jedem Klick (auch beim Setzen eines Häkchens) die
     * Vorlade-Ergebnisse aller offenen Namen einzeln gelesen. Neu gebildet nur, wenn sich Bestand, Verzeichnis oder
     * Ergebnisse ändern.
     */
    public function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }
        $base = DataVersion::artistContent().':'.DataVersion::splits().':'.DataVersion::directory();
        $key = 'artist-quick-rows:v1:'.$base.':'.(int) Cache::get(self::RESULTS_BUMP, 0);
        if (($rows = Cache::get($key)) !== null) {
            return $this->rows = $rows;
        }
        // Vorladen läuft (neue Ergebnisse im Sekundentakt): höchstens einmal pro Minute neu bilden, sonst letzten Stand zeigen
        $last = Cache::get('artist-quick-rows:last');
        if ($last && $last['base'] === $base && now()->diffInSeconds($last['at'], true) < 60 && (PrefetchUnknownArtists::status()['running'] ?? false)) {
            return $this->rows = $last['rows'];
        }
        $rows = $this->buildRows();
        Cache::put($key, $rows, now()->addDay());
        Cache::put('artist-quick-rows:last', ['base' => $base, 'at' => now()->toIso8601String(), 'rows' => $rows], now()->addHour());

        return $this->rows = $rows;
    }

    private function buildRows(): array
    {
        $open = $this->directory->openNames();
        // gleicher Vergleichsschlüssel wie ein anderer offener Name: erst entscheiden, welche Schreibweise gilt
        $byKey = array_count_values(array_column($open, 'key'));
        $results = PrefetchArtistLookup::results(array_map('strval', array_keys($open)));
        // Kombi-Prüfung (eingetragene Künstler bei Deezer, Spotify, MusicBrainz) – nur gespeicherte Ergebnisse
        $combis = app(CombiCheck::class)->cachedMany(array_values(array_filter(array_map('strval', array_keys($open)), fn ($n) => CombiCheck::applies($n))));

        $rows = [];
        foreach ($open as $name => $o) {
            $name = (string) $name;
            [$kind, $reason, $hit] = $this->classify($name, $results[$name] ?? null);
            [$kind, $reason, $hit, $suggest] = $this->withServices($name, $results[$name] ?? null, $kind, $reason, $hit);
            if ($kind !== 'pending' && $o['status'] === 'similar') {
                [$kind, $reason] = ['unsure', 'ähnlicher Eintrag im Verzeichnis – im Assistenten zuordnen'];
            } elseif ($kind !== 'pending' && ($byKey[$o['key']] ?? 0) > 1) {
                [$kind, $reason] = ['unsure', 'mehrere Schreibweisen im Bestand – im Assistenten entscheiden'];
            } elseif ($kind !== 'pending' && CombiCheck::applies($name)) {
                // Kombi-Name: eindeutig nur, wenn die Dienste ihn als einen Act eintragen
                $combi = $combis[$name] ?? null;
                if (($combi['verdict'] ?? null) === 'collab') {
                    [$kind, $reason] = ['unsure', 'Zusammenarbeit laut Diensten ('.implode(' + ', $combi['parts']).') – trennen'];
                } elseif (($combi['verdict'] ?? null) !== 'act' || ! in_array($kind, ['sure', 'standard'], true)) {
                    [$kind, $reason] = ['unsure', ($combi ? 'Kombi-Prüfung: '.$combi['reason'] : 'sieht nach mehreren Künstlern aus').' – im Assistenten trennen oder bestätigen'];
                } else {
                    $reason .= ' · Act laut Diensten';
                }
            }
            $combi = $combis[$name] ?? null;
            // Kombi („Gerhard Polt, Gisela Schneeberger“): Trennen gleich in der Liste anbieten – mit den Namen, wie die Dienste sie eintragen
            $split = in_array($kind, ['unsure', 'pending'], true) && ArtistNames::looksCombined($name) && ($combi['verdict'] ?? null) !== 'act'
                ? (($combi['verdict'] ?? null) === 'collab' ? $combi['parts'] : ArtistNames::suggestSplit($name)) : [];
            $services = $results[$name]['services'] ?? null;
            $rows[] = ['name' => $name, 'key' => md5($name), 'count' => (int) $o['n'], 'kind' => $kind, 'reason' => $reason, 'hit' => $hit,
                'suggest' => $kind === 'typo' ? $suggest : null, 'services' => $services, 'checked' => is_array($services),
                'split' => count($split) >= 2 ? array_values($split) : [], 'combi' => $combi ? ['verdict' => $combi['verdict'], 'reason' => $combi['reason']] : null];
        }

        return $rows;
    }

    /**
     * Deezer und Spotify dazunehmen – unter welchem Künstler führen sie eure Lieder?
     * - MusicBrainz eindeutig und ein weiterer Dienst bestätigt → „Standard“ (gesammelt übernehmen)
     * - MusicBrainz nicht eindeutig, aber Deezer/Spotify führen eure Lieder unter genau diesem Namen → „Deezer/Spotify“
     *   (MusicBrainz-ID nur, wenn es dort genau einen gleichnamigen gibt)
     * - Lieder unter einem sehr ähnlichen Namen → „Tippfehler?“ (Vorschlag: Schreibweise von …)
     *
     * @return array{0: string, 1: string, 2: ?array, 3: ?string} [Art, Begründung, Treffer, vorgeschlagener Name]
     */
    private function withServices(string $name, ?array $result, string $kind, string $reason, ?array $hit): array
    {
        $services = $result['services'] ?? null;
        if (! $services || $kind === 'pending') {
            return [$kind, $reason, $hit, null];
        }
        $confirm = array_filter(['Deezer' => $services['deezer'] ?? null, 'Spotify' => $services['spotify'] ?? null], fn ($s) => $s && $s['exact']);
        $alt = collect([$services['deezer'] ?? null, $services['spotify'] ?? null])->first(fn ($s) => $s && ! $s['exact']);
        $via = fn () => implode(' + ', array_keys($confirm)).': '.reset($confirm)['via'];
        if ($kind === 'sure') {
            return $confirm ? ['standard', 'MusicBrainz: '.str_replace(' gefunden', '', $reason).' · '.$via(), $hit, null] : [$kind, $reason, $hit, null];
        }
        $hits = $result['hits'] ?? [];
        $withSongs = array_values(array_filter($hits, fn ($h) => ! empty($h['songs'])));
        $exact = array_values(array_filter($hits, fn ($h) => ! empty($h['exact'])));
        if ($confirm && ! $withSongs) {
            $mb = count($exact) === 1 ? ['name' => ArtistNames::plainPunctuation((string) $exact[0]['name'])] + $exact[0] : null;
            $note = $mb ? 'gleichnamig bei MusicBrainz' : (count($exact) > 1 ? count($exact).' gleichnamige bei MusicBrainz – ohne MusicBrainz-ID' : 'nicht bei MusicBrainz');

            return ['service', $via().' · '.$note, $mb, null];
        }
        // Tippfehler: ein Dienst (oder MusicBrainz' Liedprüfung) findet eure Lieder unter einem sehr ähnlichen Namen
        $mbAlt = count($withSongs) === 1 && empty($withSongs[0]['exact']) && Lookup::similarName($name, (string) $withSongs[0]['name']) ? (string) $withSongs[0]['name'] : null;
        if (! $confirm && ($alt || $mbAlt)) {
            $target = ArtistNames::plainPunctuation($mbAlt ?? $alt['name']);
            $from = $mbAlt ? 'MusicBrainz' : (($services['deezer'] ?? null) === $alt ? 'Deezer' : 'Spotify');

            return ['typo', $from.' führt eure Lieder unter „'.$target.'“'.($alt && ! $mbAlt ? ' ('.$alt['via'].')' : ''), $mbAlt ? $withSongs[0] : null, $target];
        }
        if ($confirm) {
            $reason .= ' · '.$via();
        }

        return [$kind, $reason, $hit, null];
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
            if ($r['hit'] || ($r['services'] ?? null)) {
                $out[$r['key']] = $this->imagesFor($r, $lookup);
            }
        }

        return $out;
    }

    /**
     * Bilder einer Zeile: über die MusicBrainz-ID (TheAudioDB, Fanart.tv) bzw. über eure Lieder gefunden (Spotify, Deezer) –
     * mit Liedprüfung bei Deezer fällt der Treffer „gleicher Name“ weg (könnte ein Namensvetter sein).
     *
     * @return list<array{url: string, thumb: string, source: string, size: string}>
     */
    public function imagesFor(array $r, Lookup $lookup, int $limit = 3): array
    {
        $deezer = ($r['services']['deezer'] ?? null) && $r['services']['deezer']['exact'] ? $r['services']['deezer']['images'] : null;
        $names = array_values(array_unique(array_filter([$r['name'], $r['hit']['name'] ?? null])));
        $list = $lookup->cachedArtistImages($names, $r['hit']['mbid'] ?? null, 6);
        if ($deezer !== null) {
            $list = [...array_filter($list, fn ($i) => ! str_starts_with((string) $i['source'], 'Deezer: ')), ...$deezer];
        }

        return array_slice(array_values($list), 0, $limit);
    }

    /**
     * Bild, das ohne Rückfrage übernommen werden darf: nur, wenn es sicher der richtige Künstler ist – über die
     * MusicBrainz-ID (TheAudioDB, Fanart.tv) oder über eines eurer Lieder (Spotify, Deezer). Sonst null.
     */
    public function safeImage(array $r, Lookup $lookup): ?string
    {
        return $this->safeImageEntry($r, $lookup)['url'] ?? null;
    }

    /** @return array{url: string, thumb: string, source: string, size: string}|null */
    public function safeImageEntry(array $r, Lookup $lookup): ?array
    {
        $best = null;
        foreach ($this->imagesFor($r, $lookup, 6) as $i) {
            $s = (string) $i['source'];
            // Reihenfolge: Foto bei TheAudioDB, Spotify, Deezer, dann Fanart.tv; Querformate (Breitbild, Fanart) nie als Künstlerbild
            $rank = match (true) {
                str_starts_with($s, 'TheAudioDB: ') => $s === 'TheAudioDB: Foto' && ($r['hit']['mbid'] ?? null) ? 0 : null,
                str_starts_with($s, 'Spotify: ') => str_contains($s, 'Lied „') ? 1 : null,
                str_starts_with($s, 'Deezer: ') => str_contains($s, 'Lied „') ? 2 : null,
                default => ($r['hit']['mbid'] ?? null) ? 3 : null, // Fanart.tv über die MusicBrainz-ID
            };
            if ($rank !== null && ($best === null || $rank < $best[0])) {
                $best = [$rank, $i];
            }
        }

        return $best[1] ?? null;
    }

    /** @return array{0: string, 1: string, 2: ?array} [Art, Begründung, Treffer] */
    public function classify(string $name, ?array $result): array
    {
        if ($result === null) {
            return ['pending', '', null];
        }
        $hits = array_map(fn ($h) => ['name' => ArtistNames::plainPunctuation((string) ($h['name'] ?? ''))] + $h, $result['hits'] ?? []);
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
     * @param  list<array{name: string, hit: ?array, mb_spelling: bool, image?: ?string}>  $picks  image: gewähltes Bild (URL);
     *                                                                                             hit null = ohne MusicBrainz-ID (nur von Deezer/Spotify bestätigt)
     * @return array{created: int, staged: int}
     */
    public function save(array $picks, Lookup $lookup, ArtistGenreSuggestions $genres): array
    {
        $created = 0;
        $changed = [];
        foreach ($picks as $p) {
            $orig = $p['name'];
            $hit = $p['hit'];
            $final = $hit && $p['mb_spelling'] ? ArtistNames::plainPunctuation(trim((string) $hit['name'])) : $orig;
            $artist = Artist::where('name', $final)->first();
            if (! $artist) {
                $artist = Artist::create(['name' => $final]);
                $created++;
            }
            $artist->fill(array_filter(['mbid' => $artist->mbid ?: ($hit['mbid'] ?? null)]))->save();
            if ($final !== $orig) {
                ArtistAlias::updateOrCreate(['alias' => $orig], ['artist_id' => $artist->id, 'source' => 'musicbrainz']);
                $changed[] = $orig;
                $changed[] = $final;
            }
            // Schreibweisen und Genres liegen vom Vorladen im Zwischenspeicher
            $aliases = $hit ? rescue(fn () => $lookup->musicbrainzArtistAliases($hit['mbid']), [], report: false) : [];
            $added = $aliases ? $this->directory->importMusicBrainzAliases($artist, $aliases) : [];
            if ($added) {
                array_push($changed, $final, ...$added);
            }
            if (! empty($p['image']) && ! $artist->image) {
                StoreArtistImage::dispatch($artist->id, $p['image']);
            }
            app(NameConfirmation::class)->evaluate($artist->fresh());
            if (! $artist->genres()) {
                $genre = ArtistGenreSuggestions::best($genres->for($final, $artist->mbid, null, $artist->fresh('aliases'))['proposals']);
                $genre && $artist->setGenres([$genre])->save();
            }
        }
        $this->directory->flush();
        // Tags der betroffenen Titel gleich vormerken (wie im Assistenten)
        $staged = $changed ? app(PendingChanges::class)->stageForNames($changed, 'Schnell erfassen') : 0;

        return ['created' => $created, 'staged' => $staged];
    }

    /**
     * „Tippfehler?“ übernehmen: der Bestandsname wird Schreibweise von $target (Tags werden vorgemerkt). Steht $target noch
     * nicht im Verzeichnis, wird er angelegt – mit MusicBrainz-ID, wenn sein eigenes Vorladen eindeutig ist.
     *
     * @return array{artist: string, created: bool, staged: int}
     */
    public function typo(string $name, string $target): array
    {
        $target = trim($target);
        $check = $this->directory->check($target);
        $artist = in_array($check['status'], ['ok', 'variant'], true) ? Artist::where('name', $check['canonical'])->first() : null;
        if ($artist) {
            return ['artist' => $artist->name, 'created' => false, 'staged' => app(ArtistActions::class)->addAlias($artist, $name)['staged']];
        }
        [$kind, , $hit] = $this->classify($target, PrefetchArtistLookup::results([$target])[$target] ?? null);
        $mbid = $kind === 'sure' && ArtistNames::compareKey((string) $hit['name']) === ArtistNames::compareKey($target) ? $hit['mbid'] : null;
        $r = app(ArtistActions::class)->create($target, $mbid, null, [$name], null, 'Tippfehler');
        if (! $r['artist']->genres() && ($genre = ArtistGenreSuggestions::best(app(ArtistGenreSuggestions::class)->for($target, $mbid, null, $r['artist']->fresh('aliases'))['proposals']))) {
            $r['artist']->setGenres([$genre])->save();
        }

        return ['artist' => $r['artist']->name, 'created' => $r['created'], 'staged' => $r['staged']];
    }
}
