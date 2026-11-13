<?php

namespace App\Support;

use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Models\ArtistImage;
use App\Models\Track;
use App\Support\Lookup\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Künstlerverzeichnis: Schreibweisen pflegen, zusammenführen, Bild. Schreibweisen werden genau verglichen (Groß/klein
 * zählt – „Abba“ ist eine eigene Schreibweise neben „ABBA“). Lieder mit einer Schreibweise eines Künstlers bekommen
 * dessen Namen in die Tags – als System-Änderung mit Grund im Protokoll (nur in der Datenbank).
 */
class ArtistRegistry
{
    /** Bilder im Bilderordner: Kleinschreibung des Namens => Dateiname (je Durchlauf neu gelesen) */
    private ?array $imageFiles = null;

    /** Unbekannte Namen als unbestätigte Künstler anlegen (Bild aus dem Bilderordner, falls vorhanden). */
    public function create(array $names): int
    {
        $this->imageFiles = null;
        $now = now();
        $rows = array_map(fn ($n) => ['name' => mb_substr($n, 0, 255), 'confirmed' => false, 'image' => $this->existingImage($n), 'created_at' => $now, 'updated_at' => $now], $names);
        $n = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            $n += Artist::insertOrIgnore($chunk);
        }

        return $n;
    }

    /** Einen Interpreten von Hand anlegen (Modal „Interpret anlegen“). */
    public function add(string $name, bool $confirmed = true): Artist
    {
        $name = $this->clean($name);
        if ($owner = $this->ownerOf($name)) {
            throw new InvalidArgumentException('„'.$name.'“ gibt es schon'.($owner->name !== $name ? ' als Schreibweise von „'.$owner->name.'“' : '').'.');
        }

        return Artist::create(['name' => $name, 'confirmed' => $confirmed, 'image' => $this->existingImage($name)]);
    }

    /** Umbenennen – der bisherige Name bleibt als Schreibweise erhalten. Gibt die Zahl der angepassten Lieder zurück. */
    public function rename(Artist $artist, string $name): int
    {
        $name = $this->clean($name);
        if ($name === $artist->name) {
            return 0;
        }
        $owner = $this->ownerOf($name);
        if ($owner && $owner->id !== $artist->id) {
            throw new InvalidArgumentException('„'.$name.'“ gehört schon zu „'.$owner->name.'“ – dort zusammenführen.');
        }
        $old = $artist->name;
        DB::transaction(function () use ($artist, $name, $old) {
            ArtistAlias::where('artist_id', $artist->id)->where('alias', $name)->delete();
            $artist->update(['name' => $name]);
            ArtistAlias::create(['artist_id' => $artist->id, 'alias' => $old]);
            if (! $artist->imagePath() && ($image = $this->existingImage($name))) {
                $artist->update(['image' => $image]);
            }
        });
        app(ArtistImages::class)->renamed($artist->fresh(), $old);

        return $this->canonicalize($artist->fresh(), 'Interpret umbenannt: '.$old.' → '.$name);
    }

    public function addAlias(Artist $artist, string $alias): void
    {
        $alias = $this->clean($alias);
        $owner = $this->ownerOf($alias);
        if ($owner?->id === $artist->id) {
            return;
        }
        if ($owner) {
            throw new InvalidArgumentException('„'.$alias.'“ gehört schon zu „'.$owner->name.'“ – mit „Ist derselbe wie …“ zusammenführen.');
        }
        ArtistAlias::create(['artist_id' => $artist->id, 'alias' => $alias]);
        $this->canonicalize($artist->fresh(), 'Schreibweise „'.$alias.'“ → „'.$artist->name.'“');
    }

    /** Schreibweise entfernen: Lieder mit dieser Schreibweise gehören dann zu einem eigenen Künstler (Tags bleiben). */
    public function removeAlias(Artist $artist, string $alias): void
    {
        ArtistAlias::where('artist_id', $artist->id)->where('alias', $alias)->delete();
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $alias).'%';
        $ids = Track::where(fn ($q) => $q->where('artist', 'like', $like)->orWhere('albumartist', 'like', $like)
            ->orWhere('artists', 'like', $like)->orWhere('albumartists', 'like', $like))->pluck('id')->all();
        app(ArtistLinker::class)->linkIds($ids);
    }

    /**
     * „Ist derselbe wie …“: der andere Künstler wird mit Name und Schreibweisen zu Schreibweisen von $artist; wer als
     * „verschieden“ vom anderen markiert war, ist es dann auch von $artist. Nichts vom anderen geht verloren: leere
     * Felder (IDs, Sortiername, Bild) werden gefüllt, Treffer der Dienste, eigene Genres und Prüf-Ergebnisse wandern mit
     * (bei beiden vorhanden gilt das von $artist). Verschiedene MusicBrainz-Einträge: nur mit $conflict (ausdrücklich
     * gewählt) – automatisch (Namensregeln, Sortiernamen) nie. Gibt die Zahl der angepassten Lieder zurück.
     */
    public function merge(Artist $artist, Artist $other, bool $conflict = false): int
    {
        if ($artist->id === $other->id) {
            return 0;
        }
        if (! $conflict && self::conflict($artist, $other)) {
            throw new InvalidArgumentException('„'.$artist->name.'“ und „'.$other->name.'“ haben verschiedene MusicBrainz-Einträge – vermutlich zwei verschiedene.');
        }
        $trackIds = DB::table('track_artist')->where('artist_id', $other->id)->pluck('track_id')->all();
        DB::transaction(function () use ($artist, $other) {
            $distinct = DB::table('artist_distinct')->where('artist_id', $other->id)->pluck('other_id')
                ->merge(DB::table('artist_distinct')->where('other_id', $other->id)->pluck('artist_id'))->all();
            ArtistAlias::where('artist_id', $other->id)->update(['artist_id' => $artist->id]);
            $name = $other->name;
            $this->adopt($artist, $other);
            $artist->update(['confirmed' => $artist->confirmed || $other->confirmed]);
            $other->delete();
            ArtistAlias::create(['artist_id' => $artist->id, 'alias' => $name]);
            foreach ($distinct as $id) {
                $this->distinct([$artist->id, $id]);
            }
        });
        app(ArtistLinker::class)->linkIds($trackIds);

        return $this->canonicalize($artist->fresh(), '„'.$other->name.'“ ist „'.$artist->name.'“ (zusammengeführt)');
    }

    /** Haben beide einen MusicBrainz-Eintrag – verschiedene? */
    public static function conflict(Artist $a, Artist $b): bool
    {
        return $a->mbid && $b->mbid && $a->mbid !== $b->mbid;
    }

    /** Daten von $other übernehmen, bevor er gelöscht wird (was $artist schon hat, bleibt) */
    private function adopt(Artist $artist, Artist $other): void
    {
        $fill = [];
        foreach (['image', 'mbid', 'discogs_id', 'spotify_id', 'sort_name', 'looked_up_at'] as $field) {
            if (blank($artist->{$field}) && filled($other->{$field})) {
                $fill[$field] = $other->{$field};
            }
        }
        $fill && $artist->update($fill);
        // Treffer der Dienste: fehlende übernehmen; gewählt bleibt, was $artist gewählt hat
        $chosen = DB::table('artist_sources')->where('artist_id', $artist->id)->where('chosen', true)->pluck('service')->flip();
        foreach (DB::table('artist_sources')->where('artist_id', $other->id)->get() as $source) {
            $exists = DB::table('artist_sources')->where('artist_id', $artist->id)->where('service', $source->service)->where('external_id', $source->external_id)->exists();
            if (! $exists) {
                DB::table('artist_sources')->where('id', $source->id)->update(['artist_id' => $artist->id, 'chosen' => $source->chosen && ! isset($chosen[$source->service])]);
            }
        }
        DB::table('artist_lookups')->where('artist_id', $other->id)
            ->whereNotIn('service', DB::table('artist_lookups')->where('artist_id', $artist->id)->select('service'))->update(['artist_id' => $artist->id]);
        // eigene Genres (die anderen Quellen werden ohnehin neu abgeleitet)
        foreach (DB::table('artist_genre')->where('artist_id', $other->id)->where('source', 'manual')->get() as $g) {
            DB::table('artist_genre')->insertOrIgnore(['artist_id' => $artist->id, 'genre_id' => $g->genre_id, 'source' => 'manual', 'weight' => $g->weight]);
        }
        foreach (['artist_recording_checks', 'artist_spelling_checks'] as $table) {
            if (! DB::table($table)->where('artist_id', $artist->id)->exists()) {
                DB::table($table)->where('artist_id', $other->id)->update(['artist_id' => $artist->id]);
            }
        }
    }

    /**
     * „Ähnlich“ › Zusammenführen: $others werden zu Schreibweisen von $target, der bestätigt wird. Mit $name heißt er
     * danach so (vorher umbenannt, damit jedes Lied nur eine Änderung bekommt; gehört der Name schon einem der anderen,
     * wird der das Ziel). Gibt die Zahl der angepassten Lieder zurück.
     *
     * @param  list<int>  $others
     */
    public function mergeGroup(Artist $target, array $others, ?string $name = null): int
    {
        $others = Artist::whereIn('id', array_diff($others, [$target->id]))->get();
        $n = 0;
        if ($name !== null && trim($name) !== '') {
            $name = $this->clean($name);
            $owner = $this->ownerOf($name);
            if ($owner && $owner->id !== $target->id) {
                if (! $others->contains('id', $owner->id)) {
                    throw new InvalidArgumentException('„'.$name.'“ gehört schon zu „'.$owner->name.'“ – den in die Gruppe nehmen oder dort zusammenführen.');
                }
                // der Name gehört einem anderen Mitglied: das wird das Ziel
                [$others, $target] = [$others->reject(fn ($a) => $a->id === $owner->id)->push($target), $owner];
            }
            $n += $this->rename($target, $name);
        }
        foreach ($others as $other) {
            $n += $this->merge($target->fresh(), $other, true);   // ausdrücklich gewählt
        }
        $target->fresh()->update(['confirmed' => true]);

        return $n;
    }

    /** „Verschieden“: diese Interpreten sind paarweise nicht derselbe (werden nicht mehr als ähnlich vorgeschlagen). */
    public function distinct(array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        $rows = [];
        foreach ($ids as $i => $a) {
            foreach (array_slice($ids, $i + 1) as $b) {
                $rows[] = ['artist_id' => $a, 'other_id' => $b, 'created_at' => now()];
            }
        }
        DB::table('artist_distinct')->insertOrIgnore($rows);
    }

    /** „Verschieden“ aufheben: das Paar kann wieder als ähnlich vorgeschlagen werden. */
    public function undistinct(int $a, int $b): void
    {
        DB::table('artist_distinct')->where(['artist_id' => min($a, $b), 'other_id' => max($a, $b)])->delete();
    }

    /**
     * Lieder, die eine alternative Schreibweise des Künstlers tragen, bekommen seinen Namen (Interpret bzw.
     * Album-Interpret, auch als Teil einer Kombi wie „Calvin Harris feat. rihanna“, und in ARTISTS/ALBUMARTISTS) –
     * System-Änderung mit Grund. Gibt die Zahl der geänderten Lieder zurück.
     */
    public function canonicalize(Artist $artist, string $reason): int
    {
        $aliases = $artist->aliases()->pluck('alias')->all();
        if (! $aliases) {
            return 0;
        }
        $editor = app(TagEditor::class);
        $credits = app(ArtistCredits::class);
        $n = 0;
        $ids = $artist->tracks()->pluck('id')
            ->merge(Track::whereNotIn('location', Locations::IGNORED)->where(fn ($q) => $q->whereIn('artist', $aliases)->orWhereIn('albumartist', $aliases))->pluck('id'))->unique()->all();
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (Track::whereIn('id', $chunk)->get() as $track) {
                $values = [];
                foreach (ArtistCredits::FIELDS as $role => $list) {
                    $value = (string) $track->getAttribute($role);
                    if (in_array($value, $aliases, true)) {   // genau diese Schreibweise (Groß/klein)
                        $values[$role] = $artist->name;
                    } else {
                        foreach (array_intersect($aliases, $credits->credits($value)) as $alias) {
                            $values[$role] = self::replaceName($values[$role] ?? $value, $alias, $artist->name);
                        }
                    }
                    // einzeln: ist der Name selbst eine Kombi aus der Schreibweise („In-Mood“ → „In-Mood feat. Juliette“),
                    // bleibt der Teil stehen – er gehört über die Schreibweise ohnehin zum Künstler
                    $names = ArtistCredits::list($track->getAttribute($list));
                    $replace = array_filter($aliases, fn ($alias) => ! self::partOf($artist->name, $alias));
                    if (array_intersect($names, $replace)) {
                        $values[$list] = implode(ArtistCredits::SEPARATOR, array_unique(array_map(fn ($x) => in_array($x, $replace, true) ? $artist->name : $x, $names)));
                    }
                }
                if ($values && $editor->update($track, $values, TagEditor::SYSTEM, $reason)) {
                    $n++;
                }
            }
        }

        return $n;
    }

    /**
     * Einen Namen innerhalb einer Kombi ersetzen – nur als ganzes Wort („Bob“ in „Bob & Ann“, nicht in „Bobby“) und nicht
     * dort, wo der neue Name schon steht („In-Mood“ → „In-Mood feat. Juliette“ ergibt sonst beim zweiten Mal
     * „In-Mood feat. Juliette feat. Juliette“).
     */
    private static function replaceName(string $value, string $old, string $new): string
    {
        $at = strpos($new, $old);

        return preg_replace_callback('/(?<![\p{L}\p{N}])'.preg_quote($old, '/').'(?![\p{L}\p{N}])/u', function ($m) use ($value, $new, $at) {
            return $at !== false && $m[0][1] >= $at && substr($value, $m[0][1] - $at, strlen($new)) === $new ? $m[0][0] : $new;
        }, $value, -1, $count, PREG_OFFSET_CAPTURE) ?? $value;
    }

    /** Ist $alias ein Teil des Namens, der selbst eine Kombi ist („In-Mood“ in „In-Mood feat. Juliette“)? */
    private static function partOf(string $name, string $alias): bool
    {
        [$main, $guest] = ArtistCredits::splitGuest($name);

        return $name !== $alias && in_array($alias, [...ArtistCredits::suggest($main), ...($guest === null ? [] : ArtistCredits::suggest($guest))], true);
    }

    /** Bild aus dem Abgleich übernehmen: Original laden und als Interpretenbild speichern */
    public function adoptImage(Artist $artist, ArtistImage $image): void
    {
        app(Client::class)->pace('images');
        $response = Http::timeout(30)->withHeaders(['User-Agent' => Client::USER_AGENT])->get($image->url);
        if (! $response->successful()) {
            throw new InvalidArgumentException('Bild nicht erreichbar ('.$response->status().').');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'mwimg');
        file_put_contents($tmp, $response->body());
        try {
            $this->setImage($artist, $tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /** Bild setzen: als JPEG in den Bilderordner („Name.jpg“), höchstens 1200 px. */
    public function setImage(Artist $artist, string $sourceFile): void
    {
        $data = @file_get_contents($sourceFile);
        $img = $data ? @imagecreatefromstring($data) : false;
        if (! $img) {
            throw new InvalidArgumentException('Das ist kein lesbares Bild.');
        }
        $dir = rtrim((string) config('musicwiz.artist_images'), '/');
        @mkdir($dir, 0775, true);
        $file = self::fileName($artist->name);
        [$w, $h] = [imagesx($img), imagesy($img)];
        $scale = min(1, 1200 / max($w, $h));
        $img = $scale < 1 ? imagescale($img, (int) round($w * $scale), (int) round($h * $scale), IMG_BICUBIC) : $img;
        imagejpeg($img, $dir.'/'.$file, 88);
        $artist->update(['image' => $file]);
        // gleicher Dateiname, neues Bild: Zeitstempel trotzdem neu – er steht in der Bildadresse (Browser-Cache)
        $artist->touch();
        self::forgetThumbnails($artist);
    }

    /** Bild entfernen (die Datei bleibt im Ordner liegen – nichts wird gelöscht). */
    public function removeImage(Artist $artist): void
    {
        $artist->update(['image' => null]);
        self::forgetThumbnails($artist);
    }

    /** Zu welchem Künstler gehört dieser Name bzw. diese Schreibweise? */
    public function ownerOf(string $name): ?Artist
    {
        return Artist::where('name', $name)->first() ?? ArtistAlias::where('alias', $name)->first()?->artist;
    }

    public static function fileName(string $name): string
    {
        return trim(str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $name)).'.jpg';
    }

    public static function forgetThumbnails(Artist $artist): void
    {
        foreach (glob(config('musicwiz.cover_cache').'/artist-'.$artist->id.'-*') ?: [] as $f) {
            @unlink($f);
        }
    }

    /** Bild aus dem Bilderordner, falls es eins mit dem Namen gibt (Groß/klein egal, .jpg/.jpeg/.png) */
    private function existingImage(string $name): ?string
    {
        $this->imageFiles ??= collect(@scandir((string) config('musicwiz.artist_images')) ?: [])
            ->filter(fn ($f) => preg_match('/\.(jpe?g|png)$/i', $f))->mapWithKeys(fn ($f) => [mb_strtolower(pathinfo($f, PATHINFO_FILENAME)) => $f])->all();

        return $this->imageFiles[mb_strtolower(pathinfo(self::fileName($name), PATHINFO_FILENAME))] ?? null;
    }

    private function clean(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if ($name === '') {
            throw new InvalidArgumentException('Der Name darf nicht leer sein.');
        }

        return mb_substr($name, 0, 255);
    }
}
