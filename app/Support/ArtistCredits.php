<?php

namespace App\Support;

use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Models\ArtistSplit;
use App\Models\Track;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Kombis im Interpreten-Tag in einzelne Interpreten zerlegen – das Interpret-Tag bleibt die Anzeige („Calvin Harris
 * feat. Rihanna“), die Teile kommen ins Tag ARTISTS bzw. ALBUMARTISTS (Spalten artists/albumartists, „; “ getrennt).
 * - feat./ft./featuring: Gast – automatisch getrennt, Schreibweise wird „feat.“ – nur wenn eindeutig: mit Punkt, in
 *   Klammern, „featuring“ oder klein geschrieben; „Feat“/„Ft“ ohne Punkt kann zum Namen gehören („Little Feat“) und
 *   wartet wie eine Kombi auf eine Entscheidung. Steht ein bestätigter Interpret vorn, der das Wort enthält, bleibt er.
 * - vs./versus, „;“ und „ / “: automatisch getrennt
 * - &, and, und, Komma, x, with, +: nur nach Entscheidung (Interpreten › Kombis), gemerkt in artist_splits
 * Ganz bleibt, was bestätigt im Verzeichnis steht (Name oder Schreibweise) oder als „bleibt ganz“ entschieden ist
 * („Simon & Garfunkel“). „Ganz + Mitglieder“ gilt immer – auch für Lieder, die nach der Entscheidung dazukommen. Album-Interpret: Gäste fallen weg („A feat. B“ → „A“).
 * Steht ARTISTS schon in der Datei, bleibt es; abgeleitet wird nur, solange es leer ist bzw. der Ableitung entspricht.
 */
class ArtistCredits
{
    public const SEPARATOR = '; ';

    /** Felder mit Kombi => Feld mit den einzelnen Interpreten */
    public const FIELDS = ['artist' => 'artists', 'albumartist' => 'albumartists'];

    /** eindeutiger Gast: in Klammern, mit Punkt, „featuring“ oder klein geschrieben */
    private const GUEST = '/(?|(\s+[\(\[])(?i:feat\.?|ft\.?|featuring)(\s+)|(\s+)(?:(?i:feat\.|ft\.|featuring)|feat|ft)(\s+))/u';

    /** könnte ein Gast sein („Feat“/„FT“ ohne Punkt) – oder Teil des Namens */
    private const MAYBE_GUEST = '/\s+(?:feat|ft)\s+/iu';

    private const AUTO = '/\s+(?:vs\.?|versus)\s+|\s*;\s*|\s+\/\s+/iu';

    /** Komma vor nachgestelltem Artikel („Drifters, The“) trennt nicht */
    private const ASK = '/\s*,\s+(?!(?:the|die|der|das|les|la|le|los|las|el|il)\s*$)|\s+(?:&|and|und|x|with|\+)\s+/iu';

    /** Vorfilter in der Datenbank (MariaDB-REGEXP): könnte eine Kombi sein */
    private const SQL_ANY = '(?i)(feat|\\bft\\b|featuring|\\bvs\\b|versus|;| / |&|\\band\\b|\\bund\\b|, |\\bx\\b|\\bwith\\b| \\+ )';

    private const SQL_ASK = '(?i)(&|\\band\\b|\\bund\\b|, |\\bx\\b|\\bwith\\b| \\+ |\\bfeat\\b|\\bft\\b)';

    private const SQL_TITLE = '(?i)(\\bfeat\\b|\\bft\\b|featuring)';

    /** @var array<string, array{kind: string, parts: ?list<string>}>|null Entscheidungen je Name */
    private ?array $splits = null;

    /** @var array<string, true>|null Namen und Schreibweisen bestätigter Interpreten */
    private ?array $confirmed = null;

    public function forget(): void
    {
        $this->splits = $this->confirmed = null;
    }

    /**
     * Zerlegen: Haupt-Interpreten, Gäste und Teile, die noch auf eine Entscheidung warten (bleiben vorerst ganz).
     *
     * @return array{main: list<string>, guests: list<string>, open: list<string>}
     */
    public function parse(?string $value): array
    {
        $value = trim((string) $value);
        if ($value === '') {
            return ['main' => [], 'guests' => [], 'open' => []];
        }
        if ($members = $this->members($value)) {
            return ['main' => [$value, ...$members], 'guests' => [], 'open' => []];
        }
        if ($this->isWhole($value)) {
            return ['main' => [$value], 'guests' => [], 'open' => []];
        }
        [$main, $guest] = $this->guest($value);
        $open = [];

        return ['main' => $this->pieces($main, $open), 'guests' => $guest === null ? [] : $this->pieces($guest, $open), 'open' => array_values(array_unique($open))];
    }

    /** Alle einzelnen Interpreten, Haupt-Interpreten zuerst */
    public function credits(?string $value): array
    {
        $p = $this->parse($value);

        return array_values(array_unique([...$p['main'], ...$p['guests']]));
    }

    /** Wert für ARTISTS: die Teile mit „; “ – null, wenn es nur einer ist */
    public function derived(?string $value): ?string
    {
        $credits = $this->credits($value);

        return count($credits) > 1 ? implode(self::SEPARATOR, $credits) : null;
    }

    /**
     * Folgt ARTISTS dem Interpreten – leer, dessen Zerlegung oder genau der Interpret als einziger Wert (so schreiben
     * es manche Programme)? Dann wird es beim Ändern des Interpreten mitgezogen; sonst ist es von Hand gepflegt.
     */
    public function follows(?string $list, ?string $value): bool
    {
        return $list === null || $list === $this->derived($value) || self::list($list) === [trim((string) $value)];
    }

    /** ARTISTS zum neuen Interpreten: die Zerlegung; bei nur einem Interpreten bleibt ein vorhandener Wert als dieser */
    public function follow(?string $list, ?string $value): ?string
    {
        return $this->derived($value) ?? ($list !== null && trim((string) $value) !== '' ? trim((string) $value) : null);
    }

    /** „A ft. B“ / „A (Featuring B)“ → „A feat. B“ / „A (feat. B)“ */
    public function normalize(string $value): string
    {
        if ($this->isWhole($value) || $this->guest($value)[1] === null) {
            return $value;
        }

        return preg_replace_callback(self::GUEST, fn ($m) => $m[1].'feat.'.$m[2], $value, 1) ?? $value;
    }

    /** wie splitGuest – aber ein bestätigter Interpret, der das Gast-Wort im Namen trägt („Mike feat. Ike“), bleibt ganz */
    private function guest(string $value): array
    {
        [$main, $guest] = self::splitGuest($value);
        if ($guest === null || ! preg_match(self::GUEST, $value, $m, PREG_OFFSET_CAPTURE)) {
            return [$value, null];
        }
        // vom Anfang über das Gast-Wort bis zum nächsten Trenner: ist das ein bestätigter Name, ist es kein Gast
        $end = $m[0][1] + strlen($m[0][0]);
        $after = preg_split('/\s*[\)\];,]|\s+(?:&|and|und|x|with|vs\.?|versus|\+|\/|(?i:feat\.?|ft\.?|featuring))\s+/u', substr($value, $end), 2)[0];
        if ($this->isWhole(trim(substr($value, 0, $end).$after))) {
            return [$value, null];
        }

        return [$main, $guest];
    }

    /** Album-Interpret ohne Gäste: „A feat. B“ → „A“ */
    public function withoutGuests(string $value): string
    {
        return $this->isWhole($value) ? $value : $this->guest($value)[0];
    }

    /** „A; B“ → ['A', 'B'] */
    public static function list(?string $value): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(';', (string) $value)), fn ($v) => $v !== '')));
    }

    /** ARTISTS/ALBUMARTISTS aus den rohen Tags der Datei (ID3, MP4, ASF, Vorbis) */
    public static function fromRawTags(array $raw): array
    {
        $upper = array_change_key_case($raw, CASE_UPPER);
        $find = function (array $keys) use ($upper) {
            foreach ($keys as $key) {
                if ($values = self::list(implode(';', (array) ($upper[strtoupper($key)] ?? [])))) {
                    return mb_substr(implode(self::SEPARATOR, $values), 0, 1024);
                }
            }

            return null;
        };

        return [
            'artists' => $find(['TXXX:ARTISTS', '----:com.apple.iTunes:ARTISTS', 'WM/ARTISTS', 'ARTISTS']),
            'albumartists' => $find(['TXXX:ALBUMARTISTS', '----:com.apple.iTunes:ALBUMARTISTS', 'WM/ALBUMARTISTS', 'ALBUMARTISTS']),
        ];
    }

    /**
     * Regeln auf die Lieder anwenden (alle bzw. diese IDs): „feat.“ vereinheitlichen, Gäste aus dem Album-Interpret,
     * ARTISTS/ALBUMARTISTS füllen – als System-Änderung mit Grund. Danach Kombi-Einträge ohne Lieder entfernen.
     *
     * @return int Zahl der geänderten Lieder
     */
    public function apply(?array $ids = null): int
    {
        $this->forget();
        $editor = app(TagEditor::class);
        $n = 0;
        Track::whereNotIn('location', Locations::IGNORED)->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->where(fn ($q) => $q->whereRaw('artist REGEXP ?', [self::SQL_ANY])->orWhereRaw('albumartist REGEXP ?', [self::SQL_ANY]))
            ->chunkById(500, function ($tracks) use ($editor, &$n) {
                foreach ($tracks as $track) {
                    $values = $this->wanted($track);
                    if ($values && $editor->update($track, $values, TagEditor::SYSTEM, $this->reason($track, $values))) {
                        $n++;
                    }
                }
            });
        $this->removeCombos();

        return $n;
    }

    /** Was sich an einem Lied ändern soll */
    private function wanted(Track $track): array
    {
        $values = [];
        if (($artist = (string) $track->artist) !== '') {
            $fixed = $this->normalize($artist);
            if ($fixed !== $artist) {
                $values['artist'] = $fixed;
            }
            if ($this->follows($track->artists, $artist) && ($derived = $this->derived($fixed)) && $derived !== $track->artists) {
                $values['artists'] = $derived;
            }
        }
        if (($albumartist = (string) $track->albumartist) !== '') {
            $fixed = $this->withoutGuests($this->normalize($albumartist));
            if ($fixed !== $albumartist) {
                $values['albumartist'] = $fixed;
            }
            if ($this->follows($track->albumartists, $albumartist) && ($derived = $this->derived($fixed)) && $derived !== $track->albumartists) {
                $values['albumartists'] = $derived;
            }
        }

        return $values;
    }

    private function reason(Track $track, array $values): string
    {
        $parts = [];
        foreach (['artist' => 'Interpret', 'albumartist' => 'Album-Interpret'] as $field => $label) {
            if (isset($values[$field])) {
                $parts[] = $label.' „'.$track->getOriginal($field).'“ → „'.$values[$field].'“';
            }
            if (isset($values[self::FIELDS[$field]])) {
                $parts[] = $label.' einzeln: '.str_replace(self::SEPARATOR, ' · ', $values[self::FIELDS[$field]]);
            }
        }

        return 'Kombi: '.implode(', ', $parts);
    }

    /**
     * Interpreten › Kombis: Teile, die auf eine Entscheidung warten, mit Zahl der Lieder und Vorschlag. Zuerst die, deren
     * Teile alle auch allein Lieder haben, dann die mit lauter bekannten Teilen, dann nach Liedern.
     *
     * @return list<array{name: string, tracks: int, parts: list<string>, known: array<string, array{id: int, name: string, tracks: int, alone: int, others: int}>}>
     */
    public function open(): array
    {
        $this->forget();
        $counts = [];
        foreach (self::FIELDS as $field => $list) {
            // stehen mehrere Interpreten schon in ARTISTS (z. B. aus der Datei), ist die Kombi entschieden
            $rows = Track::whereNotIn('location', Locations::IGNORED)->whereRaw($field.' REGEXP ?', [self::SQL_ASK])->where(fn ($q) => $q->whereNull($list)->orWhere($list, 'not like', '%;%'))->selectRaw('CAST('.$field.' AS BINARY) AS v, COUNT(*) AS n')->groupBy('v')->get();
            foreach ($rows as $r) {
                foreach ($this->parse((string) $r->v)['open'] as $piece) {
                    $counts[$piece] = ($counts[$piece] ?? 0) + (int) $r->n;
                }
            }
        }
        $suggested = array_map(fn ($piece) => self::suggest($piece), array_combine(array_keys($counts), array_keys($counts)));
        $known = $this->known(array_merge([], ...array_values($suggested)));
        $out = [];
        foreach ($counts as $name => $tracks) {
            $parts = $suggested[$name];
            $out[] = ['name' => (string) $name, 'tracks' => $tracks, 'parts' => $parts,
                'known' => array_intersect_key($known, array_flip($parts))];
        }
        // zuerst: jeder Teil hat auch eigene Lieder (dann ist Trennen fast sicher), dann: alle Teile bekannt
        $rank = fn ($c) => [self::allAlone($c) ? 0 : (count($c['known']) === count($c['parts']) ? 1 : 2), -$c['tracks'], mb_strtolower($c['name'])];
        usort($out, fn ($a, $b) => $rank($a) <=> $rank($b));

        return $out;
    }

    /** Hat jeder Teil der Kombi auch Lieder, auf denen er allein steht? */
    public static function allAlone(array $combo): bool
    {
        foreach ($combo['parts'] as $part) {
            if (($combo['known'][$part]['alone'] ?? 0) === 0) {
                return false;
            }
        }

        return true;
    }

    /** Vorschlag zum Trennen: an &, and, und, Komma, x, with, + und an „Feat“/„Ft“ ohne Punkt */
    public static function suggest(string $piece): array
    {
        $parts = preg_split(self::ASK, $piece);
        $parts = array_merge(...array_map(fn ($p) => preg_split(self::MAYBE_GUEST, $p), $parts));

        return array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
    }

    /**
     * Namen, die es als Interpret (Name oder Schreibweise) schon gibt, ohne etwas anzulegen – mit Liedern: „allein“ =
     * der Interpret steht allein im Interpret-Tag (Name oder eine Schreibweise), „mit anderen“ = als Teil einer
     * Kombi (feat., vs. …) bzw. als Gast.
     *
     * @return array<string, array{id: int, name: string, tracks: int, alone: int, others: int}>
     */
    public function known(array $names): array
    {
        $names = array_values(array_unique($names));
        if (! $names) {
            return [];
        }
        $owners = Artist::whereIn('name', $names)->pluck('id', 'name')->all();
        foreach (ArtistAlias::whereIn('alias', $names)->pluck('artist_id', 'alias') as $alias => $id) {
            $owners[$alias] ??= $id;
        }
        $ids = array_values(array_unique($owners));
        $artists = Artist::whereIn('id', $ids)->pluck('name', 'id');
        $tracks = DB::table('track_artist')->whereIn('artist_id', $ids)->selectRaw('artist_id, COUNT(DISTINCT track_id) AS n')->groupBy('artist_id')->pluck('n', 'artist_id');
        $performed = DB::table('track_artist')->whereIn('artist_id', $ids)->whereIn('role', ['artist', 'guest'])
            ->selectRaw('artist_id, COUNT(DISTINCT track_id) AS n')->groupBy('artist_id')->pluck('n', 'artist_id');
        // allein: Interpret-Tag ist genau der Name oder eine Schreibweise (binär verglichen – Groß/klein zählt)
        $spellingOf = [];
        foreach ($artists as $id => $name) {
            $spellingOf[$name] = $id;
        }
        foreach (ArtistAlias::whereIn('artist_id', $ids)->pluck('artist_id', 'alias') as $alias => $id) {
            $spellingOf[$alias] = $id;
        }
        $alone = [];
        foreach (array_chunk(array_keys($spellingOf), 1000) as $chunk) {
            $rows = Track::whereNotIn('location', Locations::IGNORED)->whereIn('artist', $chunk)->selectRaw('CAST(artist AS BINARY) AS v, COUNT(*) AS n')->groupBy('v')->get();
            foreach ($rows as $r) {
                if (isset($spellingOf[$r->v])) {
                    $alone[$spellingOf[$r->v]] = ($alone[$spellingOf[$r->v]] ?? 0) + (int) $r->n;
                }
            }
        }

        return array_map(fn ($id) => ['id' => $id, 'name' => $artists[$id], 'tracks' => (int) ($tracks[$id] ?? 0),
            'alone' => $alone[$id] ?? 0, 'others' => max(0, (int) ($performed[$id] ?? 0) - ($alone[$id] ?? 0))], $owners);
    }

    /** Entscheidung „Trennen“: in diese Teile (fehlende werden als Interpreten angelegt). Gibt die Zahl der geänderten Lieder zurück. */
    public function split(string $name, array $parts): int
    {
        $parts = self::clean($parts);
        if (count($parts) < 2) {
            throw new InvalidArgumentException('Zum Trennen braucht es mindestens zwei Teile.');
        }

        return $this->decide(trim($name), ArtistSplit::SPLIT, $parts, 'Kombi „'.trim($name).'“ getrennt: '.implode(' · ', $parts));
    }

    /** Entscheidung „Bleibt ganz“: ein eigener Interpret (wird bestätigt). */
    public function keepWhole(string $name): int
    {
        return $this->decide(trim($name), ArtistSplit::WHOLE, null, 'Kombi „'.trim($name).'“ bleibt ganz');
    }

    /**
     * Entscheidung „Bleibt ganz + Mitglieder“: die Gruppe bleibt Interpret (bestätigt), die Mitglieder kommen mit ins
     * ARTISTS-Tag („Beam & Yanou; Beam; Yanou“) – so findet sich das Lied auch bei ihnen.
     */
    public function keepWithMembers(string $name, array $members): int
    {
        $name = trim($name);
        $members = array_values(array_diff(self::clean($members), [$name]));
        if (! $members) {
            throw new InvalidArgumentException('Keine Mitglieder angegeben.');
        }

        return $this->decide($name, ArtistSplit::MEMBERS, $members, 'Kombi „'.$name.'“ bleibt ganz, mit Mitgliedern: '.implode(' · ', $members));
    }

    /**
     * Entscheidung speichern: betroffene Lieder bekommen ARTISTS neu (wenn es der bisherigen Ableitung entsprach) und
     * werden neu verbunden; neue Teile werden als Interpreten angelegt. Ganz (mit oder ohne Mitglieder) bestätigt den
     * Interpreten.
     */
    private function decide(string $name, string $kind, ?array $parts, string $reason): int
    {
        $previous = ArtistSplit::where('name', $name)->value('confirmed_artist');
        // war er schon bestätigt (nicht durch eine frühere Entscheidung), bleibt er es auch nach dem Aufheben
        $byDecision = $kind !== ArtistSplit::SPLIT && ($previous ?? ! Artist::where('name', $name)->where('confirmed', true)->exists());

        return $this->change($name, fn () => ArtistSplit::updateOrCreate(['name' => $name], ['kind' => $kind, 'parts' => $parts, 'confirmed_artist' => $byDecision]), $reason, function () use ($name, $kind) {
            if ($kind !== ArtistSplit::SPLIT) {
                Artist::where('name', $name)->update(['confirmed' => true]);
            }
        });
    }

    /** Namen säubern: Leerzeichen, Doppelte, Leere */
    private static function clean(array $names): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($p) => trim(preg_replace('/\s+/u', ' ', (string) $p)), $names), fn ($p) => $p !== '')));
    }

    /**
     * Entscheidung aufheben: die Kombi wartet wieder auf eine Entscheidung (ARTISTS wie vorher, wenn unverändert). Hat
     * erst die Entscheidung den Interpreten bestätigt, wird das zurückgenommen.
     */
    public function undo(string $name): int
    {
        return $this->change($name, function () use ($name) {
            if (ArtistSplit::where('name', $name)->value('confirmed_artist')) {
                Artist::where('name', $name)->update(['confirmed' => false]);
            }
            ArtistSplit::where('name', $name)->delete();
        }, 'Entscheidung zu „'.$name.'“ aufgehoben');
    }

    /** Alle Entscheidungen, neueste zuerst */
    public function decisions(): Collection
    {
        return ArtistSplit::orderByDesc('updated_at')->orderBy('name')->get();
    }

    private function change(string $name, callable $store, string $reason, ?callable $after = null): int
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $name).'%';
        $tracks = Track::whereNotIn('location', Locations::IGNORED)->where(fn ($q) => $q->where('artist', 'like', $like)->orWhere('albumartist', 'like', $like))->get();
        $this->forget();
        $before = $tracks->mapWithKeys(fn ($t) => [$t->id => array_map(fn ($field) => $this->derived($t->$field), array_flip(self::FIELDS))]);
        $store();
        $this->forget();
        $editor = app(TagEditor::class);
        $n = 0;
        foreach ($tracks as $track) {
            $values = [];
            foreach (self::FIELDS as $field => $list) {
                // mitziehen, wenn ARTISTS die bisherige Zerlegung ist – oder nur der Interpret selbst und jetzt getrennt wird
                $current = $track->$list;
                $wanted = $this->derived($track->$field);
                $mirror = $wanted !== null && $current !== null && self::list($current) === [trim((string) $track->$field)];
                if (($current === $before[$track->id][$list] || $mirror) && $wanted !== $current) {
                    $values[$list] = $wanted;
                }
            }
            if ($values && $editor->update($track, $values, TagEditor::SYSTEM, $reason)) {
                $n++;
            }
        }
        app(ArtistLinker::class)->linkIds($tracks->pluck('id')->all());
        if ($after) {
            $after();
        }
        $this->removeCombos();

        return $n;
    }

    /** Unbestätigte Interpreten, deren Name eine getrennte Kombi ist und die keine Lieder mehr haben, entfernen */
    public function removeCombos(): int
    {
        $this->forget();
        $ids = Artist::where('confirmed', false)->whereRaw('name REGEXP ?', [self::SQL_ANY])
            ->whereNotExists(fn ($q) => $q->from('track_artist')->whereColumn('track_artist.artist_id', 'artists.id'))
            ->get(['id', 'name'])->filter(fn ($a) => count($this->credits($a->name)) > 1)->pluck('id')->all();

        return $ids ? Artist::whereIn('id', $ids)->delete() : 0;
    }

    /**
     * feat. im Titel: „Song (feat. X)“ von „A“ → Titel „Song“, Interpret „A feat. X“ (nur als Vorschlag).
     *
     * @return list<array{track: Track, title: string, artist: string}>
     */
    public function titleSuggestions(?array $ids = null): array
    {
        $this->forget();
        $out = [];
        $tracks = Track::whereRaw('title REGEXP ?', [self::SQL_TITLE])->whereNotNull('artist')->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('artist')->orderBy('title')->get(['id', 'title', 'artist', 'album', 'location', 'filename', 'duration']);
        foreach ($tracks as $track) {
            if (preg_match('/^(.*?)\s*[\(\[]\s*(?:feat\.?|ft\.?|featuring)\s+([^\)\]]+?)\s*[\)\]](.*)$/iu', $track->title, $m)) {
                [$title, $guest] = [trim($m[1].$m[3]), trim($m[2])];
            } elseif (preg_match('/^(.+?)\s+(?:feat\.|featuring)\s+(.+)$/iu', $track->title, $m)) {   // ohne Klammern nicht „ft“ („Ft. Lauderdale“)
                [$title, $guest] = [trim($m[1]), trim($m[2])];
            } else {
                continue;
            }
            if ($title === '' || $guest === '') {
                continue;
            }
            $artist = $track->artist;
            if (! in_array($guest, $this->credits($artist), true)) {
                $artist .= self::splitGuest($artist)[1] === null ? ' feat. '.$guest : ' & '.$guest;
            }
            $out[] = ['track' => $track, 'title' => $title, 'artist' => $artist];
        }

        return $out;
    }

    /** Vorschläge „feat. im Titel“ übernehmen (System-Änderung mit Grund). Gibt die Zahl der geänderten Lieder zurück. */
    public function moveTitleGuests(array $ids): int
    {
        $editor = app(TagEditor::class);
        $n = 0;
        foreach ($this->titleSuggestions($ids) as $s) {
            $track = Track::find($s['track']->id);
            if ($track && $editor->update($track, ['title' => $s['title'], 'artist' => $s['artist']], TagEditor::SYSTEM, 'feat. aus dem Titel in den Interpreten')) {
                $n++;
            }
        }

        return $n;
    }

    /** [Haupt-Interpret, Gast oder null] – „A (feat. B)“ → ['A', 'B'] */
    public static function splitGuest(string $value): array
    {
        if (! preg_match(self::GUEST, $value, $m, PREG_OFFSET_CAPTURE)) {
            return [$value, null];
        }
        $main = trim(substr($value, 0, $m[0][1]));
        $guest = trim(substr($value, $m[0][1] + strlen($m[0][0])));
        $open = trim($m[1][0]);
        if ($open !== '') {
            $guest = trim(preg_replace('/\s*'.($open === '(' ? '\)' : '\]').'\s*$/u', '', $guest));
        }

        return $main === '' || $guest === '' ? [$value, null] : [$main, $guest];
    }

    /** Teile eines Haupt- bzw. Gast-Abschnitts; noch nicht entschiedene Kombis kommen nach $open */
    private function pieces(string $s, array &$open): array
    {
        if ($members = $this->members($s)) {
            return [$s, ...$members];
        }
        if ($this->isWhole($s)) {
            return [$s];
        }
        $out = [];
        foreach (preg_split(self::AUTO, $s) as $piece) {
            $piece = trim($piece);
            if ($piece === '') {
                continue;
            }
            if ($members = $this->members($piece)) {
                array_push($out, $piece, ...$members);

                continue;
            }
            if (! $this->isWhole($piece) && isset($this->splits[$piece])) {
                // getrennt: die Teile – ganz mit Mitgliedern: die Gruppe und ihre Mitglieder
                $d = $this->splits[$piece];
                array_push($out, ...($d['kind'] === ArtistSplit::MEMBERS ? [$piece, ...$d['parts']] : $d['parts']));

                continue;
            }
            if (! $this->isWhole($piece) && (preg_match(self::ASK, $piece) || preg_match(self::MAYBE_GUEST, $piece))) {
                $open[] = $piece;
            }
            $out[] = $piece;
        }

        return $out;
    }

    /** Mitglieder, wenn die Kombi als „ganz + Mitglieder“ entschieden ist */
    private function members(string $name): ?array
    {
        $this->load();

        return ($this->splits[$name]['kind'] ?? null) === ArtistSplit::MEMBERS ? $this->splits[$name]['parts'] : null;
    }

    private function isWhole(string $name): bool
    {
        $this->load();

        return isset($this->confirmed[$name]) || ($this->splits[$name]['kind'] ?? null) === ArtistSplit::WHOLE;
    }

    private function load(): void
    {
        if ($this->splits !== null) {
            return;
        }
        $this->splits = ArtistSplit::get(['name', 'kind', 'parts'])->mapWithKeys(fn ($s) => [$s->name => ['kind' => $s->kind, 'parts' => $s->parts]])->all();
        $this->confirmed = [];
        foreach (Artist::where('confirmed', true)->pluck('name') as $name) {
            $this->confirmed[$name] = true;
        }
        foreach (ArtistAlias::whereIn('artist_id', Artist::where('confirmed', true)->select('id'))->pluck('alias') as $alias) {
            $this->confirmed[$alias] = true;
        }
    }
}
