<?php

namespace App\Support;

use App\Jobs\RefreshCleanup;
use App\Jobs\StoreArtistImage;
use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Models\ArtistSplit;
use App\Models\RejectedAlias;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Entscheidungen im Künstlerverzeichnis – gemeinsam für die App und die Schnittstelle, damit beide genau dasselbe tun.
 * Das Verzeichnis ändert sich sofort; die Tags der betroffenen Titel werden nur vorgemerkt (geschrieben wird nie hier).
 */
class ArtistActions
{
    public function __construct(private ArtistDirectory $directory, private PendingChanges $pending) {}

    /**
     * Künstler anlegen bzw. ergänzen (vorhandene Felder werden nicht überschrieben).
     *
     * @param  list<string>  $aliases  weitere Schreibweisen
     * @return array{artist: Artist, created: bool, aliases: list<string>, staged: int}
     */
    public function create(string $name, ?string $mbid = null, ?string $genre = null, array $aliases = [], ?string $imageUrl = null, string $label = 'Neuer Künstler'): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Name fehlt.');
        }
        $artist = Artist::where('name', $name)->first();
        $created = ! $artist;
        $artist ??= Artist::create(['name' => $name]);
        $artist->fill(array_filter(['mbid' => $artist->mbid ?: $mbid, 'default_genre' => $artist->default_genre ?: $genre]))->save();
        $added = [];
        foreach ($aliases as $alias) {
            if ($this->addAliasQuietly($artist, (string) $alias)) {
                $added[] = trim((string) $alias);
            }
        }
        if ($artist->mbid) {
            $mb = rescue(fn () => app(Lookup::class)->musicbrainzArtistAliases($artist->mbid), [], report: false);
            array_push($added, ...($mb ? $this->directory->importMusicBrainzAliases($artist, $mb) : []));
        }
        if ($imageUrl && ! $artist->image) {
            StoreArtistImage::dispatch($artist->id, $imageUrl);
        }
        $this->directory->flush();
        $staged = $this->pending->stageForNames([$name, ...$added], $label);
        app(NameConfirmation::class)->evaluate($artist->fresh());

        return ['artist' => $artist->fresh(), 'created' => $created, 'aliases' => $added, 'staged' => $staged];
    }

    /** Umbenennen: alter Name wird Schreibweise, Bild zieht mit; gibt es den Namen schon, wird zusammengeführt. */
    public function rename(Artist $artist, string $name): array
    {
        $old = $artist->name;
        $artist = $this->directory->rename($artist, trim($name));
        $staged = $old !== $artist->name ? $this->pending->stageForNames([$old, $artist->name], 'Künstler umbenannt') : 0;

        return ['artist' => $artist, 'staged' => $staged];
    }

    /** „Ist derselbe wie …“: andere Einträge gehen im Ziel auf. */
    public function merge(Artist $target, array $ids): array
    {
        $result = $this->directory->merge($target, array_map('intval', $ids));
        $staged = $result['merged'] ? $this->pending->stageForNames([...$result['merged'], $target->name], 'Künstler zusammengeführt') : 0;

        return $result + ['staged' => $staged];
    }

    /** Schreibweise hinzufügen; gehört sie einem anderen Künstler, nicht still umhängen. */
    public function addAlias(Artist $artist, string $alias): array
    {
        $alias = trim($alias);
        if ($alias === '' || $alias === $artist->name) {
            throw new InvalidArgumentException('Schreibweise fehlt oder ist der Name selbst.');
        }
        $taken = ArtistAlias::with('artist')->where('alias', $alias)->where('artist_id', '!=', $artist->id)->first();
        if ($taken) {
            throw new InvalidArgumentException('„'.$alias.'“ ist schon eine Schreibweise von „'.($taken->artist?->name ?? '?').'“ – dort zuerst entfernen.');
        }
        $this->addAliasQuietly($artist, $alias);

        return ['staged' => $this->pending->stageForNames([$alias, $artist->name], 'Schreibweise')];
    }

    /** Schreibweise löschen und merken – sonst trägt das nächste Holen von MusicBrainz sie wieder ein. */
    public function removeAlias(Artist $artist, string $alias): bool
    {
        $row = ArtistAlias::where('artist_id', $artist->id)->where('alias', $alias)->first();
        if (! $row) {
            return false;
        }
        RejectedAlias::remember($artist->id, $row->alias);
        $row->delete();
        $this->directory->flush();

        return true;
    }

    /**
     * Eintrag ist eine Zusammenarbeit („Beam & Yanou“): Trennung merken (auch für seine Kombi-Schreibweisen), Eintrag
     * herausnehmen (Bild in den Papierkorb), Tags vormerken.
     *
     * @param  list<string>  $parts
     * @return array{parts: list<string>, names: list<string>, staged: int, new: list<string>}
     */
    public function splitInto(Artist $artist, array $parts): array
    {
        $parts = array_values(array_unique(array_filter(array_map('trim', $parts), fn ($p) => $p !== '' && mb_strtolower($p) !== mb_strtolower($artist->name))));
        if (count($parts) < 2) {
            throw new InvalidArgumentException('Mindestens zwei Künstler angeben.');
        }
        $names = [$artist->name, ...$artist->aliases()->pluck('alias')->filter(fn ($n) => ArtistNames::looksCollaboration($n))->all()];
        DB::transaction(function () use ($artist, $names, $parts) {
            foreach ($names as $n) {
                ArtistSplit::remember($n, $parts);
            }
            $dir = rtrim((string) app(Settings::class)->get('artist_images'), '/');
            if ($artist->image && is_file($dir.'/'.$artist->image)) {
                rescue(fn () => app(FileOps::class)->trash($dir.'/'.$artist->image), report: false);
            }
            $artist->delete();
        });
        $this->directory->flush();
        $staged = $this->pending->stageForNames([...$names, ...$parts], 'Trennen');
        RefreshCleanup::request();

        return ['parts' => $parts, 'names' => $names, 'staged' => $staged,
            'new' => array_values(array_filter($parts, fn ($p) => $this->directory->check($p)['status'] === 'new'))];
    }

    /**
     * Kombi-Vorschläge übernehmen („Kombi mit &, und, and, Komma“): trennen bzw. als ein Künstler anlegen.
     *
     * @param  list<string>  $names
     * @return array{split: array<string, list<string>>, artists: list<string>, staged: int, new: list<string>}
     */
    public function applyCombined(array $names): array
    {
        $suggestions = app(CombinedSuggestions::class)->for(array_values(array_filter($names)));
        $splits = array_filter($suggestions, fn ($s) => $s['kind'] === 'split');
        $artists = array_filter($suggestions, fn ($s) => $s['kind'] === 'artist');
        foreach ($splits as $name => $s) {
            ArtistSplit::remember((string) $name, $s['parts']);
        }
        $staged = $splits ? $this->pending->stageForNames(array_map('strval', array_keys($splits)), 'Trennen') : 0;
        if ($artists) {
            // wie „Schnell erfassen“: mit MusicBrainz-ID, Genre und Schreibweisen; MusicBrainz-Schreibweise („Simon and Garfunkel“ → „Simon & Garfunkel“)
            $picks = array_map(fn ($name, $s) => ['name' => (string) $name, 'hit' => $s['hit'], 'mb_spelling' => true], array_keys($artists), $artists);
            $staged += app(ArtistQuickMatch::class)->save($picks, app(Lookup::class), app(ArtistGenreSuggestions::class))['staged'];
        }
        $this->directory->flush();
        if ($suggestions) {
            RefreshCleanup::request();
        }
        $parts = array_values(array_unique(array_merge([], ...array_values(array_map(fn ($s) => $s['parts'], $splits)))));

        return ['split' => array_map(fn ($s) => $s['parts'], $splits), 'artists' => array_map('strval', array_keys($artists)), 'staged' => $staged,
            'new' => array_values(array_filter($parts, fn ($p) => $this->directory->check($p)['status'] === 'new'))];
    }

    /**
     * Gemerkte Trennung aufheben („Nicht mehr trennen“): der Kombi-Name gilt wieder als ein Name. Schon geschriebene
     * ARTISTS-Tags bleiben; noch vorgemerkte lassen sich verwerfen.
     *
     * @return array{name: string, parts: list<string>}
     */
    public function removeSplit(ArtistSplit $split): array
    {
        $result = ['name' => $split->name, 'parts' => (array) $split->parts];
        $split->delete();
        $this->directory->flush();
        RefreshCleanup::request();

        return $result;
    }

    private function addAliasQuietly(Artist $artist, string $alias): bool
    {
        $alias = trim($alias);
        if ($alias === '' || $alias === $artist->name || ArtistAlias::where('alias', $alias)->where('artist_id', '!=', $artist->id)->exists()) {
            return false;
        }
        ArtistAlias::updateOrCreate(['alias' => $alias], ['artist_id' => $artist->id]);
        RejectedAlias::forget($artist->id, $alias); // von Hand eingetragen: nicht mehr abgelehnt

        return true;
    }
}
