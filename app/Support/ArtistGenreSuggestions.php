<?php

namespace App\Support;

use App\Models\Artist;

/**
 * Vorschläge fürs Standard-Genre eines Künstlers aus drei Quellen: eigene Lieder im Bestand, MusicBrainz, Discogs.
 * Alles nur Vorschläge – übernommen wird per Klick.
 */
class ArtistGenreSuggestions
{
    public function __construct(private Genres $genres, private Lookup $lookup) {}

    /**
     * @return array{proposals: list<array{source:string, genre:?string, detail:string, tied?:list<string>}>, errors: list<string>}
     */
    public function for(string $name, ?string $mbid, ?string $discogsId, ?Artist $artist = null): array
    {
        $proposals = [];
        $errors = [];

        $own = $this->genres->suggestForArtist($artist ?? new Artist(['name' => $name]));
        if ($own) {
            $proposals[] = ['source' => 'Bestand', 'genre' => $own['genre'], 'detail' => $own['count'].' von '.$own['total'].' Liedern'];
        }
        if ($mbid) {
            try {
                $proposals[] = ['source' => 'MusicBrainz'] + $this->describe($this->lookup->musicbrainzArtistGenres($mbid));
            } catch (\Throwable $e) {
                $errors[] = 'MusicBrainz: '.$e->getMessage();
            }
        }
        if ($discogsId && $this->lookup->hasDiscogs()) {
            try {
                $proposals[] = ['source' => 'Discogs'] + $this->describe($this->lookup->discogsArtistGenres($name));
            } catch (\Throwable $e) {
                $errors[] = 'Discogs: '.$e->getMessage();
            }
        }

        return ['proposals' => $proposals, 'errors' => $errors];
    }

    /** Erstes Listen-Genre in der Reihenfolge Bestand → MusicBrainz → Discogs. */
    public static function best(array $proposals): ?string
    {
        foreach ($proposals as $p) {
            if ($p['genre']) {
                return $p['genre'];
            }
        }

        return null;
    }

    private function describe(array $weights): array
    {
        if (! $weights) {
            return ['genre' => null, 'detail' => 'keine Angaben'];
        }
        $r = $this->genres->fromExternal($weights);
        $raw = implode(', ', array_slice(array_keys($weights), 0, 5));
        if ($r['tied']) {
            // gleich viele Stimmen: jeder Kandidat wird ein eigener Vorschlag, keiner wird vorausgewählt
            return ['genre' => null, 'tied' => $r['tied'], 'detail' => 'gleichauf: '.implode(' / ', $r['tied']).' – '.$raw];
        }

        return [
            'genre' => $r['genre'],
            'detail' => $r['genre'] ? $raw : $raw.' – nichts davon steht auf der Genre-Liste (Zuordnung in den Einstellungen)',
        ];
    }
}
