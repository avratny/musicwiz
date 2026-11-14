<?php

namespace App\Support;

use App\Models\Artist;
use App\Models\ArtistImage;
use App\Models\ArtistSource;
use App\Support\Lookup\LookupGroups;
use App\Support\Lookup\RecordingEvidence;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Unbestätigte Interpreten, bei denen der Abgleich einen Treffer klar macht – sie lassen sich gesammelt übernehmen
 * (bestätigen + IDs, Genres aus dem Treffer, Bild, wenn noch keins da ist). Drei Listen (LEVELS):
 *
 * - eindeutig: der Treffer ist sicher, weil
 *   a) unsere Lieder bei genau diesem MusicBrainz-Eintrag stehen (Beleg, RecordingEvidence) – oder
 *   b) es nur eine ernsthafte Treffergruppe gibt, mit MusicBrainz, und genau einen MusicBrainz-Eintrag dieses Namens.
 *   Name genau so geschrieben wie bei MusicBrainz (nur typografische Varianten wie „‐“/„-“ gleich).
 * - wahrscheinlich: mehrere Gleichnamige, aber einer ist klar der Bekannte (DOMINANT_*) – zum Durchsehen, ein kleiner
 *   Namensvetter kann gemeint sein. Fällt weg, sobald der Beleg einen anderen zeigt.
 * - schreibweise: sicher wie „eindeutig“, nur schreibt MusicBrainz den Namen anders (Groß/klein, Leerzeichen, Satzzeichen,
 *   Akzente: „Paul Van Dyk“ → „Paul van Dyk“) – übernehmen heißt umbenennen (Tags, der alte Name bleibt Schreibweise).
 *   Nur über einen Alias gefunden („Melanie G“ → Melanie Brown): in keiner Liste. Hat Claude geantwortet (SpellingAi),
 *   gilt beim Übernehmen dessen Schreibweise – auch „so lassen“.
 *
 * Schwache Nebentreffer (nur ein Dienst, Name nicht genau, z. B. „Robert Williams“ zu „Robbie Williams“) zählen nicht.
 * Immer: nicht in einer offenen Ähnlich-Gruppe oder offenen Kombi (dort zuerst klären), mindestens ein Lied.
 */
class ClearArtists
{
    public const LEVELS = ['eindeutig', 'wahrscheinlich', 'schreibweise'];

    /** Bild: ein Porträt, bevorzugt von diesen Diensten */
    private const IMAGE_ORDER = ['fanart', 'audiodb', 'spotify', 'discogs'];

    /** Treffer mit diesem Abgleich gelten auch allein (nur ein Dienst) als ernsthaft */
    private const STRONG_MATCH = ['exakt', 'gross_klein', 'zeichen'];

    /** „wahrscheinlich“: die erste Gruppe hat so viele Dienste mehr … */
    private const DOMINANT_SERVICES = 2;

    /** … und so viel Mal so viele Veröffentlichungen bei MusicBrainz wie die zweite */
    private const DOMINANT_RELEASES = 5;

    private ?array $all = null;

    /** vorab geladen für viele Interpreten auf einmal (all) – sonst einzeln abgefragt */
    private array $hits = [];

    private array $trackCounts = [];

    private array $mbInfo = [];

    private array $namesakes = [];

    /** Interpreten je Paket beim Vorab-Laden */
    private const CHUNK = 500;

    public function __construct(private LookupGroups $groups, private MusicBrainzData $musicbrainz, private ArtistMatcher $matcher, private ArtistCredits $credits) {}

    /**
     * Alle Interpreten einer Liste, mit den meisten Liedern zuerst.
     *
     * @return list<array{artist: Artist, level: string, reason: string, group: array, mb: object, rename: ?string, rival: ?array, genres: list<string>, image: ?ArtistImage, tracks: int}>
     */
    public function candidates(string $level = 'eindeutig'): array
    {
        return array_values(array_filter($this->all(), fn ($c) => $c['level'] === $level));
    }

    /** Anzahl je Liste */
    public function counts(): array
    {
        $out = array_fill_keys(self::LEVELS, 0);
        foreach ($this->all() as $c) {
            $out[$c['level']]++;
        }

        return $out;
    }

    /**
     * Für den Beleg über die Lieder: nicht sichere Interpreten mit mehreren möglichen MusicBrainz-Einträgen, noch
     * nicht geprüft.
     *
     * @return list<int>
     */
    public function evidenceOpen(): array
    {
        if (! $this->musicbrainz->available()) {
            return [];
        }
        $sure = array_map(fn ($c) => $c['artist']->id, array_filter($this->all(), fn ($c) => $c['level'] !== 'wahrscheinlich'));
        $checked = DB::table('artist_recording_checks')->pluck('artist_id')->all();
        $out = [];
        foreach (array_chunk(array_values(array_diff($this->prefilter(), $sure, $checked)), self::CHUNK) as $ids) {
            $artists = $this->preload($ids);
            $this->namesakes += $this->musicbrainz->namesakesMany($artists->pluck('name')->all());
            foreach ($artists as $artist) {
                $mbids = array_filter(array_column($this->strong($this->groups->for($artist, false)), 'mbid'));
                if (count($mbids) >= 2 || ($mbids && $this->namesakes($artist->name) > 1)) {
                    $out[] = $artist->id;
                }
            }
        }

        return $out;
    }

    /** Für welche Liste taugt der Interpret? Dann die Daten zum Übernehmen, sonst null. */
    public function check(Artist $artist): ?array
    {
        if ($artist->confirmed || ! $this->musicbrainz->available()) {
            return null;
        }
        $strong = $this->strong($this->groups->for($artist, false));
        $withMb = array_values(array_filter($strong, fn ($g) => $g['mbid'] && isset($g['ids']['musicbrainz'])));
        if (! $withMb) {
            return null;
        }
        $pick = $rival = null;
        $sure = false;
        $reason = '';
        // a) Beleg über die Lieder: genau ein MusicBrainz-Eintrag hat unsere Titel
        $hits = array_keys(array_filter((array_key_exists($artist->id, $this->hits) ? $this->hits[$artist->id] : RecordingEvidence::hits($artist->id)) ?? []));
        if (count($hits) > 1) {
            return null;   // die Titel gibt es bei mehreren Gleichnamigen
        }
        if ($hits) {
            $pick = collect($withMb)->firstWhere('mbid', $hits[0]);
            if (! $pick) {
                return null;   // gemeint ist ein Eintrag, den der Abgleich nicht gefunden hat
            }
            [$sure, $reason] = [true, 'lied'];
        } elseif (count($strong) === 1 && $withMb) {
            // b) einzige ernsthafte Gruppe, einziger Eintrag dieses Namens
            $pick = $withMb[0];
            $mb = $this->info($pick);
            if (! $mb || $this->namesakes($mb->name) !== 1) {
                return null;
            }
            [$sure, $reason] = [true, count(array_diff(array_keys($pick['ids']), ['musicbrainz', 'lastfm'])) >= 2 ? 'dienste' : 'einziger'];
        } elseif (count($strong) >= 2 && $strong[0]['key'] === $withMb[0]['key']) {
            // c) mehrere Gleichnamige, einer klar der Bekannte
            [$first, $second] = [$strong[0], $strong[1]];
            $r1 = (int) $this->mb($first['mbid'])?->releases;
            $r2 = $second['mbid'] ? (int) $this->mb($second['mbid'])?->releases : 0;
            if (count($first['services']) - count($second['services']) < self::DOMINANT_SERVICES || $r1 < self::DOMINANT_RELEASES * max(1, $r2)) {
                return null;
            }
            $pick = $first;
            $reason = 'bekannter';
            $rival = ['mb' => $second['mbid'] ? $this->info($second) : null, 'releases' => $r2, 'services' => $second['services'], 'own' => $r1, 'count' => count($strong) - 1];
        }
        $mb = $pick ? $this->info($pick) : null;
        if (! $mb) {
            return null;
        }
        $rename = ArtistMatcher::plain($mb->name) !== ArtistMatcher::plain($artist->name) ? ArtistMatcher::plain($mb->name) : null;
        if ($rename && ArtistMatcher::key($rename) !== ArtistMatcher::key($artist->name)) {
            return null;   // nur über eine Schreibweise gefunden („Melanie G“ → Melanie Brown): kein Umbenennen auf den Hauptnamen
        }
        if ($rename && (! $sure || (($owner = app(ArtistRegistry::class)->ownerOf($rename)) && $owner->id !== $artist->id))) {
            return null;   // anders geschrieben: nur bei sicherem Treffer, und nur, wenn der Name nicht schon einem anderen gehört
        }
        $tracks = $this->trackCounts[$artist->id] ?? DB::table('track_artist')->where('artist_id', $artist->id)->distinct()->count('track_id');
        if (! $tracks) {
            return null;
        }

        return [
            'artist' => $artist,
            'level' => $rename ? 'schreibweise' : ($sure ? 'eindeutig' : 'wahrscheinlich'),
            'reason' => $reason,
            'group' => $pick,
            'mb' => $mb,
            'rename' => $rename,
            'rival' => $rival,
            'claude' => $rename ? SpellingAi::answer($artist, $rename) : null,
            'genres' => $this->genres($pick),
            'image' => $artist->image && $artist->imagePath() ? null : $this->image($artist, $pick),
            'tracks' => $tracks,
        ];
    }

    /**
     * Übernehmen: ggf. umbenennen, dann bestätigen + IDs + Genres (wie „Diesen nehmen“), Bild, wenn noch keins da ist.
     * Vorher erneut prüfen – mit $level nur, wenn er (noch) in dieser Liste steht. Der Name danach: $spelling, sonst die
     * Antwort von Claude, sonst die Schreibweise von MusicBrainz.
     *
     * @return bool übernommen
     */
    public function confirm(Artist $artist, ?string $level = null, ?string $spelling = null): bool
    {
        $c = $this->check($artist);
        if (! $c || ($level && $c['level'] !== $level)) {
            return false;
        }
        // Claudes Antwort nur, wenn sie derselbe Name ist („unsure“ nur, wenn ausdrücklich gewählt)
        $claude = $c['claude'] && $c['claude']->source !== 'unsure' ? $c['claude']->spelling : null;
        $name = $c['rename'] ? ArtistMatcher::plain($spelling ?? $claude ?? $c['rename']) : $artist->name;
        if ($name === '') {
            return false;
        }
        if ($name !== $artist->name) {
            try {
                app(ArtistRegistry::class)->rename($artist, $name);
            } catch (InvalidArgumentException) {
                return false;
            }
            $artist = $artist->fresh();
        }
        $this->groups->choose($artist, $c['group']['key']);
        // Spotify übersprungen (eindeutig): die ID steht als Verweis bei MusicBrainz
        if (! $artist->fresh()->spotify_id && ($spotify = $this->musicbrainz->artist($c['group']['ids']['musicbrainz'])['links']['spotify'][0] ?? null)) {
            $artist->update(['spotify_id' => $spotify]);
        }
        if ($c['image']) {
            try {
                app(ArtistRegistry::class)->adoptImage($artist->fresh(), $c['image']);
            } catch (Throwable $e) {
                report($e);   // Bild nicht erreichbar: bestätigt ist er trotzdem, das Bild lässt sich im Flyout nachholen
            }
        }
        $this->all = null;

        return true;
    }

    /** Alle Listen zusammen (einmal je Anfrage gerechnet) */
    private function all(): array
    {
        if ($this->all !== null) {
            return $this->all;
        }
        if (! $this->musicbrainz->available()) {
            return $this->all = [];
        }
        $out = [];
        $blocked = $this->blocked();
        foreach (array_chunk(array_values(array_diff($this->prefilter(), array_keys($blocked))), self::CHUNK) as $ids) {
            foreach ($this->preload($ids) as $artist) {
                if ($c = $this->check($artist)) {
                    $out[] = $c;
                }
            }
        }
        usort($out, fn ($a, $b) => [$b['tracks'], mb_strtolower($a['artist']->name)] <=> [$a['tracks'], mb_strtolower($b['artist']->name)]);

        return $this->all = $out;
    }

    /**
     * Ein Paket Interpreten mit allem, was check() braucht – wenige Abfragen statt etwa zehn je Interpret
     *
     * @param  list<int>  $ids
     * @return Collection<int, Artist>
     */
    private function preload(array $ids)
    {
        $artists = Artist::whereIn('id', $ids)->with(['aliases', 'sources.images', 'sources.aliases'])->get();
        $hits = DB::table('artist_recording_checks')->whereIn('artist_id', $ids)->pluck('hits', 'artist_id');
        foreach ($ids as $id) {
            $this->hits[$id] = isset($hits[$id]) ? (json_decode($hits[$id], true) ?: []) : null;
        }
        $this->trackCounts = DB::table('track_artist')->whereIn('artist_id', $ids)->selectRaw('artist_id, COUNT(DISTINCT track_id) AS n')
            ->groupBy('artist_id')->pluck('n', 'artist_id')->map(fn ($n) => (int) $n)->all() + array_fill_keys($ids, 0);
        $mbids = $artists->flatMap(fn ($a) => $a->sources->flatMap(fn ($s) => [$s->mbid, $s->service === 'musicbrainz' ? $s->external_id : null]))->filter()->unique()->all();
        $this->mbInfo = $this->musicbrainz->info($mbids) + array_fill_keys($mbids, null);
        $this->namesakes = $this->musicbrainz->namesakesMany(array_filter(array_map(fn ($i) => $i?->name, $this->mbInfo)));

        return $artists;
    }

    /** MusicBrainz-Eintrag (vorab geladen oder einzeln) */
    private function mb(string $mbid): ?object
    {
        return array_key_exists($mbid, $this->mbInfo) ? $this->mbInfo[$mbid] : ($this->mbInfo[$mbid] = $this->musicbrainz->info([$mbid])[$mbid] ?? null);
    }

    private function namesakes(string $name): int
    {
        return $this->namesakes[$name] ??= $this->musicbrainz->namesakes($name);
    }

    /** Günstige Vorauswahl in der Datenbank: unbestätigt, abgeglichen, mit mindestens einem MusicBrainz-Treffer */
    private function prefilter(): array
    {
        return DB::table('artists')->where('confirmed', false)->whereNotNull('looked_up_at')
            ->whereExists(fn ($q) => $q->from('artist_sources')->whereColumn('artist_sources.artist_id', 'artists.id')->where('service', 'musicbrainz'))
            ->pluck('id')->all();
    }

    /** Ernsthafte Treffergruppen: mehr als ein Dienst, oder der Name passt genau (sonst: Nebentreffer wie „Robert Williams“) */
    private function strong(array $groups): array
    {
        return array_values(array_filter($groups, fn ($g) => count($g['services']) > 1 || in_array($g['match'], self::STRONG_MATCH, true)));
    }

    private function info(array $group): ?object
    {
        $mbid = $group['ids']['musicbrainz'] ?? $group['mbid'];

        return $mbid ? $this->mb($mbid) : null;
    }

    /** Interpreten in offenen Ähnlich-Gruppen oder offenen Kombis (erst dort klären) */
    private function blocked(): array
    {
        $ids = array_fill_keys(array_merge([], ...array_column($this->matcher->groups(), 'ids')), true);
        $combos = array_column($this->credits->open(), 'name');
        foreach ($combos ? Artist::whereIn('name', $combos)->pluck('id') : [] as $id) {
            $ids[$id] = true;
        }

        return $ids;
    }

    /** Genres der Treffergruppe, soweit der Genrebaum sie kennt (Pfad, z. B. „Electronic › House“) */
    private function genres(array $group): array
    {
        $tree = app(GenreTree::class);

        return array_values(array_unique(array_filter(array_map(fn ($g) => ($id = $tree->match($g)) ? $tree->path($id) : null, $group['genres']))));
    }

    /** Porträt, bevorzugt nach IMAGE_ORDER, dann nach Rang (die Vorschau lädt die Anzeige nach, falls sie noch fehlt) */
    private function image(Artist $artist, array $group): ?ArtistImage
    {
        $sources = ($artist->relationLoaded('sources') ? $artist->sources : ArtistSource::where('artist_id', $artist->id)->get())
            ->filter(fn ($s) => ($group['ids'][$s->service] ?? null) === $s->external_id && in_array($s->service, self::IMAGE_ORDER, true))
            ->sortBy(fn ($s) => array_search($s->service, self::IMAGE_ORDER, true));
        foreach ($sources as $source) {
            $image = $source->images->first(fn (ArtistImage $i) => $i->kind === 'portrait');
            if ($image) {
                return $image;
            }
        }

        return null;
    }
}
