<?php

namespace App\Support;

use App\Models\Artist;
use App\Models\NameRule;
use App\Models\Track;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Schreibweisen-Regeln auf Interpret und Album-Interpret anwenden – nur in der Datenbank, als System-Änderung mit Grund:
 * - Various Artists: der ganze Wert ist eine Schreibweise davon („Verschiedene“, „V. A.“, „various artists (compilation)“)
 *   → „Various Artists“. Verglichen ohne Groß/klein, Akzente, Punkte, Striche, Leerzeichen und Klammerzusatz am Ende.
 * - Namen: Suchtext ersetzen, ohne Groß/klein – nur als eigenes Wort („d.r. Alban“ → „Dr. Alban“, „Drake“ bleibt) oder
 *   auch innerhalb eines Worts. Steht die Ersetzung an der Stelle schon („Dr.“ bei der Regel „Dr“ → „Dr.“), bleibt es.
 * Interpreten, die danach keine Lieder mehr haben, werden mit dem neuen Namen zusammengeführt (alter Name bleibt Schreibweise).
 */
class NameRules
{
    public const VARIOUS = 'Various Artists';

    private ?array $various = null;

    private ?array $words = null;

    public function forget(): void
    {
        $this->various = $this->words = null;
    }

    /** „V. A.“, „V/A“ → „va“; „Various Artists (Compilation)“ → „variousartists“ */
    public static function normalize(string $value): string
    {
        $s = Str::ascii(mb_strtolower(trim($value)));
        do {
            $before = $s;
            $s = preg_replace('/\s*[\(\[][^\(\)\[\]]*[\)\]]\s*$/u', '', $s) ?? $s;
        } while ($s !== $before && $s !== '');

        return preg_replace('/[.\-_\/\s]+/u', '', $s) ?? $s;
    }

    /** „Various Artists“ selbst oder eine Schreibweise davon (Liste unter Einstellungen › Schreibweisen) */
    public function isVarious(string $name): bool
    {
        $this->various ??= array_flip(array_map(self::normalize(...), [self::VARIOUS, ...NameRule::where('kind', 'various')->pluck('value')->all()]));
        $key = self::normalize($name);

        return $key !== '' && isset($this->various[$key]);
    }

    /** Wie der Name nach den Regeln heißen soll (unverändert, wenn keine greift) */
    public function fix(string $name): string
    {
        if (trim($name) === '' || $name === self::VARIOUS) {
            return $name;
        }
        if ($this->isVarious($name)) {
            return self::VARIOUS;
        }
        $this->words ??= NameRule::where('kind', 'word')->orderByRaw('CHAR_LENGTH(value) DESC')->get(['value', 'replacement', 'whole_word'])->all();
        foreach ($this->words as $rule) {
            $name = self::replace($name, $rule->value, (string) $rule->replacement, (bool) $rule->whole_word);
        }

        return $name;
    }

    /** Suchtext ersetzen (ohne Groß/klein); eigenes Wort = davor und danach kein Buchstabe und keine Ziffer */
    public static function replace(string $name, string $search, string $replacement, bool $wholeWord): string
    {
        $pattern = preg_quote($search, '/');
        // Ersetzung beginnt mit dem Suchtext („Dr“ → „Dr.“): vorhandenen Rest mit ersetzen („dr. Dre“ → „Dr. Dre“, nicht „Dr.. Dre“)
        if (mb_stripos($replacement, $search) === 0 && mb_strlen($replacement) > mb_strlen($search)) {
            $pattern .= '(?:'.preg_quote(mb_substr($replacement, mb_strlen($search)), '/').')?+';
        }
        if ($wholeWord) {
            $pattern = '(?<![\p{L}\p{N}])'.$pattern.'(?![\p{L}\p{N}])';
        }

        return preg_replace_callback('/'.$pattern.'/iu', function ($m) use ($name, $replacement) {
            [$found, $offset] = $m[0];

            // steht die Ersetzung hier schon („Dr.“ bei „Dr“ → „Dr.“, „DJ“ bei „dj“ → „DJ“): nichts tun
            return substr($name, $offset, strlen($replacement)) === $replacement ? $found : $replacement;
        }, $name, -1, $count, PREG_OFFSET_CAPTURE) ?? $name;
    }

    /** Alle Lieder prüfen. @return int Zahl der geänderten Lieder */
    public function apply(): int
    {
        $this->forget();
        $map = [];
        foreach (ArtistLinker::ROLES as $role) {
            // binär: „DJ Bobo“ und „Dj Bobo“ sind verschiedene Werte (die Spalte vergleicht ohne Groß/klein)
            foreach (Track::whereNotIn('location', Locations::IGNORED)->whereNotNull($role)->selectRaw('DISTINCT CAST('.$role.' AS BINARY) AS v')->pluck('v') as $value) {
                $fixed = $this->fix((string) $value);
                if ($fixed !== $value) {
                    $map[$value] = $fixed;
                }
            }
        }
        if (! $map) {
            self::removeVariousArtists();

            return 0;
        }
        $editor = app(TagEditor::class);
        $changed = 0;
        foreach (array_chunk(array_keys($map), 500, true) as $values) {
            $tracks = Track::whereNotIn('location', Locations::IGNORED)->where(fn ($q) => $q->whereIn('artist', $values)->orWhereIn('albumartist', $values))->get();
            foreach ($tracks as $track) {
                $new = [];
                foreach (ArtistLinker::ROLES as $role) {
                    $value = $track->getAttribute($role);
                    if ($value !== null && isset($map[$value]) && $map[$value] !== $value) {
                        $new[$role] = $map[$value];
                    }
                }
                if ($new && $editor->update($track, $new, TagEditor::SYSTEM, $this->reason($map, $new, $track))) {
                    $changed++;
                }
            }
        }
        self::removeVariousArtists();
        $this->mergeOrphans($map);

        return $changed;
    }

    private function reason(array $map, array $new, Track $track): string
    {
        $parts = [];
        foreach ($new as $role => $value) {
            $old = (string) $track->getOriginal($role);
            $parts[$old] = '„'.$old.'“ → „'.$value.'“';
        }

        return 'Schreibweise: '.implode(', ', $parts).(in_array(self::VARIOUS, $new, true) ? ' (Various Artists)' : '');
    }

    /**
     * Interpreten nachziehen: der alte Name ohne Lieder geht im neuen auf (Name wird Schreibweise, Bild bleibt); ist der
     * neue Name nur eine Schreibweise des alten Interpreten, wird dieser umbenannt. Schreibweisen von Various Artists
     * sind kein Interpret – deren Einträge werden entfernt.
     */
    private function mergeOrphans(array $map): void
    {
        $registry = app(ArtistRegistry::class);
        foreach ($map as $old => $new) {
            if ($new === self::VARIOUS) {
                continue;
            }
            $artist = Artist::where('name', $old)->first();
            $target = $registry->ownerOf($new);
            if (! $artist || ! $target) {
                continue;
            }
            if ($target->id === $artist->id) {
                $registry->rename($artist, $new);
            } elseif (! DB::table('track_artist')->where('artist_id', $artist->id)->exists() && ! ArtistRegistry::conflict($target, $artist)) {
                $registry->merge($target, $artist);
            }
        }
    }

    /** Einträge im Interpretenverzeichnis, die Various Artists sind, entfernen (samt Verknüpfungen und Abgleich) */
    public static function removeVariousArtists(): int
    {
        $rules = app(self::class);
        $ids = Artist::get(['id', 'name'])->filter(fn ($a) => $rules->isVarious($a->name))->pluck('id')->all();
        if (! $ids) {
            return 0;
        }
        DB::transaction(function () use ($ids) {
            DB::table('track_artist')->whereIn('artist_id', $ids)->delete();
            Artist::whereIn('id', $ids)->delete();
        });

        return count($ids);
    }
}
