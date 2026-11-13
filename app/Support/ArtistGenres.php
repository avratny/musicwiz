<?php

namespace App\Support;

use App\Models\Artist;
use App\Models\ArtistSource;
use App\Support\Lookup\LookupGroups;
use Illuminate\Support\Facades\DB;

/**
 * Genres eines Interpreten (jede Ebene des Baums) aus den Genre-Tags seiner Lieder (Gewicht = Anzahl Lieder) und aus
 * dem Abgleich (gewählte Treffer, sonst die oberste Treffergruppe). Manuelle Zuordnungen bleiben unangetastet.
 */
class ArtistGenres
{
    public const SOURCES = ['manual' => 'manuell', 'tags' => 'Tags', 'musicbrainz' => 'MusicBrainz', 'spotify' => 'Spotify', 'discogs' => 'Discogs', 'audiodb' => 'TheAudioDB'];

    public function __construct(private GenreTree $tree) {}

    /** Einen Interpreten neu verknüpfen */
    public function sync(Artist $artist): void
    {
        $this->syncIds([$artist->id]);
    }

    /** Alle Interpreten neu verknüpfen (z. B. nach Änderungen am Genrebaum) */
    public function syncAll(): void
    {
        Artist::select('id')->chunkById(500, fn ($chunk) => $this->syncIds($chunk->pluck('id')->all()));
    }

    /** @param  list<int>  $ids */
    public function syncIds(array $ids): void
    {
        $this->tree->forget();
        $rows = [];
        $add = function (int $artistId, string $value, string $source, int $weight) use (&$rows) {
            foreach (GenreTree::split($value) as $v) {
                if ($genre = $this->tree->match($v)) {
                    $key = $artistId.'|'.$genre.'|'.$source;
                    $rows[$key] = ['artist_id' => $artistId, 'genre_id' => $genre, 'source' => $source, 'weight' => ($rows[$key]['weight'] ?? 0) + $weight];
                }
            }
        };
        // Tags: Genre der Lieder, in denen der Interpret Interpret ist
        $tagged = DB::table('track_artist')->join('tracks', 'tracks.id', '=', 'track_artist.track_id')
            ->whereIn('track_artist.artist_id', $ids)->where('track_artist.role', 'artist')->whereNotNull('tracks.genre')
            ->selectRaw('track_artist.artist_id, tracks.genre, COUNT(*) AS n')->groupBy('track_artist.artist_id', 'tracks.genre')->get();
        foreach ($tagged as $t) {
            $add((int) $t->artist_id, (string) $t->genre, 'tags', (int) $t->n);
        }
        // Abgleich
        foreach ($this->lookupSources($ids) as $source) {
            foreach (self::genresOf($source) as $value) {
                $add($source->artist_id, $value, $source->service, 1);
            }
        }
        DB::transaction(function () use ($ids, $rows) {
            DB::table('artist_genre')->whereIn('artist_id', $ids)->where('source', '!=', 'manual')->delete();
            foreach (array_chunk(array_values($rows), 1000) as $chunk) {
                DB::table('artist_genre')->insert($chunk);
            }
        });
    }

    /** Genre-Werte eines Treffers (MusicBrainz/Spotify: Genres, Discogs: –, TheAudioDB: Genre und Stil) */
    public static function genresOf(ArtistSource $source): array
    {
        return array_values(array_filter([...($source->data['genres'] ?? []), $source->data['genre'] ?? null, $source->data['style'] ?? null]));
    }

    /** Treffer je Interpret, die zählen: gewählte – sonst die oberste Gruppe des Abgleichs */
    private function lookupSources(array $ids): array
    {
        $out = [];
        $chosen = ArtistSource::whereIn('artist_id', $ids)->where('chosen', true)->get()->groupBy('artist_id');
        $groups = app(LookupGroups::class);
        foreach (Artist::whereIn('id', $ids)->whereIn('id', ArtistSource::select('artist_id'))->get() as $artist) {
            if (isset($chosen[$artist->id])) {
                array_push($out, ...$chosen[$artist->id]->all());

                continue;
            }
            $top = $groups->for($artist)[0] ?? null;
            if ($top) {
                array_push($out, ...ArtistSource::where('artist_id', $artist->id)->get()
                    ->filter(fn ($s) => ($top['ids'][$s->service] ?? null) === $s->external_id)->all());
            }
        }

        return $out;
    }

    /**
     * Genre-Werte, die keinem Knoten zugeordnet sind – aus Tags und Abgleich, mit Häufigkeit.
     *
     * @return list<array{value: string, tracks: int, artists: int}>
     */
    public function unmapped(int $limit = 200): array
    {
        $this->tree->forget();
        $values = [];
        foreach (DB::table('tracks')->whereNotIn('location', Locations::IGNORED)->whereNotNull('genre')->selectRaw('genre, COUNT(*) AS n')->groupBy('genre')->get() as $r) {
            foreach (GenreTree::split($r->genre) as $v) {
                $values[$v]['tracks'] = ($values[$v]['tracks'] ?? 0) + $r->n;
            }
        }
        foreach (ArtistSource::get(['artist_id', 'data']) as $s) {
            foreach (self::genresOf($s) as $v) {
                $values[$v]['artist_ids'][$s->artist_id] = true;
            }
        }
        $out = [];
        foreach ($values as $value => $v) {
            if (! $this->tree->match((string) $value) && ! $this->tree->isIgnored((string) $value)) {
                $out[] = ['value' => (string) $value, 'tracks' => $v['tracks'] ?? 0, 'artists' => count($v['artist_ids'] ?? [])];
            }
        }
        usort($out, fn ($a, $b) => [$b['tracks'], $b['artists']] <=> [$a['tracks'], $a['artists']]);

        return array_slice($out, 0, $limit);
    }
}
