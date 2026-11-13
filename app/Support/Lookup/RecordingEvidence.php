<?php

namespace App\Support\Lookup;

use App\Models\Artist;
use App\Support\ArtistMatcher;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Beleg über die eigenen Lieder: Bei MusicBrainz nach Aufnahmen „Titel + Interpret“ suchen und zählen, bei welchem
 * Eintrag unsere Titel stehen. Gibt es mehrere Gleichnamige, entscheidet das, wer gemeint ist (Spike, Matchbox …).
 * Gefragt werden bis zu TITLES Titel; Zusätze in Klammern („(Radio Edit)“) zählen beim Vergleich nicht.
 */
class RecordingEvidence
{
    private const API = 'https://musicbrainz.org/ws/2/recording';

    private const TITLES = 3;

    public function __construct(private Client $client) {}

    /**
     * Prüfen und speichern.
     *
     * @return array<string, int> MusicBrainz-ID => Zahl der gefundenen Titel
     */
    public function check(Artist $artist): array
    {
        $titles = $this->titles($artist);
        $hits = [];
        foreach ($titles as $title) {
            foreach ($this->search($title, $artist->name) as $mbid) {
                $hits[$mbid] = ($hits[$mbid] ?? 0) + 1;
            }
        }
        arsort($hits);
        DB::table('artist_recording_checks')->updateOrInsert(['artist_id' => $artist->id],
            ['hits' => json_encode($hits), 'titles' => count($titles), 'checked_at' => now()]);

        return $hits;
    }

    /** Gespeichertes Ergebnis oder null (noch nicht geprüft) */
    public static function hits(int $artistId): ?array
    {
        $row = DB::table('artist_recording_checks')->where('artist_id', $artistId)->value('hits');

        return $row === null ? null : (json_decode($row, true) ?: []);
    }

    /** Titel ohne Zusatz in Klammern als Vergleichsschlüssel („Take On Me (Remix)“ → „takeonme“) */
    public static function titleKey(string $title): string
    {
        return ArtistMatcher::key(trim(preg_replace('/\s*[\(\[][^\)\]]*[\)\]]/u', '', $title)) ?: $title);
    }

    /** Verschiedene Titel des Interpreten (alphabetisch, ohne Zusatz in Klammern) */
    private function titles(Artist $artist): array
    {
        $out = [];
        foreach ($artist->tracks()->whereNotNull('title')->orderBy('title')->pluck('title') as $title) {
            $key = self::titleKey($title);
            if ($key !== '' && ! isset($out[$key])) {
                $out[$key] = trim(preg_replace('/\s*[\(\[][^\)\]]*[\)\]]/u', '', $title)) ?: $title;
            }
        }

        return array_slice(array_values($out), 0, self::TITLES);
    }

    /** MusicBrainz-IDs der Interpreten, bei denen eine Aufnahme mit diesem Titel unter diesem Namen steht */
    private function search(string $title, string $name): array
    {
        $q = fn (string $s) => '"'.addcslashes($s, '"\\').'"';
        $r = $this->client->get('musicbrainz', self::API, ['query' => 'recording:'.$q($title).' AND artist:'.$q($name), 'limit' => 25, 'fmt' => 'json']);
        if (! $r->successful()) {
            throw new RuntimeException('MusicBrainz antwortet nicht ('.$r->status().')');
        }
        $titleKey = self::titleKey($title);
        $nameKey = ArtistMatcher::key($name);
        $out = [];
        foreach ($r->json('recordings') ?? [] as $rec) {
            if (self::titleKey((string) ($rec['title'] ?? '')) !== $titleKey) {
                continue;
            }
            foreach ($rec['artist-credit'] ?? [] as $credit) {
                $id = $credit['artist']['id'] ?? null;
                if ($id && in_array($nameKey, [ArtistMatcher::key((string) ($credit['name'] ?? '')), ArtistMatcher::key((string) ($credit['artist']['name'] ?? ''))], true)) {
                    $out[$id] = true;
                }
            }
        }

        return array_keys($out);
    }
}
