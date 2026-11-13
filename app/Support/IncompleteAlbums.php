<?php

namespace App\Support;

use App\Models\AlbumExemption;
use App\Models\AlbumTracklist;
use App\Models\FilePlan;
use App\Models\Track;
use App\Support\Lookup\Tracklists;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Album unvollständig (Inbox und Lib, Alben ab 2 Liedern, nicht Einzeltitel-Ordner): Lücken in den Nummern, weniger
 * Lieder als die Titelanzahl, fehlende CDs. Je Album:
 * - Titelliste aus dem Netz (nur auf Klick): sagt, welcher Titel fehlt – oder dass die Titelanzahl falsch ist
 * - fehlende Titel bei uns suchen (Inbox, Lib, Blackhole): gleiches Album mit der Nummer, sonst Titel und Interpret;
 *   die Länge unterscheidet Fassungen
 * - übernehmen = vormerken (ausgeführt beim Schreiben): kopieren immer, verschieben nur, wenn das Lied allein steht –
 *   ein anderes Album wird nie zerrissen. Aus dem Blackhole geht es nur über die Inbox.
 * - gewollt unvollständig: ausnehmen
 */
class IncompleteAlbums
{
    public const CHECK = 'incomplete';

    /** ab dieser Übereinstimmung gilt eine Titelliste als sicher */
    public const SURE = 0.7;

    /** Felder, die ein Lied im Ziel-Album übernimmt (Interpret, Titel, Genre bleiben) */
    private const ALBUM_FIELDS = ['album', 'albumartist', 'albumartists', 'year', 'releasedate', 'mb_albumid', 'compilation', 'category'];

    public function __construct(private Tracklists $tracklists) {}

    /**
     * Unvollständige Alben.
     *
     * @return Collection<string, array{discs: array<int, array{have: int, total: int, missing: list<int>}>, missing_discs: list<int>, tracks: int}>
     */
    public function findings(bool $exempted = false): Collection
    {
        $exempt = AlbumExemption::where('check', self::CHECK)->pluck('album_hash')->flip();
        $planned = FilePlan::get(['target_album_hash', 'values'])->groupBy('target_album_hash');
        $out = collect();
        $tracks = Track::whereIn('location', SplitAlbums::LOCATIONS)->get(['id', 'album_hash', 'album_dir', 'album', 'track', 'track_total', 'disc', 'disc_total'])->groupBy('album_hash');
        foreach ($tracks as $hash => $t) {
            if (isset($exempt[$hash]) !== $exempted || $t->count() < 2 || Albums::loose($t) || $t->contains(fn ($x) => ! $x->track)) {
                continue;
            }
            $finding = self::analyse($t, $planned[$hash] ?? collect());
            if ($finding && ($finding['missing_discs'] || array_filter(array_column($finding['discs'], 'missing')))) {
                $out[$hash] = $finding;
            }
        }

        return $out;
    }

    public function count(): int
    {
        return $this->findings()->count();
    }

    /** Lücken je CD (vorgemerkte Lieder zählen als vorhanden); null bei unplausiblen Nummern */
    private static function analyse(Collection $tracks, Collection $plans): ?array
    {
        $mode = fn (Collection $c, string $f) => (int) $c->pluck($f)->filter()->countBy()->sortDesc()->keys()->first();
        $planned = $plans->map(fn ($p) => [(int) ($p->values['disc'] ?? 1) ?: 1, (int) $p->values['track']]);
        $discs = [];
        foreach ($tracks->groupBy(fn ($t) => max(1, (int) $t->disc))->sortKeys() as $disc => $d) {
            $have = $d->pluck('track')->map(fn ($n) => (int) $n)->merge($planned->filter(fn ($p) => $p[0] === $disc)->pluck(1))->unique();
            $total = $mode($d, 'track_total');
            $max = max((int) $have->max(), $total);
            if ($max > 99) {
                return null; // unplausibel (z. B. „101“ für CD 1 Titel 1) – nicht raten
            }
            $discs[$disc] = ['have' => $have->count(), 'total' => $max, 'missing' => array_values(array_diff(range(1, $max), $have->all()))];
        }
        $discTotal = $mode($tracks, 'disc_total');
        $present = array_unique([...array_keys($discs), ...$planned->pluck(0)->all()]);

        return ['discs' => $discs, 'missing_discs' => $discTotal > 1 ? array_values(array_diff(range(1, $discTotal), $present)) : [], 'tracks' => $tracks->count()];
    }

    /**
     * Liste: unvollständige Alben mit Anzeige-Daten, nach Ordner.
     *
     * @return array{total: int, albums: list<array>}
     */
    public function albums(string $search = '', int $limit = 50, bool $exempted = false): array
    {
        $found = $this->findings($exempted);
        $tracks = Track::whereIn('location', SplitAlbums::LOCATIONS)->whereIn('album_hash', $found->keys())
            ->get(['id', 'location', 'album_hash', 'album_dir', 'album', 'albumartist', 'artist', 'has_cover'])->groupBy('album_hash');
        $like = mb_strtolower(trim($search));
        $albums = $found->map(fn ($f, $hash) => [...self::info($tracks[$hash]), ...$f, 'hash' => $hash])
            ->filter(fn ($a) => $like === '' || str_contains(mb_strtolower($a['album'].' '.$a['albumartist'].' '.$a['dir']), $like))
            ->sortBy(fn ($a) => mb_strtolower($a['dir']))->values();
        $lists = AlbumTracklist::whereIn('album_hash', $albums->take($limit)->pluck('hash'))->pluck('fetched_at', 'album_hash');

        return ['total' => $albums->count(), 'albums' => $albums->take($limit)->map(fn ($a) => $a + ['fetched' => $lists[$a['hash']] ?? null])->all()];
    }

    /** Anzeige-Daten eines Albums (häufigster Wert) */
    private static function info(Collection $t): array
    {
        $mode = fn (string $f) => $t->pluck($f)->filter()->countBy()->sortDesc()->keys()->first();
        $first = $t->first();

        return [
            'location' => $first->location,
            'dir' => ltrim(mb_substr($first->album_dir, mb_strlen(Locations::root($first->location))), '/'),
            'album' => $mode('album'),
            'albumartist' => $mode('albumartist') ?? ($t->pluck('artist')->filter()->unique()->count() > 1 ? NameRules::VARIOUS : $mode('artist')),
            'cover' => $t->firstWhere('has_cover', true)?->id ?? $first->id,
        ];
    }

    /**
     * Einzelheiten eines Albums: Lücken, Titelliste (gewählt bzw. beste sichere), fehlende Plätze mit Treffern bei uns,
     * Vorschlag zur Titelanzahl, Vormerkungen.
     */
    public function details(string $hash): ?array
    {
        $tracks = Track::whereIn('location', SplitAlbums::LOCATIONS)->where('album_hash', $hash)->orderBy('disc')->orderBy('track')->get();
        if ($tracks->isEmpty()) {
            return null;
        }
        $plans = FilePlan::with('track')->where('target_album_hash', $hash)->get();
        $finding = self::analyse($tracks, $plans) ?? ['discs' => [], 'missing_discs' => [], 'tracks' => $tracks->count()];
        $list = AlbumTracklist::where('album_hash', $hash)->first();
        $release = $list ? self::chosen($list) : null;
        $present = $tracks->map(fn ($t) => max(1, (int) $t->disc).'|'.(int) $t->track)
            ->merge($plans->map(fn ($p) => max(1, (int) ($p->values['disc'] ?? 1)).'|'.(int) $p->values['track']))->flip();

        // fehlende Plätze: laut Titelliste (mit Titel) bzw. laut Nummern
        $slots = [];
        if ($release) {
            foreach ($release['tracks'] as $rt) {
                if (! isset($present[$rt['disc'].'|'.$rt['n']])) {
                    $slots[] = $rt;
                }
            }
        } else {
            foreach ($finding['discs'] as $disc => $d) {
                foreach ($d['missing'] as $n) {
                    $slots[] = ['disc' => $disc, 'n' => $n, 'title' => null, 'artist' => null, 'duration' => null];
                }
            }
        }
        $album = self::info($tracks)['album'];
        foreach ($slots as &$slot) {
            $slot['candidates'] = $this->candidates($slot, $album, $hash);
        }
        unset($slot);

        return [
            ...self::info($tracks), ...$finding, 'hash' => $hash,
            'multi' => $tracks->pluck('disc')->filter()->unique()->count() > 1 || (int) $tracks->max('disc_total') > 1,
            'tracklist' => $list, 'release' => $release, 'slots' => $slots, 'plans' => $plans,
            'totals' => $release ? self::totalsFix($tracks, $release) : null,
            'trimTo' => $release ? null : self::trimTo($finding),
        ];
    }

    /** gewählte Titelliste bzw. die beste, wenn sie sicher passt */
    public static function chosen(AlbumTracklist $list): ?array
    {
        $candidates = $list->candidates ?? [];
        if ($list->chosen !== null) {
            return $candidates[$list->chosen] ?? null;
        }

        return isset($candidates[0]) && $candidates[0]['score'] >= self::SURE ? $candidates[0] : null;
    }

    /** Titelliste holen (MusicBrainz, Deezer, iTunes) und nach Übereinstimmung mit unseren Liedern ordnen */
    public function fetchTracklist(string $hash): AlbumTracklist
    {
        $tracks = Track::whereIn('location', SplitAlbums::LOCATIONS)->where('album_hash', $hash)->get();
        throw_if($tracks->isEmpty(), InvalidArgumentException::class, 'Album nicht gefunden.');
        $info = self::info($tracks);
        $result = $this->tracklists->search((string) $info['album'], (string) $info['albumartist']);
        $expected = $tracks->count() + count(array_merge(...array_column((self::analyse($tracks, collect()) ?? [])['discs'] ?? [], 'missing')));
        $candidates = collect($result['releases'])->map(fn ($r) => $r + self::match($tracks, $r))
            ->sortBy([['score', 'desc'], fn ($a, $b) => abs(count($a['tracks']) - $expected) <=> abs(count($b['tracks']) - $expected)])->values()->all();

        return AlbumTracklist::updateOrCreate(['album_hash' => $hash], ['candidates' => $candidates, 'chosen' => null,
            'errors' => $result['errors'] ? implode("\n", $result['errors']) : null, 'fetched_at' => now()]);
    }

    /** eine Titelliste wählen (null = wieder automatisch) */
    public function choose(string $hash, ?int $index): void
    {
        AlbumTracklist::where('album_hash', $hash)->update(['chosen' => $index]);
    }

    /** Übereinstimmung einer Titelliste mit unseren Liedern: gleicher Platz und passender Titel */
    private static function match(Collection $tracks, array $release): array
    {
        $byPlace = collect($release['tracks'])->keyBy(fn ($t) => $t['disc'].'|'.$t['n']);
        $matched = $tracks->filter(function ($t) use ($byPlace) {
            $rt = $byPlace[max(1, (int) $t->disc).'|'.(int) $t->track] ?? null;

            return $rt && self::sameTitle((string) $t->title, $rt['title']);
        })->count();

        return ['score' => round($matched / max(1, $tracks->count()), 2), 'matched' => $matched];
    }

    /**
     * Vorschlag: Titelanzahl (und CD-Anzahl) laut Titelliste, wenn sie bei uns anders steht.
     *
     * @return array<string, mixed>|null je CD: neue Titelanzahl, dazu CD-Anzahl
     */
    private static function totalsFix(Collection $tracks, array $release): ?array
    {
        $perDisc = collect($release['tracks'])->countBy('disc')->all();
        $discCount = count($perDisc);
        $changes = [];
        foreach ($tracks->groupBy(fn ($t) => max(1, (int) $t->disc)) as $disc => $d) {
            $ours = (int) $d->pluck('track_total')->filter()->countBy()->sortDesc()->keys()->first();
            if (isset($perDisc[$disc]) && $ours !== $perDisc[$disc]) {
                $changes['discs'][$disc] = ['from' => $ours ?: null, 'to' => $perDisc[$disc]];
            }
        }
        $oursDiscs = (int) $tracks->pluck('disc_total')->filter()->countBy()->sortDesc()->keys()->first();
        if ($discCount > 1 && $oursDiscs !== $discCount) {
            $changes['disc_total'] = ['from' => $oursDiscs ?: null, 'to' => $discCount];
        }

        return $changes ?: null;
    }

    /** ohne Titelliste: fehlen nur Nummern hinter der höchsten vorhandenen, kann die Titelanzahl falsch sein → so viele */
    private static function trimTo(array $finding): ?array
    {
        $out = [];
        foreach ($finding['discs'] as $disc => $d) {
            if ($d['missing'] && min($d['missing']) === $d['have'] + 1 && max($d['missing']) === $d['total']) {
                $out[$disc] = $d['have'];
            }
        }

        return $out ?: null;
    }

    /** Titelanzahl (und CD-Anzahl) korrigieren – laut Titelliste bzw. auf die vorhandenen Lieder. @return int Lieder */
    public function fixTotals(string $hash): int
    {
        $details = $this->details($hash);
        $fix = $details['totals'] ?? null;
        $discs = $fix ? array_map(fn ($c) => $c['to'], $fix['discs'] ?? []) : ($details['trimTo'] ?? []);
        $editor = app(TagEditor::class);
        $n = 0;
        foreach (Track::whereIn('location', SplitAlbums::LOCATIONS)->where('album_hash', $hash)->get() as $track) {
            $values = [];
            if (isset($discs[max(1, (int) $track->disc)])) {
                $values['track_total'] = $discs[max(1, (int) $track->disc)];
            }
            if (isset($fix['disc_total'])) {
                $values['disc_total'] = $fix['disc_total']['to'];
            }
            $n += $values && $editor->update($track, $values) ? 1 : 0;
        }

        return $n;
    }

    /**
     * Treffer bei uns für einen fehlenden Platz: gleiches Album mit dieser Nummer; mit Titel aus der Titelliste auch
     * gleicher Titel und Interpret. Länge ±3 s = gleiche Fassung.
     */
    public function candidates(array $slot, ?string $album, string $hash): array
    {
        $found = collect();
        $locations = [...SplitAlbums::LOCATIONS, 'blackhole'];
        if ($album) {
            $found = $found->merge(Track::whereIn('location', $locations)->where('album_hash', '!=', $hash)->where('album', $album)
                ->where('track', $slot['n'])->where(fn ($q) => $q->whereNull('disc')->orWhere('disc', $slot['disc'])->when($slot['disc'] === 1, fn ($w) => $w->orWhere('disc', 0)))
                ->limit(5)->get()->each(fn ($t) => $t->setAttribute('reason', 'gleiches Album, Nr. '.$slot['n'])));
        }
        if ($slot['title']) {
            $word = collect(preg_split('/[^\p{L}\p{N}]+/u', $slot['title'], -1, PREG_SPLIT_NO_EMPTY))->sortByDesc(fn ($w) => mb_strlen($w))->first();
            if ($word) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word).'%';
                $found = $found->merge(Track::whereIn('location', $locations)->where('album_hash', '!=', $hash)->where('title', 'like', $like)->limit(200)->get()
                    ->filter(fn ($t) => self::sameTitle((string) $t->title, $slot['title']) && (! $slot['artist'] || self::sameArtist((string) $t->artist, $slot['artist'])))
                    ->each(fn ($t) => $t->setAttribute('reason', 'Titel und Interpret passen')));
            }
        }

        return $found->unique('id')->map(function (Track $t) use ($slot) {
            $diff = $slot['duration'] && $t->duration ? (int) round($t->duration - $slot['duration']) : null;

            return ['track' => $t, 'reason' => $t->reason, 'diff' => $diff, 'exact' => $diff !== null && abs($diff) <= 3,
                'alone' => self::alone($t), 'blackhole' => Locations::ignored($t->location)];
        })->sortBy([fn ($a, $b) => ($b['exact'] <=> $a['exact']), fn ($a, $b) => ($b['track']->ext === 'flac') <=> ($a['track']->ext === 'flac')])->take(6)->values()->all();
    }

    /** steht das Lied allein (Ordner mit nur ihm bzw. Einzeltitel-Ordner)? Dann darf es verschoben werden. */
    public static function alone(Track $track): bool
    {
        $group = Track::where('album_hash', $track->album_hash)->get(['id', 'album', 'album_dir']);

        return $group->count() === 1 || Albums::loose($group);
    }

    /**
     * Lied für einen fehlenden Platz vormerken: kopieren (immer) bzw. verschieben (nur allein stehend, nicht aus dem
     * Blackhole). Das Lied bekommt im Ziel die Album-Felder des Albums, Nummer und CD.
     */
    public function plan(int $trackId, string $hash, int $disc, int $n, string $action): FilePlan
    {
        $source = Track::findOrFail($trackId);
        throw_if(Locations::ignored($source->location), InvalidArgumentException::class, 'Liegt im Blackhole – erst in die Inbox holen.');
        throw_unless(in_array($action, ['copy', 'move'], true), InvalidArgumentException::class, 'Unbekannte Aktion.');
        throw_if($action === 'move' && ! self::alone($source), InvalidArgumentException::class, 'Gehört zu einem anderen Album – nur kopieren.');
        $tracks = Track::whereIn('location', SplitAlbums::LOCATIONS)->where('album_hash', $hash)->get();
        throw_if($tracks->isEmpty(), InvalidArgumentException::class, 'Album nicht gefunden.');
        throw_if($tracks->contains(fn ($t) => $t->id === $source->id), InvalidArgumentException::class, 'Das Lied gehört schon zu diesem Album.');

        // ein Lied wandert nur in ein Album – ist es schon anderswohin zum Verschieben vorgemerkt, wird es hier kopiert
        if ($action === 'move' && FilePlan::where('track_id', $source->id)->where('action', 'move')->where('target_album_hash', '!=', $hash)->exists()) {
            $action = 'copy';
        }

        return FilePlan::updateOrCreate(['track_id' => $source->id, 'target_album_hash' => $hash],
            ['action' => $action, 'target_location' => $tracks->first()->location, 'values' => self::albumValues($tracks, $disc, $n)]);
    }

    /**
     * Werte für ein Lied, das in ein Album kommt: die Album-Felder des Albums (häufigster Wert), Nummer und CD.
     * Beim Schreiben neu berechnet (FilePlan::currentValues) – Änderungen am Album nach dem Vormerken gelten mit.
     */
    public static function albumValues(Collection $tracks, int $disc, int $n): array
    {
        $mode = fn (Collection $c, string $f) => $c->pluck($f)->filter(fn ($v) => $v !== null && $v !== '')->countBy()->sortDesc()->keys()->first();
        $values = [];
        foreach (self::ALBUM_FIELDS as $f) {
            $values[$f] = $f === 'compilation' ? $tracks->where('compilation', true)->count() * 2 > $tracks->count() : $mode($tracks, $f);
        }
        $multi = $tracks->pluck('disc')->filter()->unique()->count() > 1 || (int) $tracks->max('disc_total') > 1;
        $discTracks = $tracks->filter(fn ($t) => max(1, (int) $t->disc) === $disc);

        return $values + ['track' => $n, 'track_total' => $mode($discTracks->isNotEmpty() ? $discTracks : $tracks, 'track_total'),
            'disc' => $multi ? $disc : $mode($tracks, 'disc'), 'disc_total' => $mode($tracks, 'disc_total')];
    }

    public function unplan(int $id): void
    {
        FilePlan::whereKey($id)->delete();
    }

    public function exempt(string $hash, bool $exempt = true): void
    {
        $exempt ? AlbumExemption::firstOrCreate(['album_hash' => $hash, 'check' => self::CHECK])
            : AlbumExemption::where('album_hash', $hash)->where('check', self::CHECK)->delete();
    }

    /** „Summer Moved On (Radio Edit)“ = „Summer Moved On - Radio Edit“; ohne Zusatz gleich bzw. sehr ähnlich */
    public static function sameTitle(string $a, string $b): bool
    {
        [$na, $nb] = [self::norm($a), self::norm($b)];
        if ($na === '' || $nb === '') {
            return false;
        }
        if ($na === $nb || self::norm(self::base($a)) === self::norm(self::base($b))) {
            return true;
        }
        similar_text($na, $nb, $percent);

        return $percent >= 90;
    }

    /** Haupt-Interpret des einen steckt im anderen („Cher“ in „Cher feat. Eros Ramazzotti“) */
    public static function sameArtist(string $a, string $b): bool
    {
        $main = fn (string $s) => self::norm(preg_split('/\s+(?:feat\.?|ft\.?|featuring|vs\.?|x|with)\s+|\s*[&,;\/]\s*|\s+(?:and|und)\s+/iu', trim($s))[0] ?? $s);
        [$ma, $mb, $na, $nb] = [$main($a), $main($b), self::norm($a), self::norm($b)];

        return $ma !== '' && $mb !== '' && (str_contains($nb, $ma) || str_contains($na, $mb));
    }

    private static function norm(string $s): string
    {
        return preg_replace('/[^a-z0-9]+/', '', str_replace('&', 'and', Str::ascii(mb_strtolower($s))));
    }

    /** ohne Zusatz in Klammern bzw. nach „ - “ */
    private static function base(string $s): string
    {
        return trim(preg_replace(['/\s*[\(\[][^\)\]]*[\)\]]/u', '/\s+-\s+.*$/u'], '', $s));
    }
}
