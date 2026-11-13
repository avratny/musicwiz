<?php

namespace App\Support;

use App\Models\Track;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Alben, die auseinanderfallen würden: Lieder eines Album-Ordners (Inbox und Lib) mit verschiedenen Werten in Feldern,
 * nach denen Navidrome Alben trennt – Album, Album-Interpret (fehlt er, gilt der Interpret bzw. bei Samplern „Various
 * Artists“), Album-Interpreten einzeln, MusicBrainz-Album-ID, Veröffentlichungsdatum und Jahr (nicht bei Samplern:
 * dort sind verschiedene Jahre gewollt). Ordner ohne jeden Album-Namen und der Ordner „Kein Album“ zählen nicht – ein
 * Ordner mit verschiedenen Album-Namen dagegen schon (das ist genau der Fall, der hier auffallen soll).
 * Angeglichen wird je Feld auf einen gewählten Wert – nur in der Datenbank, wie jede Tag-Änderung.
 */
class SplitAlbums
{
    public const LOCATIONS = ['inbox', 'library'];

    /** geprüfte Felder – Beschriftung */
    public const FIELDS = [
        'album' => 'Album',
        'albumartist' => 'Album-Interpret',
        'albumartists' => 'Album-Interpreten einzeln',
        'mb_albumid' => 'MusicBrainz-Album-ID',
        'releasedate' => 'Veröffentlicht',
        'year' => 'Jahr',
    ];

    /** Felder, die leer sein dürfen (Angleichen auf „leer“ möglich) */
    public const MAY_BE_EMPTY = ['mb_albumid', 'releasedate', 'year'];

    /** Grund in tag_changes */
    public const REASON = 'Album angeglichen';

    private const COLUMNS = ['id', 'location', 'dir', 'album_dir', 'album_hash', 'filename', 'title', 'artist', 'album', 'albumartist',
        'albumartists', 'compilation', 'mb_albumid', 'releasedate', 'year'];

    /** MusicBrainz-Album-ID und Veröffentlichungsdatum aus den rohen Tags der Datei (ID3, MP4, ASF, Vorbis) */
    public static function fromRawTags(array $raw): array
    {
        $upper = array_change_key_case($raw, CASE_UPPER);
        $find = function (array $keys, int $max) use ($upper) {
            foreach ($keys as $key) {
                foreach ((array) ($upper[$key] ?? []) as $v) {
                    if (($v = trim((string) $v)) !== '') {
                        return mb_substr($v, 0, $max);
                    }
                }
            }

            return null;
        };

        return [
            'releasedate' => $find(['TDRL', 'TXXX:RELEASEDATE', '----:COM.APPLE.ITUNES:RELEASEDATE', 'RELEASEDATE'], 32),
            'mb_albumid' => $find(['TXXX:MUSICBRAINZ ALBUM ID', '----:COM.APPLE.ITUNES:MUSICBRAINZ ALBUM ID', 'MUSICBRAINZ/ALBUM ID', 'MUSICBRAINZ_ALBUMID'], 64),
        ];
    }

    public function count(): int
    {
        return $this->hashes()->count();
    }

    /**
     * Betroffene Alben (Suche über Album, Interpret, Album-Interpret und Ordner), nach Ordner sortiert.
     *
     * @return array{total: int, albums: list<array>}
     */
    public function albums(string $search = '', int $limit = 100): array
    {
        $hashes = $this->hashes($search);
        $page = $hashes->take($limit)->all();
        $tracks = Track::whereIn('album_hash', $page)->whereIn('location', self::LOCATIONS)
            ->orderBy('dir')->orderBy('disc')->orderBy('track')->orderBy('filename')->get(self::COLUMNS)->groupBy('album_hash');
        $albums = [];
        foreach ($page as $hash) {
            if (isset($tracks[$hash]) && ($album = $this->describe($tracks[$hash]))['issues']) {
                $albums[] = $album;
            }
        }

        return ['total' => $hashes->count(), 'albums' => $albums];
    }

    /**
     * Ein Feld fürs ganze Album auf einen Wert setzen. „Various Artists“ als Album-Interpret setzt auch das Sampler-Flag.
     *
     * @return int Zahl der geänderten Lieder
     */
    public function align(string $hash, string $field, string $value): int
    {
        abort_unless(isset(self::FIELDS[$field]), 422);
        $value = trim($value);
        abort_if($value === '' && ! in_array($field, self::MAY_BE_EMPTY, true), 422);
        $values = [$field => $value];
        if ($field === 'albumartist' && $value === NameRules::VARIOUS) {
            $values['compilation'] = true;
        }
        $editor = app(TagEditor::class);
        $changed = 0;
        foreach (Track::where('album_hash', $hash)->whereIn('location', self::LOCATIONS)->get() as $track) {
            if ($editor->update($track, $values, TagEditor::MANUAL, self::REASON)) {
                $changed++;
            }
        }

        return $changed;
    }

    /** Album-Interpret, wie Navidrome ihn sieht (SQL): fehlt er, der Interpret bzw. bei Samplern „Various Artists“ */
    public const ALBUM_ARTIST_SQL = "COALESCE(albumartist, IF(compilation, '".NameRules::VARIOUS."', IFNULL(artist, '')))";

    /** Lieder aus Inbox und Lib, auf Alben eingeschränkt, bei denen Album, Interpret, Album-Interpret oder Ordner passt */
    public static function tracks(string $search = ''): Builder
    {
        $like = '%'.mb_strtolower(str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($search))).'%';

        return DB::table('tracks')->whereIn('location', self::LOCATIONS)
            ->when(trim($search) !== '', fn ($q) => $q->whereIn('album_hash', DB::table('tracks')->whereIn('location', self::LOCATIONS)
                ->where(fn ($w) => $w->whereRaw('LOWER(album) LIKE ?', [$like])->orWhereRaw('LOWER(albumartist) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(artist) LIKE ?', [$like])->orWhereRaw('LOWER(album_dir) LIKE ?', [$like]))->select('album_hash')));
    }

    /** Zahl aller Alben in Inbox und Lib (Menü, Alben › Alle) */
    public static function total(): int
    {
        return self::tracks()->distinct()->count('album_hash');
    }

    /** Album-Hashes der betroffenen Alben – Vorauswahl per SQL (genau verglichen, Groß/klein zählt) */
    public function hashes(string $search = ''): Collection
    {
        $aa = self::ALBUM_ARTIST_SQL;
        $distinct = fn (string $expr) => 'COUNT(DISTINCT BINARY '.$expr.') > 1';

        return self::tracks($search)
            ->groupBy('album_hash')
            ->havingRaw('MAX(album IS NOT NULL) = 1')
            ->havingRaw("LOWER(SUBSTRING_INDEX(MIN(album_dir), '/', -1)) <> ?", [Albums::noAlbum()])
            ->havingRaw('('.implode(' OR ', [
                $distinct("IFNULL(album, '')"),
                $distinct($aa),
                $distinct("IFNULL(albumartists, $aa)"),
                $distinct("IFNULL(mb_albumid, '')"),
                $distinct("IFNULL(releasedate, '')"),
                '(MIN(compilation) = 0 AND '.$distinct("IFNULL(year, '')").')',
            ]).')')
            ->orderByRaw('MIN(album_dir)')
            ->pluck('album_hash');
    }

    /**
     * Ein Album: Kopf (häufigste Werte, Ordner) und je uneinheitlichem Feld die Werte mit Anzahl, der Grund (warum das
     * Album auseinanderfällt) und ein Vorschlag mit Begründung – oder keiner, wenn der Nutzer entscheiden muss.
     */
    private function describe(Collection $tracks): array
    {
        $first = $tracks->first();
        $sampler = $tracks->every(fn ($t) => $t->compilation);
        // Album-Interpret, wie Navidrome ihn sieht – „aus Interpret“ merken, damit sichtbar ist, woher der Wert kommt
        $aa = fn ($t) => $t->albumartist ?? ($t->compilation ? NameRules::VARIOUS : (string) $t->artist);
        $values = [
            'album' => $tracks->map(fn ($t) => (string) $t->album),
            'albumartist' => $tracks->map($aa),
            'albumartists' => $tracks->map(fn ($t) => $t->albumartists ?? $aa($t)),
            'mb_albumid' => $tracks->map(fn ($t) => (string) $t->mb_albumid),
            'releasedate' => $tracks->map(fn ($t) => (string) $t->releasedate),
            'year' => $tracks->map(fn ($t) => (string) $t->year),
        ];
        $derived = $tracks->filter(fn ($t) => $t->albumartist === null)->map($aa)->unique()->all();
        $issues = [];
        foreach ($values as $field => $list) {
            $counts = $list->countBy()->sortDesc();
            if ($counts->count() <= 1 || ($field === 'year' && $sampler)) {
                continue;
            }
            // folgt meist dem Album-Interpreten – erst den angleichen
            if ($field === 'albumartists' && isset($issues['albumartist'])) {
                continue;
            }
            $options = $counts->map(fn ($n, $v) => ['value' => (string) $v, 'count' => $n,
                'derived' => $field === 'albumartist' && in_array((string) $v, $derived, true)])->values()->all();
            // fehlt der Album-Interpret und die Interpreten sind verschieden: vielleicht ein Sampler
            if ($field === 'albumartist' && ! $counts->has(NameRules::VARIOUS)) {
                $options[] = ['value' => NameRules::VARIOUS, 'count' => 0, 'derived' => false];
            }
            $issues[$field] = ['options' => $options, ...$this->judge($field, $list, $tracks)];
        }

        return [
            'hash' => $first->album_hash,
            'location' => $first->location,
            'dir' => ltrim(mb_substr($first->album_dir, mb_strlen(Locations::root($first->location))), '/'),
            'album' => (string) $values['album']->filter()->countBy()->sortDesc()->keys()->first(),
            'artist' => (string) $values['albumartist']->filter()->countBy()->sortDesc()->keys()->first(),
            'tracks' => $tracks->count(),
            'cover' => $first->id,
            'issues' => $issues,
        ];
    }

    /**
     * Grund und Vorschlag für ein uneinheitliches Feld.
     *
     * @param  Collection<int, string>  $list  Wert je Lied (in Reihenfolge: Ordner, CD, Nummer)
     * @return array{reason: string, suggest: ?string, why: string}
     */
    private function judge(string $field, Collection $list, Collection $tracks): array
    {
        $n = $list->count();
        $counts = $list->countBy()->sortDesc();
        $filled = $counts->filter(fn ($c, $v) => (string) $v !== '');
        $empty = (int) ($counts[''] ?? 0);
        $label = self::FIELDS[$field];
        $lieder = fn (int $c) => $c.' '.($c === 1 ? 'Lied' : 'Liedern');
        // häufigster Wert; gleich häufig → der des ersten Lieds (CD 1, Nr. 1)
        $top = $filled->max();
        $tied = $filled->filter(fn ($c) => $c === $top)->keys()->map(fn ($v) => (string) $v);
        $most = $tied->count() === 1 ? $tied->first() : $list->first(fn ($v) => $tied->contains($v));
        $mostWhy = $tied->count() === 1
            ? 'häufigster Wert ('.$top.' von '.$n.')'
            : 'gleich häufig – genommen wie beim ersten Lied (CD 1)';
        $caseOnly = $filled->count() > 1 && $filled->keys()->map(fn ($v) => mb_strtolower((string) $v))->unique()->count() === 1;

        // nur ein Wert, bei den übrigen fehlt er: ergänzen
        if ($filled->count() === 1) {
            $value = (string) $filled->keys()->first();

            return ['reason' => $label.' fehlt bei '.$lieder($empty).' von '.$n.' – Navidrome macht daraus ein eigenes Album.',
                'suggest' => $value, 'why' => 'einziger vorhandener Wert, bei den übrigen ergänzen'];
        }
        if ($field === 'albumartist') {
            $missing = $tracks->filter(fn ($t) => $t->albumartist === null);
            if ($missing->isNotEmpty() && $filled->count() > 1) {
                $artists = $tracks->pluck('artist')->map(fn ($v) => (string) $v)->unique()->count();
                $reason = $missing->count() === $n
                    ? 'Kein Album-Interpret gesetzt – Navidrome nimmt dann den Interpreten jedes Lieds und verteilt das Album auf '.$filled->count().' Interpreten.'
                    : 'Album-Interpret fehlt bei '.$lieder($missing->count()).' von '.$n.' – dort nimmt Navidrome den Interpreten des Lieds.';
                // viele verschiedene Interpreten: Sampler
                if ($artists >= max(3, intdiv($n, 2)) || $tracks->contains(fn ($t) => $t->compilation)) {
                    return ['reason' => $reason, 'suggest' => NameRules::VARIOUS,
                        'why' => $artists.' verschiedene Interpreten bei '.$n.' Liedern – sieht nach Sampler aus (setzt auch das Sampler-Flag)'];
                }

                return ['reason' => $reason, 'suggest' => $most, 'why' => 'Hauptinterpret: '.$mostWhy];
            }
        }
        if (in_array($field, ['mb_albumid', 'releasedate'], true)) {
            return ['reason' => 'Verschiedene Werte für '.$label.' – vielleicht sind Lieder aus zwei Ausgaben gemischt.',
                'suggest' => null, 'why' => 'kein Vorschlag: welche Ausgabe stimmt, kann ich nicht erkennen – bitte selbst entscheiden'];
        }
        $reason = match (true) {
            $caseOnly => $label.' unterschiedlich geschrieben (nur Groß-/Kleinschreibung).',
            $empty > 0 => 'Verschiedene Werte für '.$label.', bei '.$lieder($empty).' fehlt er.',
            $field === 'year' => 'Verschiedene Jahre in einem Album, das kein Sampler ist.',
            default => 'Verschiedene Werte für '.$label.' im selben Album.',
        };

        return ['reason' => $reason, 'suggest' => $most, 'why' => $mostWhy];
    }
}
