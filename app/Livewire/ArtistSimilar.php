<?php

namespace App\Livewire;

use App\Livewire\Concerns\ShowsMessages;
use App\Models\Artist;
use App\Models\Track;
use App\Support\ArtistMatcher;
use App\Support\ArtistRegistry;
use App\Support\ArtistTools;
use App\Support\MusicBrainzData;
use App\Support\SimilarVerdict;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Interpreten › Ähnlich: ähnliche Schreibweisen gruppiert (sicherste zuerst) – richtige wählen und zusammenführen,
 * als verschieden markieren oder auf später schieben. Mit Fazit aus MusicBrainz (lokale Daten): derselbe Eintrag,
 * verschiedene Einträge, Schreibweise laut MusicBrainz.
 */
class ArtistSimilar extends Component
{
    use ShowsMessages;

    private const STEP = 20;

    /** auf später verschobene Gruppen (Schlüssel = IDs der Mitglieder) stehen hinten */
    public array $later = [];

    public int $limit = self::STEP;

    /**
     * Zusammenführen: $merge werden Schreibweisen von $target (bestätigt), optional unter neuem Namen. Abgewählte
     * Mitglieder ($keep) sind nicht derselbe und tauchen mit ihnen nicht mehr als ähnlich auf.
     */
    public function mergeSimilar(int $targetId, array $merge, array $keep, string $name, ArtistRegistry $registry): void
    {
        $target = Artist::findOrFail($targetId);
        $merge = array_values(array_diff(array_map('intval', $merge), [$target->id]));
        $names = Artist::whereIn('id', $merge)->pluck('name')->all();
        $this->attempt(function () use ($registry, $target, $merge, $keep, $name, $names) {
            $n = DB::transaction(function () use ($registry, $target, $merge, $keep, $name) {
                foreach (array_map('intval', $keep) as $id) {
                    foreach ([$target->id, ...$merge] as $other) {
                        $registry->distinct([$id, $other]);
                    }
                }

                return $registry->mergeGroup($target, $merge, $name);
            });
            $final = Artist::whereIn('id', [$target->id, ...$merge])->value('name');
            $this->message = ($names ? implode(', ', array_map(fn ($x) => '„'.$x.'“', $names)).' → ' : '').'„'.$final.'“ bestätigt'
                .($n ? ' – '.$n.' '.($n === 1 ? 'Lied' : 'Lieder').' angepasst.' : '.');
        });
        $this->dispatch('artist-tools-changed');
    }

    /** Verschieden: diese Interpreten sind nicht derselbe */
    public function notSame(array $ids, ArtistRegistry $registry): void
    {
        $registry->distinct($ids);
        $this->say(implode(', ', Artist::whereIn('id', $ids)->orderBy('name')->pluck('name')->map(fn ($x) => '„'.$x.'“')->all()).' bleiben verschieden.');
        $this->dispatch('artist-tools-changed');
    }

    /** Später: Gruppe nach hinten */
    public function postpone(string $key): void
    {
        $this->later = [...array_diff($this->later, [$key]), $key];
    }

    public function more(): void
    {
        $this->limit += self::STEP;
    }

    /**
     * Gruppen mit allem, was zum Entscheiden hilft – je Mitglied Lieder, Alben, Schreibweisen und bis zu drei Alben
     * (die mit den meisten Liedern) mit je einem Lied zum Reinhören.
     */
    private function groups(array $groups, ArtistMatcher $matcher): array
    {
        $later = array_flip($this->later);
        usort($groups, fn ($a, $b) => isset($later[implode('-', $a['ids'])]) <=> isset($later[implode('-', $b['ids'])])
            ?: ($later[implode('-', $a['ids'])] ?? 0) <=> ($later[implode('-', $b['ids'])] ?? 0));
        $groups = array_slice($groups, 0, $this->limit);
        $ids = array_merge([], ...array_column($groups, 'ids'));
        if (! $ids) {
            return [];
        }
        $artists = Artist::with('aliases')->whereIn('id', $ids)->get()->keyBy('id');
        $stats = ArtistTools::stats($ids);
        $albums = DB::table('track_artist')->join('tracks', 'tracks.id', '=', 'track_artist.track_id')->whereIn('track_artist.artist_id', $ids)
            ->selectRaw('track_artist.artist_id, tracks.album, COUNT(DISTINCT tracks.id) AS n, MIN(tracks.id) AS track')
            ->groupBy('track_artist.artist_id', 'tracks.album')->orderByDesc('n')->orderBy('tracks.album')->get()->groupBy('artist_id')
            ->map(fn ($rows) => $rows->take(3)->values());
        $tracks = Track::whereIn('id', $albums->flatten(1)->pluck('track'))->get(['id', 'title', 'filename', 'artist', 'duration'])->keyBy('id');
        // MusicBrainz-Eintrag je Mitglied (lokale Daten) – für Anzeige und Fazit
        $mb = app(MusicBrainzData::class);
        $identity = array_combine($ids, array_map(fn ($id) => $matcher->identity($id), $ids));
        $info = $mb->info(array_filter(array_column($identity, 'mbid')));

        return array_map(fn ($g) => [
            'key' => implode('-', $g['ids']),
            'level' => $g['level'],
            'verdict' => SimilarVerdict::for(array_values(array_filter(array_map(fn ($id) => isset($artists[$id])
                ? ['id' => $id, 'name' => $artists[$id]->name] + $identity[$id] : null, $g['ids']))), $g['level'], $info, $mb->available()),
            'members' => array_values(array_filter(array_map(function ($id) use ($artists, $stats, $albums, $tracks, $identity, $info) {
                if (! $a = $artists[$id] ?? null) {
                    return null;
                }
                $samples = ($albums[$id] ?? collect())->filter(fn ($r) => isset($tracks[$r->track]))->values();

                return [
                    'artist' => $a,
                    'mb' => isset($info[$identity[$id]['mbid'] ?? '']) ? SimilarVerdict::describe($info[$identity[$id]['mbid']]) : null,
                    'mbCandidates' => $identity[$id]['candidates'] ?? 0,
                    'tracks' => (int) ($stats[$id]->tracks ?? 0),
                    'albums' => (int) ($stats[$id]->albums ?? 0),
                    'samples' => $samples->map(fn ($r) => ['album' => $r->album ?: 'ohne Album', 'n' => (int) $r->n])->all(),
                    'queue' => $samples->map(fn ($r) => ['id' => $r->track, 'title' => $tracks[$r->track]->title ?: $tracks[$r->track]->filename,
                        'artist' => $tracks[$r->track]->artist, 'cover' => $r->track, 'duration' => $tracks[$r->track]->duration])->all(),
                ];
            }, $g['ids']))),
        ], $groups);
    }

    public function render()
    {
        $matcher = app(ArtistMatcher::class);
        $groups = $matcher->groups();

        return view('livewire.artist-similar', [
            'total' => count($groups),
            'groups' => $this->groups($groups, $matcher),
            'more' => count($groups) > $this->limit,
        ]);
    }
}
