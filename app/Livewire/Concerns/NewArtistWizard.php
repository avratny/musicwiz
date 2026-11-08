<?php

namespace App\Livewire\Concerns;

use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Support\ArtistDirectory;
use App\Support\ArtistGenreSuggestions;
use App\Support\ArtistGroupAnalyzer;
use App\Support\ArtistNames;
use App\Support\Covers;
use App\Support\Lookup;
use App\Support\Settings;
use Livewire\Attributes\Url;

/**
 * „Neue Künstler“: Namen aus dem Bestand, die noch nicht im Verzeichnis stehen, einer nach dem anderen erfassen –
 * Schreibweise prüfen, MusicBrainz- und Discogs-ID wählen, Bild und Standard-Genre, dann weiter zum nächsten.
 * Gespeichert wird erst mit „Speichern & weiter“.
 */
trait NewArtistWizard
{
    /** Name aus dem Bestand, der gerade erfasst wird */
    #[Url(as: 'neu')]
    public ?string $wizName = null;

    /** In dieser Sitzung übersprungene Namen */
    public array $wizSkipped = [];

    /** Schreibweise, unter der gespeichert wird */
    public string $wizFinal = '';

    public array $wizMb = [];

    public ?string $wizMbid = null;

    public array $wizDiscogs = [];

    public ?string $wizDiscogsId = null;

    public array $wizImages = [];

    public ?string $wizImage = null;

    public string $wizGenre = '';

    /** Genre-Vorschläge: [{source, genre, detail}] aus Bestand, MusicBrainz, Discogs */
    public array $wizGenres = [];

    /** Schreibweisen des gewählten MusicBrainz-Künstlers (Name und Aliase) */
    public array $wizMbAliases = [];

    /** Häkchen bei ähnlichen Namen: md5(Name) => mitspeichern? */
    public array $wizTake = [];

    public bool $wizLoaded = false;

    /** Name, mit dem zuletzt bei MusicBrainz/Discogs gesucht wurde (anfangs der Bestandsname) */
    public string $wizSearched = '';

    public array $wizErrors = [];

    /** @return array<string, int> offene Namen (häufigste zuerst) */
    private function wizQueue(ArtistDirectory $directory): array
    {
        return array_diff_key($this->wizOpenNames($directory), array_flip($this->wizSkipped));
    }

    /** @return array<string, int> alle Namen aus dem Bestand, die noch nicht im Verzeichnis stehen (auch übersprungene) */
    private function wizOpenNames(ArtistDirectory $directory): array
    {
        $out = [];
        $counts = $directory->namesInCollection();
        arsort($counts);
        foreach ($counts as $name => $n) {
            if (in_array($directory->check($name)['status'], ['new', 'similar'], true)) {
                $out[$name] = $n;
            }
        }

        return $out;
    }

    /**
     * Andere noch nicht erfasste Namen, die zum aktuellen gehören könnten: gleiche Schreibweise bis auf Kleinigkeiten,
     * laut MusicBrainz eine Schreibweise des gewählten Künstlers, oder ein möglicher Tippfehler.
     *
     * @return list<array{name:string, count:int, reason:string, mb:bool, default:bool, key:string}>
     */
    private function wizRelated(ArtistDirectory $directory): array
    {
        $name = (string) $this->wizName;
        if ($name === '') {
            return [];
        }
        $key = ArtistNames::compareKey($name);
        $mbByKey = [];
        foreach ($this->wizMbAliases as $alias) {
            $mbByKey[ArtistNames::compareKey($alias)] ??= $alias;
        }
        $out = [];
        foreach ($this->wizOpenNames($directory) as $other => $count) {
            if ($other === $name) {
                continue;
            }
            $k = ArtistNames::compareKey($other);
            $mbAlias = $mbByKey[$k] ?? null;
            if ($k === $key) {
                $reason = 'gleiche Schreibweise bis auf Groß/klein, Satzzeichen oder Akzente';
            } elseif ($mbAlias !== null) {
                $reason = 'laut MusicBrainz eine Schreibweise („'.$mbAlias.'“)';
            } elseif (strlen($key) >= 5 && abs(strlen($k) - strlen($key)) <= 1 && preg_replace('/\D/', '', $k) === preg_replace('/\D/', '', $key)
                && levenshtein($key, $k) <= (strlen($key) > 10 ? 2 : 1)) {
                $reason = 'möglicher Tippfehler – bitte anhören';
            } else {
                continue;
            }
            $out[] = ['name' => $other, 'count' => $count, 'reason' => $reason, 'mb' => $mbAlias !== null,
                'default' => $k === $key || $mbAlias !== null, 'key' => md5($other)];
        }

        return $out;
    }

    /** Ist der Name laut MusicBrainz eine Schreibweise des gewählten Künstlers? */
    private function wizFromMusicBrainz(string $name): bool
    {
        $key = ArtistNames::compareKey($name);

        return (bool) array_filter($this->wizMbAliases, fn ($a) => ArtistNames::compareKey($a) === $key);
    }

    /** Bestandsname und angehakte ähnliche Namen als Schreibweisen speichern (mit Herkunft). @return int Anzahl */
    private function wizStoreAliases(Artist $artist, string $orig, ArtistDirectory $directory): int
    {
        $n = 0;
        $names = $orig !== $artist->name ? [$orig] : [];
        foreach ($this->wizRelated($directory) as $r) {
            if ($this->wizTake[$r['key']] ?? $r['default']) {
                $names[] = $r['name'];
            }
        }
        foreach (array_unique($names) as $alias) {
            if ($alias === $artist->name) {
                continue;
            }
            ArtistAlias::updateOrCreate(['alias' => $alias], [
                'artist_id' => $artist->id,
                'source' => $this->wizFromMusicBrainz($alias) ? 'musicbrainz' : null,
            ]);
            $n++;
        }

        return $n;
    }

    private function wizReset(?string $name): void
    {
        $this->wizName = $name;
        $this->wizFinal = (string) $name;
        $this->wizMb = $this->wizDiscogs = $this->wizImages = $this->wizErrors = $this->wizGenres = $this->wizMbAliases = $this->wizTake = [];
        $this->wizMbid = $this->wizDiscogsId = $this->wizImage = null;
        $this->wizGenre = '';
        $this->wizLoaded = false;
        $this->wizSearched = (string) $name;
    }

    /** Nachschlagen für den aktuellen Namen (läuft nach dem Anzeigen der Karte, nicht beim Rendern). */
    public function wizLookup(Lookup $lookup, ArtistGroupAnalyzer $analyzer): void
    {
        if ((string) $this->wizName === '' || $this->wizLoaded) {
            return;
        }
        $this->wizRunLookup($this->wizSearched ?: (string) $this->wizName, $lookup, $analyzer);
    }

    /** Mit einer anderen Schreibweise erneut bei MusicBrainz und Discogs suchen (bisherige Auswahl wird verworfen). */
    public function wizSearchAgain(?string $term, Lookup $lookup, ArtistGroupAnalyzer $analyzer): void
    {
        if ($term !== null) {
            $this->wizFinal = $term;
        }
        $search = trim($this->wizFinal) ?: (string) $this->wizName;
        if ((string) $this->wizName === '') {
            return;
        }
        $this->wizMb = $this->wizDiscogs = $this->wizImages = $this->wizErrors = $this->wizGenres = $this->wizMbAliases = [];
        $this->wizMbid = $this->wizDiscogsId = $this->wizImage = null;
        $this->wizRunLookup($search, $lookup, $analyzer);
    }

    private function wizRunLookup(string $name, Lookup $lookup, ArtistGroupAnalyzer $analyzer): void
    {
        $this->wizLoaded = true;
        $this->wizSearched = $name;
        $key = ArtistNames::compareKey($name);
        // Liedtitel aus dem Bestand – immer zum Bestandsnamen, auch wenn mit einer anderen Schreibweise gesucht wird
        $titles = array_slice(array_values($analyzer->profiles()[(string) $this->wizName]['titles'] ?? []), 0, 2);

        try {
            $hits = array_slice($lookup->musicbrainzArtists($name, 6), 0, 6);
            // Liedprüfung: welcher Treffer hat eure Lieder? (Gleichnamige Künstler sauber auseinanderhalten)
            $checked = 0;
            foreach ($hits as &$h) {
                $h['exact'] = ArtistNames::compareKey($h['name']) === $key;
                $h['songs'] = [];
                if ($checked < 3 && ($h['exact'] || $h['score'] >= 90)) {
                    $checked++;
                    foreach ($titles as $t) {
                        if ($lookup->musicbrainzRecordingByArtist($h['mbid'], $t)) {
                            $h['songs'][] = $t;
                            break;
                        }
                    }
                }
            }
            unset($h);
            $this->wizMb = $hits;
            $withSongs = array_values(array_filter($hits, fn ($h) => $h['songs']));
            $exact = array_values(array_filter($hits, fn ($h) => $h['exact']));
            $pick = $withSongs[0] ?? (count($exact) === 1 ? $exact[0] : null);
            if ($pick) {
                $this->wizMbid = $pick['mbid'];
            }
        } catch (\Throwable $e) {
            $this->wizErrors[] = 'MusicBrainz: '.$e->getMessage();
        }

        if ($lookup->hasDiscogs()) {
            try {
                $this->wizDiscogs = array_slice($lookup->discogsArtists($name), 0, 6);
                $exact = array_values(array_filter($this->wizDiscogs, fn ($d) => ArtistNames::compareKey($d['name']) === $key));
                $this->wizDiscogsId = count($exact) === 1 ? $exact[0]['discogs_id'] : null;
            } catch (\Throwable $e) {
                $this->wizErrors[] = 'Discogs: '.$e->getMessage();
            }
        }

        $this->wizLoadGenres(true);
        $this->wizFindImages($lookup);
    }

    /** Genre-Vorschläge zur aktuellen Auswahl (MusicBrainz-/Discogs-ID) laden; $fill: Feld mit dem besten Vorschlag belegen. */
    private function wizLoadGenres(bool $fill): void
    {
        $this->wizLoadMbAliases();
        $r = app(ArtistGenreSuggestions::class)->for((string) $this->wizName, $this->wizMbid, $this->wizDiscogsId);
        $this->wizGenres = $r['proposals'];
        array_push($this->wizErrors, ...$r['errors']);
        if ($fill && $this->wizGenre === '') {
            $this->wizGenre = (string) ArtistGenreSuggestions::best($this->wizGenres);
        }
    }

    private function wizLoadMbAliases(): void
    {
        $this->wizMbAliases = [];
        if (! $this->wizMbid) {
            return;
        }
        try {
            $this->wizMbAliases = app(Lookup::class)->musicbrainzArtistAliases($this->wizMbid);
        } catch (\Throwable $e) {
            $this->wizErrors[] = 'MusicBrainz-Schreibweisen: '.$e->getMessage();
        }
    }

    public function wizFindImages(Lookup $lookup): void
    {
        $this->wizImages = [];
        $name = trim($this->wizFinal) ?: (string) $this->wizName;
        $sources = [
            'Deezer' => fn () => $lookup->deezerArtistImages($name),
            'Fanart.tv' => fn () => $this->wizMbid && trim((string) app(Settings::class)->get('fanart_key')) !== '' ? $lookup->fanartArtistImages($this->wizMbid) : [],
            'Discogs' => fn () => $this->wizDiscogsId && $lookup->hasDiscogs() ? $lookup->discogsArtistImages($this->wizDiscogsId) : [],
        ];
        foreach ($sources as $label => $src) {
            try {
                $this->wizImages = array_merge($this->wizImages, $src());
            } catch (\Throwable $e) {
                $this->wizErrors[] = $label.': '.$e->getMessage();
            }
        }
        $this->wizImages = array_slice($this->wizImages, 0, 12);
        if ($this->wizImage && ! in_array($this->wizImage, array_column($this->wizImages, 'url'), true)) {
            $this->wizImage = null;
        }
    }

    public function wizPickMb(?string $mbid): void
    {
        $this->wizMbid = $this->wizMbid === $mbid ? null : $mbid;
        $this->wizLoadGenres(false);
    }

    public function wizPickDiscogs(?string $id): void
    {
        $this->wizDiscogsId = $this->wizDiscogsId === $id ? null : $id;
        $this->wizLoadGenres(false);
    }

    public function wizPickImage(int $i): void
    {
        $url = $this->wizImages[$i]['url'] ?? null;
        $this->wizImage = $this->wizImage === $url ? null : $url;
    }

    /** Neuen Künstler speichern (bzw. bei bekannter Schreibweise als Variante) und zum nächsten. */
    public function wizSave(ArtistDirectory $directory, Covers $covers): void
    {
        $orig = (string) $this->wizName;
        $final = trim($this->wizFinal) ?: $orig;
        if ($orig === '') {
            return;
        }
        $artist = Artist::where('name', $final)->first();
        $created = ! $artist;
        $artist ??= Artist::create(['name' => $final]);
        // Felder nur füllen, nicht überschreiben (bei einem schon vorhandenen Eintrag)
        $artist->fill(array_filter([
            'mbid' => $artist->mbid ?: $this->wizMbid,
            'discogs_id' => $artist->discogs_id ?: $this->wizDiscogsId,
            'default_genre' => $artist->default_genre ?: ($this->wizGenre ?: null),
        ]))->save();
        $aliases = $this->wizStoreAliases($artist, $orig, $directory);
        // übrige Schreibweisen von MusicBrainz im Hintergrund mitnehmen (erkennt künftige Schreibweisen, z. B. aus der Inbox)
        if ($artist->mbid === $this->wizMbid && $this->wizMbAliases) {
            $directory->importMusicBrainzAliases($artist, $this->wizMbAliases);
        }
        $msg = $created ? '„'.$final.'“ angelegt' : '„'.$orig.'“ als Schreibweise von „'.$final.'“ gespeichert';
        if ($aliases > ($orig !== $final ? 1 : 0)) {
            $msg .= ' – '.$aliases.' Schreibweise(n) mitgespeichert';
        }
        if ($this->wizImage && ! $artist->image) {
            try {
                $previous = $this->selected;
                $this->selected = $artist->id;
                $this->storeImage($covers->download($this->wizImage));
                $this->selected = $previous;
            } catch (\Throwable $e) {
                $msg .= ' – Bild nicht gespeichert: '.$e->getMessage();
            }
        }
        $directory->flush();
        session()->flash('status', $msg.'.');
        $this->wizNext($directory);
    }

    /** Name ist eine Schreibweise eines vorhandenen Künstlers. */
    public function wizAliasOf(int $artistId, ArtistDirectory $directory): void
    {
        $artist = Artist::find($artistId);
        if (! $artist || ! $this->wizName) {
            return;
        }
        $this->wizStoreAliases($artist, (string) $this->wizName, $directory);
        $directory->flush();
        session()->flash('status', '„'.$this->wizName.'“ als Schreibweise von „'.$artist->name.'“ gespeichert.');
        $this->wizNext($directory);
    }

    public function wizSkip(ArtistDirectory $directory): void
    {
        if ($this->wizName) {
            $this->wizSkipped[] = $this->wizName;
        }
        $this->wizNext($directory);
    }

    public function wizOpen(string $name): void
    {
        $this->wizReset($name);
    }

    private function wizNext(ArtistDirectory $directory): void
    {
        $this->wizReset(array_key_first(array_diff_key($this->wizQueue($directory), [(string) $this->wizName => true])));
    }

    /** Daten für die Ansicht. */
    private function wizData(ArtistDirectory $directory, ArtistGroupAnalyzer $analyzer): array
    {
        $queue = $this->wizQueue($directory);
        if ($this->wizName === null || ! isset($queue[$this->wizName])) {
            $this->wizReset(array_key_first($queue));
        } elseif ($this->wizSearched === '') {
            // Name kam über die Adresse (?neu=…): Felder wie beim Öffnen vorbelegen
            $this->wizFinal = $this->wizFinal ?: $this->wizName;
            $this->wizSearched = $this->wizName;
        }
        $name = $this->wizName;
        $similar = [];
        if ($name !== null) {
            // ähnliche Einträge im Verzeichnis (gleicher Schlüssel oder Tippfehler)
            $key = ArtistNames::compareKey($name);
            foreach (Artist::orderBy('name')->get(['id', 'name', 'compare_key', 'disambiguation']) as $a) {
                $d = levenshtein($key, (string) $a->compare_key);
                if ($a->compare_key === $key || (strlen($key) >= 5 && $d <= (strlen($key) > 10 ? 2 : 1))) {
                    $similar[] = $a;
                }
            }
        }
        $profiles = $analyzer->profiles();
        $profile = $name !== null ? ($profiles[$name] ?? []) : [];
        $related = $this->wizRelated($directory);
        foreach ($related as &$r) {
            $this->wizTake[$r['key']] ??= $r['default'];
            $r['track'] = $profiles[$r['name']]['tracks'][0] ?? null;
        }
        unset($r);

        return [
            'wizQueue' => $queue,
            'wizCount' => $name !== null ? ($queue[$name] ?? 0) : 0,
            'wizSimilar' => $similar,
            'wizTracks' => array_slice($profile['tracks'] ?? [], 0, 6),
            'wizRelated' => $related,
            'wizCombined' => $name !== null && ArtistNames::looksCombined($name),
            'wizMbName' => collect($this->wizMb)->firstWhere('mbid', $this->wizMbid)['name'] ?? null,
        ];
    }
}
