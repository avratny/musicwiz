<?php

namespace App\Support;

use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Models\Track;
use Illuminate\Support\Facades\DB;

/**
 * Verbindung Lied ↔ Künstler (track_artist): einzelne Interpreten (ARTISTS bzw. die Kombi zerlegt, siehe ArtistCredits)
 * als Interpret oder Gast, einzelne Album-Interpreten; genaue Schreibweise (Name oder alternative Schreibweise eines
 * Künstlers). Unbekannte Namen werden dabei als unbestätigte Künstler angelegt. „Various Artists“ (und seine
 * Schreibweisen) ist kein Interpret: wird weder angelegt noch verknüpft.
 */
class ArtistLinker
{
    /** Tag-Felder mit Interpreten (Rollen in track_artist: dazu „guest“ für Gäste aus „feat.“) */
    public const ROLES = ['artist', 'albumartist'];

    /** Spalten, die zum Verbinden gebraucht werden */
    public const COLUMNS = ['id', 'location', 'artist', 'artists', 'albumartist', 'albumartists'];

    public function __construct(private ArtistRegistry $registry, private NameRules $rules, private ArtistCredits $credits) {}

    /** Alle Lieder neu verbinden (z. B. nach der Einführung). */
    public function linkAll(): void
    {
        Track::select(self::COLUMNS)->chunkById(2000, fn ($tracks) => $this->link($tracks->all()));
    }

    /** Diese Lieder neu verbinden. */
    public function linkIds(array $ids): void
    {
        foreach (array_chunk($ids, 2000) as $chunk) {
            $this->link(Track::whereIn('id', $chunk)->get(self::COLUMNS)->all());
        }
    }

    /** @param  list<Track>  $tracks */
    public function link(array $tracks): void
    {
        if (! $tracks) {
            return;
        }
        $this->rules->forget();
        $wanted = [];
        $names = [];
        foreach ($tracks as $t) {
            // Blackhole: nicht verbinden (bisherige Verbindungen fallen unten weg)
            if (Locations::ignored($t->location)) {
                continue;
            }
            $guests = array_flip($this->credits->parse($t->artist)['guests']);
            foreach (self::ROLES as $role) {
                foreach ($this->names($t, $role) as $n) {
                    if (! $this->rules->isVarious($n)) {
                        $wanted[] = [$t->id, $n, $role === 'artist' && isset($guests[$n]) ? 'guest' : $role];
                        $names[$n] = true;
                    }
                }
            }
        }
        $ids = $this->artistIds(array_keys($names));
        $rows = [];
        foreach ($wanted as [$trackId, $n, $role]) {
            if (isset($ids[$n])) {
                $rows[$trackId.'|'.$ids[$n].'|'.$role] = ['track_id' => $trackId, 'artist_id' => $ids[$n], 'role' => $role];
            }
        }
        DB::transaction(function () use ($tracks, $rows) {
            DB::table('track_artist')->whereIn('track_id', array_map(fn ($t) => $t->id, $tracks))->delete();
            foreach (array_chunk(array_values($rows), 1000) as $chunk) {
                DB::table('track_artist')->insert($chunk);
            }
        });
    }

    /** Einzelne Interpreten eines Lieds für Interpret bzw. Album-Interpret: aus ARTISTS, sonst die Kombi zerlegt */
    private function names(Track $t, string $role): array
    {
        $list = ArtistCredits::list($t->getAttribute(ArtistCredits::FIELDS[$role]));

        return array_values(array_filter($list ?: $this->credits->credits($t->getAttribute($role)), fn ($n) => mb_check_encoding($n, 'UTF-8')));
    }

    /** Genaue Schreibweise → Künstler-ID; fehlende Namen werden angelegt. @return array<string, int> */
    public function artistIds(array $names): array
    {
        $names = array_values(array_filter($names, fn ($n) => $n !== '' && mb_check_encoding($n, 'UTF-8')));
        $ids = [];
        foreach (array_chunk($names, 1000) as $chunk) {
            foreach (Artist::whereIn('name', $chunk)->pluck('id', 'name') as $name => $id) {
                $ids[$name] = $id;
            }
            foreach (ArtistAlias::whereIn('alias', $chunk)->pluck('artist_id', 'alias') as $alias => $id) {
                $ids[$alias] ??= $id;
            }
        }
        $missing = array_values(array_diff($names, array_keys($ids)));
        if ($missing) {
            $this->registry->create($missing);
            foreach (array_chunk($missing, 1000) as $chunk) {
                foreach (Artist::whereIn('name', $chunk)->pluck('id', 'name') as $name => $id) {
                    $ids[$name] = $id;
                }
            }
        }

        return $ids;
    }
}
