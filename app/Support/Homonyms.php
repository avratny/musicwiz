<?php

namespace App\Support;

use App\Livewire\Artists;
use App\Models\Album;
use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Models\Track;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Gleichnamige Künstler: ein Name, mehrere Personen ("Adam [DE]", "Adam [GB]").
 * Navidrome unterscheidet Künstler nur am Namen – der Zusatz steht deshalb in ARTISTS/ALBUMARTISTS,
 * der Anzeigetext ARTIST bleibt "Adam". Zuordnung nur per Klick, mit Vorschau und Verlauf.
 */
class Homonyms
{
    public function __construct(
        private ArtistDirectory $directory,
        private Lookup $lookup,
        private Settings $settings,
        private FileOps $files,
        private AlbumPlanner $planner,
    ) {}

    /**
     * Personen bei MusicBrainz mit Vorschlag für den Zusatz.
     *
     * @return list<array{mbid:string, name:string, disambiguation:?string, country:?string, type:?string, suffix:string, label:string}>
     */
    public function candidates(string $base): array
    {
        $key = ArtistNames::compareKey($base);
        $hits = array_values(array_filter($this->lookup->musicbrainzArtists($base, 100),
            fn ($a) => ArtistNames::compareKey($a['name']) === $key));

        return self::rank(self::withSuffixes($hits), $this->localGenres($base));
    }

    /** Treffer, deren Beschreibung zu den Genres im Bestand passt, nach oben (und als relevant markieren). */
    public static function rank(array $candidates, array $genres): array
    {
        $words = [];
        foreach ($genres as $g) {
            foreach (preg_split('/[^\p{L}]+/u', mb_strtolower($g)) as $w) {
                if (mb_strlen($w) >= 4 && ! in_array($w, ['electronic', 'music'], true)) {
                    $words[] = $w;
                }
            }
        }
        // ein paar naheliegende Verwandte
        $related = ['eurodance' => ['euro', 'dance', 'eurohouse'], 'house' => ['house', 'dance', 'dj'], 'trance' => ['trance', 'dj'], 'techno' => ['techno', 'dj'],
            'schlager' => ['schlager', 'german', 'singer'], 'volksmusik' => ['volksmusik', 'folk'], 'rock' => ['rock', 'band'], 'pop' => ['pop', 'singer']];
        foreach ($words as $w) {
            array_push($words, ...($related[$w] ?? []));
        }
        $words = array_unique($words);
        foreach ($candidates as $i => &$c) {
            $text = mb_strtolower(($c['disambiguation'] ?? '').' '.($c['type'] ?? ''));
            $hits = array_values(array_filter($words, fn ($w) => str_contains($text, $w)));
            $c['relevance'] = count($hits);
            $c['order'] = $i;
            if ($hits) {
                $c['label'] = '★ passt zu deinem Bestand ('.implode(', ', array_slice($hits, 0, 3)).') · '.$c['label'];
            }
        }
        unset($c);
        usort($candidates, fn ($a, $b) => [$b['relevance'], $a['order']] <=> [$a['relevance'], $b['order']]);

        return $candidates;
    }

    /** Zusätze vorschlagen: Land, wenn eindeutig; sonst Kurzbeschreibung; sonst Nummer. */
    public static function withSuffixes(array $hits, array $genres = []): array
    {
        $countries = array_count_values(array_filter(array_map(fn ($h) => $h['country'] ?? null, $hits)));
        $used = [];
        $out = [];
        foreach ($hits as $i => $h) {
            $country = $h['country'] ?? null;
            $suffix = null;
            if ($country && $country !== 'XW' && ($countries[$country] ?? 0) === 1) {
                $suffix = $country;
            } elseif (! empty($h['disambiguation'])) {
                $suffix = self::shortDescription($h['disambiguation']);
            } elseif (! empty($h['type']) && $h['type'] !== 'Person') {
                $suffix = $h['type'] === 'Group' ? 'Band' : $h['type'];
            }
            $suffix = $suffix ?: (string) ($i + 1);
            $candidate = $suffix;
            for ($n = 2; isset($used[mb_strtolower($candidate)]); $n++) {
                $candidate = $suffix.' '.$n;
            }
            $used[mb_strtolower($candidate)] = true;
            $out[] = $h + [
                'suffix' => $candidate,
                'label' => trim(($h['disambiguation'] ?? '').' '.(($h['country'] ?? null) ? '['.$h['country'].']' : '').' '.(($h['type'] ?? null) ? '· '.$h['type'] : '')),
            ];
        }

        return $out;
    }

    /** "German Schlager singer, born 1975" -> "Schlager-Sänger"? Bewusst einfach: erste Wörter, ohne Füllwörter. */
    public static function shortDescription(string $text): string
    {
        $text = preg_replace('/[,(;].*$/u', '', $text);
        $words = array_values(array_filter(preg_split('/\s+/u', trim($text)), fn ($w) => ! in_array(mb_strtolower($w), ['a', 'an', 'the', 'from', 'of', 'and'], true)));

        return trim(implode(' ', array_slice($words, 0, 2)));
    }

    /** Häufigste Genres der Lieder mit diesem Namen (Hilfe beim Zusatz). */
    public function localGenres(string $base): array
    {
        $counts = [];
        foreach ($this->tracksWith($base) as $t) {
            foreach ($t->genres ?? [] as $g) {
                $counts[$g] = ($counts[$g] ?? 0) + 1;
            }
        }
        arsort($counts);

        return array_slice(array_keys($counts), 0, 3);
    }

    /**
     * Namen aufteilen. Der bisherige Eintrag wird zur ersten Person (Bild wird mit umbenannt), weitere werden angelegt.
     *
     * @param  list<array{suffix:string, mbid?:?string, disambiguation?:?string, existing?:bool}>  $persons
     * @return list<Artist>
     */
    public function split(Artist $artist, array $persons): array
    {
        $base = $artist->baseName();
        $persons = array_values(array_filter($persons, fn ($p) => trim((string) ($p['suffix'] ?? '')) !== ''));
        if (count($persons) < 2 && ! $artist->base_name) {
            throw new RuntimeException('Mindestens zwei Personen mit Zusatz angeben.');
        }
        $suffixes = array_map(fn ($p) => mb_strtolower(trim($p['suffix'], ' []')), $persons);
        if (count(array_unique($suffixes)) !== count($suffixes)) {
            throw new RuntimeException('Jeder Zusatz muss eindeutig sein.');
        }
        $existingIndex = collect($persons)->search(fn ($p) => ! empty($p['existing']));
        $existingIndex = $existingIndex === false ? 0 : $existingIndex;

        $result = [];
        foreach ($persons as $i => $p) {
            $full = Artist::fullName($base, $p['suffix']);
            if ($i === $existingIndex && ! $artist->base_name) {
                $this->rename($artist, $full);
                $artist->fill(['base_name' => $base, 'suffix' => trim($p['suffix'], ' []'),
                    'mbid' => $p['mbid'] ?? $artist->mbid, 'disambiguation' => $p['disambiguation'] ?? null])->save();
                $result[] = $artist;

                continue;
            }
            if (Artist::where('name', $full)->exists()) {
                throw new RuntimeException('„'.$full.'“ gibt es schon.');
            }
            $result[] = Artist::create(['name' => $full, 'base_name' => $base, 'suffix' => trim($p['suffix'], ' []'),
                'mbid' => $p['mbid'] ?? null, 'disambiguation' => $p['disambiguation'] ?? null]);
        }
        // Der Grundname selbst darf keine Variante einer Person sein – sonst würde er automatisch zugeordnet
        ArtistAlias::where('compare_key', ArtistNames::compareKey($base))->delete();
        $this->directory->flush();

        return $result;
    }

    /** Zusatz ändern (Bild wird mit umbenannt). */
    public function changeSuffix(Artist $artist, string $suffix): void
    {
        $full = Artist::fullName($artist->baseName(), $suffix);
        if ($full === $artist->name) {
            return;
        }
        if (Artist::where('name', $full)->exists()) {
            throw new RuntimeException('„'.$full.'“ gibt es schon.');
        }
        $this->rename($artist, $full);
        $artist->update(['suffix' => trim($suffix, ' []')]);
        $this->directory->flush();
    }

    private function rename(Artist $artist, string $full): void
    {
        if ($artist->image) {
            $dir = rtrim($this->settings->get('artist_images'), '/');
            $newFile = Artists::imageFile($full, pathinfo($artist->image, PATHINFO_EXTENSION));
            if (is_file($dir.'/'.$artist->image)) {
                $this->files->move($dir.'/'.$artist->image, $dir.'/'.$newFile, 'number');
            }
            $artist->image = $newFile;
        }
        $artist->name = $full;
        $artist->save();
    }

    /** @return Collection<int, Track> Lieder im Bestand, deren ARTIST/ALBUMARTIST den Grundnamen enthält */
    public function tracksWith(string $base): Collection
    {
        $key = ArtistNames::compareKey($base);
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_substr($base, 0, 3)).'%';

        return Track::where('location', 'library')->where(fn ($q) => $q->where('artist', 'like', $like)->orWhere('albumartist', 'like', $like))->get()
            ->filter(function (Track $t) use ($key) {
                foreach ([...ArtistNames::split($t->artist), ...ArtistNames::split($t->albumartist)] as $n) {
                    if (ArtistNames::compareKey($n) === $key) {
                        return true;
                    }
                }

                return false;
            });
    }

    /**
     * Alben mit diesem Namen und ihre aktuelle Zuordnung.
     *
     * @return list<array{album:Album, assigned:?string, mixed:bool, tracks:int, sample:?Track, genres:array, suggestion:?string, reason:?string}>
     */
    public function albums(string $base): array
    {
        $persons = $this->directory->personsFor($base);
        $personNames = array_map(fn ($p) => $p->name, $persons);
        $out = [];
        foreach ($this->tracksWith($base)->groupBy('album_id') as $albumId => $tracks) {
            $album = Album::find($albumId);
            if (! $album) {
                continue;
            }
            $assigned = [];
            foreach ($tracks as $t) {
                $hit = collect([...($t->artists ?? []), ...($t->albumartists ?? [])])->first(fn ($n) => in_array($n, $personNames, true));
                $assigned[$hit ?? ''] = true;
            }
            $genres = $tracks->pluck('genres')->flatten()->filter()->countBy()->sortDesc()->keys()->all();
            [$suggestion, $reason] = $this->suggestByGenre($persons, $genres);
            $out[] = [
                'album' => $album,
                'assigned' => count($assigned) === 1 && array_key_first($assigned) !== '' ? array_key_first($assigned) : null,
                'mixed' => count($assigned) > 1,
                'tracks' => $tracks->count(),
                'sample' => $tracks->first(),
                'genres' => array_slice($genres, 0, 3),
                'suggestion' => $suggestion,
                'reason' => $reason,
            ];
        }
        usort($out, fn ($a, $b) => [$a['assigned'] !== null, $a['album']->title] <=> [$b['assigned'] !== null, $b['album']->title]);

        return $out;
    }

    private function suggestByGenre(array $persons, array $genres): array
    {
        $map = app(Genres::class)->map();
        $expand = fn ($g) => array_filter([$g, $map[$g] ?? null]);
        $matches = array_values(array_filter($persons, fn ($p) => $p->default_genre && array_intersect($expand($p->default_genre), $genres)));

        return count($matches) === 1 ? [$matches[0]->name, 'Genre passt ('.$matches[0]->default_genre.')'] : [null, null];
    }

    /** MusicBrainz: Gehören Lieder des Albums zu einer der Personen? (langsam, ca. 1 Abfrage/s) */
    public function suggestByMusicBrainz(string $base, Album $album): array
    {
        $titles = $this->tracksWith($base)->where('album_id', $album->id)->pluck('title')->filter()->unique()->take(2);
        $found = [];
        foreach ($this->directory->personsFor($base) as $p) {
            if (! $p->mbid) {
                continue;
            }
            foreach ($titles as $title) {
                if ($this->lookup->musicbrainzRecordingByArtist($p->mbid, $title)) {
                    $found[$p->name] = $title;
                    break;
                }
            }
        }

        return count($found) === 1
            ? [array_key_first($found), 'MusicBrainz: „'.reset($found).'“ gehört zu '.array_key_first($found)]
            : [null, $found ? 'MusicBrainz: bei mehreren Personen gefunden' : 'MusicBrainz: kein Lied gefunden'];
    }

    /**
     * Aktionen für die Zuordnung (über den normalen Editor-Planer, damit Vorschau und Pfade stimmen).
     *
     * @param  array<int, string>  $assignments  album_id => voller Name
     */
    public function plan(string $base, array $assignments, bool $move): array
    {
        $rows = [];
        $actions = [];
        foreach ($assignments as $albumId => $full) {
            $album = Album::find($albumId);
            if (! $album || ! $full) {
                continue;
            }
            $fields = ['persons' => [$base => $full]];
            $doMove = $move && $album->location === 'library' && $album->category;
            if ($doMove) {
                $fields['category'] = $album->category;
            }
            $plan = $this->planner->plan($album, $fields, [], (bool) $doMove);
            $albumActions = $this->planner->actions($plan);
            $rows[] = [
                'album' => $album,
                'person' => $full,
                'changes' => count(array_filter($albumActions, fn ($a) => $a['type'] === 'apply')),
                'target' => collect($plan['rows'])->pluck('target')->filter()->first(),
                'problems' => collect($plan['rows'])->pluck('problems')->flatten()->unique()->values()->all(),
            ];
            array_push($actions, ...$albumActions);
        }

        return ['rows' => $rows, 'actions' => $actions];
    }
}
