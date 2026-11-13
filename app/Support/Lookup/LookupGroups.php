<?php

namespace App\Support\Lookup;

use App\Models\Artist;
use App\Models\ArtistSource;
use App\Support\ArtistGenres;
use App\Support\ArtistMatcher;
use Illuminate\Support\Collection;

/**
 * Treffer der Dienste für die Anzeige gruppieren: Was derselbe Interpret ist (gleiche MusicBrainz-ID, über
 * MusicBrainz-Links verknüpft – oder bei Treffern ohne ID: gleicher Name wie genau eine MusicBrainz-Gruppe), steht
 * zusammen. Gruppen mit den meisten Diensten zuerst – so sieht man, was das Gängigste ist.
 */
class LookupGroups
{
    /** @return list<array> */
    public function for(Artist $artist): array
    {
        $sources = $artist->sources()->with(['images', 'aliases'])->get();
        if ($sources->isEmpty()) {
            return [];
        }
        $mbGroups = $sources->whereNotNull('mbid')->groupBy('mbid');
        $nameToMb = [];
        foreach ($mbGroups as $mbid => $list) {
            foreach ($list as $s) {
                $nameToMb[ArtistMatcher::key((string) $s->name)][$mbid] = true;
            }
        }
        $groups = [];
        foreach ($sources as $s) {
            $nameKey = ArtistMatcher::key((string) $s->name);
            $key = $s->mbid ? 'mb:'.$s->mbid
                : (count($nameToMb[$nameKey] ?? []) === 1 ? 'mb:'.array_key_first($nameToMb[$nameKey]) : 'name:'.$nameKey);
            $groups[$key][] = $s;
        }

        $out = [];
        $groups = array_map(fn ($list) => collect($list)->sortBy(fn ($s) => array_search($s->service, array_keys(ArtistSource::SERVICES), true))->values()->all(), $groups);
        foreach ($groups as $key => $list) {
            $list = collect($list);
            $out[] = $this->group($key, $list, $artist);
        }
        usort($out, fn ($a, $b) => [count($b['services']), $b['score']] <=> [count($a['services']), $a['score']]);

        return $out;
    }

    private function group(string $key, Collection $list, Artist $artist): array
    {
        // Schreibweisen der Dienste: die häufigste zuerst
        $spellings = [];
        foreach ($list as $s) {
            if ($s->name) {
                $spellings[$s->name][] = ArtistSource::SERVICES[$s->service];
            }
        }
        uasort($spellings, fn ($a, $b) => count($b) <=> count($a));
        $data = fn (string $field) => $list->map(fn ($s) => $s->data[$field] ?? null)->filter()->first();
        $own = $artist->spellings();
        $aliases = [];
        foreach ($list as $s) {
            foreach ($s->aliases as $a) {
                if ($a->type !== 'Legal name' && ! in_array($a->alias, $own, true)) {
                    $aliases[$a->alias][] = ArtistSource::SERVICES[$s->service];
                }
            }
        }

        return [
            'key' => $key,
            'mbid' => str_starts_with($key, 'mb:') ? substr($key, 3) : null,
            'services' => $list->pluck('service')->unique()->values()->all(),
            'spellings' => array_map(fn ($services) => array_values(array_unique($services)), $spellings),
            'score' => (int) $list->max('score'),
            'match' => $list->sortByDesc('score')->first()->match,
            'chosen' => $list->every(fn ($s) => $s->chosen),
            'type' => $data('type'),
            'country' => $data('country'),
            'disambiguation' => $data('disambiguation'),
            'begin' => $data('begin') ?? $data('formed'),
            'listeners' => $data('listeners'),
            'releases' => $list->mapWithKeys(fn ($s) => [ArtistSource::SERVICES[$s->service] => $s->data['releases'] ?? null])->filter(fn ($n) => $n !== null)->all(),
            'genres' => $list->flatMap(fn ($s) => array_merge($s->data['genres'] ?? [], array_filter([$s->data['genre'] ?? null, $s->data['style'] ?? null])))->unique()->take(6)->values()->all(),
            'text' => $data('biography') ?? $data('profile'),
            'links' => $list->mapWithKeys(fn ($s) => [$s->service => $s->data['url'] ?? null])->filter()->all(),
            'ids' => $list->mapWithKeys(fn ($s) => [$s->service => $s->external_id])->all(),
            // Verweise laut MusicBrainz (Spotify/Discogs-IDs) – falls der Dienst selbst nicht gefragt wurde
            'linked' => (array) ($list->firstWhere('service', 'musicbrainz')?->data['links'] ?? []),
            'images' => $list->flatMap(fn ($s) => $s->images->map(fn ($i) => ['id' => $i->id, 'kind' => $i->kind, 'service' => ArtistSource::SERVICES[$s->service], 'preview' => ArtistPreviews::exists($i),
                // Version in der Adresse: der Browser hält Vorschauen eine Woche – nach neuem Abgleich (gleiche ID, anderes Bild) sonst das alte
                'version' => substr(md5((string) ($i->preview_url ?: $i->url)), 0, 8)]))
                ->sortBy(fn ($i) => ['portrait' => 0, 'background' => 1, 'logo' => 2][$i['kind']] ?? 3)->values()->all(),
            'aliases' => array_map(fn ($services) => array_values(array_unique($services)), $aliases),
        ];
    }

    /** Gruppe wählen: Treffer als gewählt markieren (je Dienst nur einer), IDs hinterlegen, Schreibweise gilt als bestätigt */
    public function choose(Artist $artist, string $key): ?array
    {
        $group = collect($this->for($artist))->firstWhere('key', $key);
        if (! $group) {
            return null;
        }
        $ids = ArtistSource::where('artist_id', $artist->id)->whereIn('service', $group['services'])->get()
            ->filter(fn ($s) => ($group['ids'][$s->service] ?? null) === $s->external_id)->pluck('id');
        ArtistSource::where('artist_id', $artist->id)->whereIn('service', $group['services'])->update(['chosen' => false]);
        ArtistSource::whereIn('id', $ids)->update(['chosen' => true]);
        $artist->update([
            'mbid' => $group['ids']['musicbrainz'] ?? $group['mbid'] ?? $artist->mbid,
            'discogs_id' => $group['ids']['discogs'] ?? $group['linked']['discogs'][0] ?? $artist->discogs_id,
            'spotify_id' => $group['ids']['spotify'] ?? $group['linked']['spotify'][0] ?? $artist->spotify_id,
            'confirmed' => true,
        ]);
        app(ArtistGenres::class)->sync($artist);

        return $group;
    }
}
