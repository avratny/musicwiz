<?php

namespace App\Support;

use App\Models\CoverImage;
use App\Models\FolderImage;
use App\Models\TagChange;
use App\Models\Track;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cover der Alben (Inbox und Lib) prüfen – je Album mit Grund und Vorschlag:
 * - „unterschiedlich“: eingebettete Cover zeigen verschiedene Motive, fehlen bei einzelnen Liedern oder das Bild im
 *   Ordner (cover/folder/front) zeigt ein anderes Motiv als die eingebetteten
 * - „zu klein“: das Cover, das Navidrome zeigt (Bild im Ordner, sonst das eingebettete), ist kleiner als eingestellt
 * Gleiches Motiv = Fingerabdrücke (dHash) fast gleich – auch in anderer Größe oder Kompression. Ausgeführt wird noch
 * nichts: Cover stecken in den Dateien, und in Dateien wird (noch) nicht geschrieben.
 */
class AlbumCovers
{
    public const CHECKS = ['different' => 'Unterschiedliche Cover', 'small' => 'Cover zu klein'];

    /** höchstens so viele abweichende Bits (von 64) gelten als dasselbe Motiv */
    private const SAME_MOTIF = 10;

    /** Navidrome nimmt die Bilder im Ordner in dieser Reihenfolge */
    private const FOLDER_PRIORITY = ['cover', 'folder', 'front'];

    public function __construct(private Settings $settings) {}

    public function minSize(): int
    {
        return $this->settings->other('cover_min_px');
    }

    /** @return array<string, int> Prüfung => Zahl der Alben */
    public function counts(): array
    {
        $all = $this->all();

        return array_map(fn ($check) => count($all[$check]), array_combine(array_keys(self::CHECKS), array_keys(self::CHECKS)));
    }

    /** Lieder in Inbox und Lib, deren Cover-Daten noch fehlen (vor dem nächsten Einlesen) */
    public function unread(): int
    {
        return Track::whereIn('location', SplitAlbums::LOCATIONS)->where('read_version', '<', Scanner::READ_VERSION)->count();
    }

    /**
     * Betroffene Alben einer Prüfung (Suche wie bei den übrigen Alben-Ansichten), nach Ordner sortiert.
     *
     * @return array{total: int, albums: list<array>}
     */
    public function albums(string $check, string $search = '', int $limit = 50): array
    {
        $albums = collect($this->all()[$check] ?? []);
        if (trim($search) !== '') {
            $match = SplitAlbums::tracks($search)->distinct()->pluck('album_hash')->flip();
            $albums = $albums->filter(fn ($a) => isset($match[$a['hash']]));
        }

        return ['total' => $albums->count(), 'albums' => $albums->take($limit)->values()->all()];
    }

    /** alle Befunde – zwischengespeichert, bis sich Lieder, Bilder im Ordner oder die Mindestgröße ändern */
    private function all(): array
    {
        $state = implode('|', [Track::count(), Track::max('updated_at'), TagChange::max('id'), TagChange::count(), FolderImage::count(), FolderImage::max('updated_at'),
            CoverImage::count(), $this->minSize()]);

        return Cache::remember('album-covers:'.md5($state), now()->addDay(), function () {
            $images = FolderImage::whereIn('location', SplitAlbums::LOCATIONS)->get(['id', 'dir', 'path', 'width', 'height', 'phash'])
                ->groupBy('dir')->map(fn ($list) => $list->sortBy(fn ($i) => array_search(strtolower(pathinfo($i->path, PATHINFO_FILENAME)), self::FOLDER_PRIORITY, true))->first());
            $covers = CoverImage::get(['hash', 'width', 'height', 'phash'])->keyBy('hash');
            $out = array_fill_keys(array_keys(self::CHECKS), []);
            $group = collect();
            $flush = function () use (&$group, &$out, $images, $covers) {
                if ($group->isNotEmpty()) {
                    foreach ($this->check($group, $images, $covers) as $check => $album) {
                        $out[$check][] = $album;
                    }
                }
                $group = collect();
            };
            $tracks = DB::table('tracks')->whereIn('location', SplitAlbums::LOCATIONS)
                ->orderBy('album_dir')->orderBy('album_hash')->orderBy('dir')->orderBy('disc')->orderBy('track')->orderBy('filename')
                ->select(['id', 'location', 'dir', 'album_dir', 'album_hash', 'album', 'albumartist', 'artist', 'compilation', 'has_cover',
                    'cover_width', 'cover_height', 'cover_phash', 'cover', 'read_version'])->cursor();
            foreach ($tracks as $t) {
                if ($group->isNotEmpty() && $group->first()->album_hash !== $t->album_hash) {
                    $flush();
                }
                $group->push($t);
            }
            $flush();

            return $out;
        });
    }

    /** ein Album prüfen: Prüfung => Befund (nur die zutreffenden) */
    private function check(Collection $tracks, Collection $images, Collection $covers): array
    {
        // noch ohne Cover-Daten gelesen: erst nach dem nächsten Einlesen prüfen
        if ($tracks->contains(fn ($t) => $t->read_version < Scanner::READ_VERSION)) {
            return [];
        }
        // neu gesetztes Cover: zählt statt des eingebetteten (kommt beim Schreiben in die Datei)
        $tracks = $tracks->map(function ($t) use ($covers) {
            if ($t->cover && ($c = $covers[$t->cover] ?? null)) {
                $t = (object) ([...(array) $t, 'has_cover' => 1, 'cover_width' => $c->width, 'cover_height' => $c->height, 'cover_phash' => $c->phash, 'new' => $c->hash]);
            }

            return $t;
        });
        $n = $tracks->count();
        // Bild im Ordner zählt nicht, wenn alle Lieder des Ordners ein neues Cover haben (beim Schreiben wird es ersetzt)
        $folder = $tracks->pluck('dir')->unique()->map(fn ($d) => $images[$d] ?? null)->filter()
            ->reject(fn ($i) => $tracks->where('dir', $i->dir)->every(fn ($t) => ! empty($t->new)))->values();
        // Fassungen: eingebettet (je Größe und Motiv) und Bilder im Ordner, nach Motiven gruppiert
        $motifs = [];
        $assign = function (string $phash) use (&$motifs): int {
            foreach ($motifs as $i => $m) {
                if (self::distance($m, $phash) <= self::SAME_MOTIF) {
                    return $i;
                }
            }
            $motifs[] = $phash;

            return count($motifs) - 1;
        };
        $variants = [];
        foreach ($tracks->filter(fn ($t) => $t->cover_phash) as $t) {
            $key = (empty($t->new) ? 'e' : 'n').$assign($t->cover_phash).'-'.$t->cover_width.'x'.$t->cover_height;
            $variants[$key] ??= ['kind' => empty($t->new) ? 'embedded' : 'new', 'motif' => $assign($t->cover_phash), 'width' => $t->cover_width,
                'height' => $t->cover_height, 'count' => 0, 'track' => $t->id, 'hash' => $t->new ?? null, 'dirs' => []];
            $variants[$key]['count']++;
            $variants[$key]['dirs'][$t->dir] = true;
        }
        foreach ($folder->filter(fn ($i) => $i->phash) as $i) {
            $variants['f'.$i->id] = ['kind' => 'folder', 'motif' => $assign($i->phash), 'width' => $i->width, 'height' => $i->height,
                'count' => $tracks->where('dir', $i->dir)->count(), 'image' => $i->id, 'version' => $i->phash, 'name' => basename($i->path), 'dirs' => [$i->dir => true]];
        }
        if (! $variants) {
            return [];
        }
        $variants = collect($variants)->sortBy([['motif', 'asc'], ['kind', 'desc'], [fn ($a, $b) => $b['count'] <=> $a['count']]])->values();
        $embeddedMotifs = $variants->whereIn('kind', ['embedded', 'new'])->pluck('motif')->unique();
        $lieder = fn (int $c) => $c.' '.($c === 1 ? 'Lied' : 'Liedern');
        $px = fn ($v) => $v['width'].'×'.$v['height'].' px';
        $label = fn ($v) => match ($v['kind']) {
            'folder' => 'Bild im Ordner („'.$v['name'].'“, '.$px($v).')',
            'new' => 'neu gesetztes Cover ('.$px($v).')',
            default => 'eingebettetes Cover ('.$px($v).')',
        };
        $head = [
            'hash' => $tracks->first()->album_hash,
            'location' => $tracks->first()->location,
            'dir' => ltrim(mb_substr($tracks->first()->album_dir, mb_strlen(Locations::root($tracks->first()->location))), '/'),
            'album' => (string) $tracks->pluck('album')->filter()->countBy()->sortDesc()->keys()->first(),
            'artist' => (string) $tracks->map(fn ($t) => $t->albumartist ?? ($t->compilation ? NameRules::VARIOUS : $t->artist))->filter()->countBy()->sortDesc()->keys()->first(),
            'tracks' => $n,
            'cover' => $tracks->first()->id,
        ];
        $out = [];

        // unterschiedlich
        $reasons = [];
        $without = $tracks->filter(fn ($t) => ! $t->has_cover)->count();
        if ($without > 0 && $without < $n) {
            $reasons[] = 'Kein eingebettetes Cover bei '.$lieder($without).' von '.$n.'.';
        }
        $unreadable = $tracks->filter(fn ($t) => $t->has_cover && ! $t->cover_phash)->count();
        if ($unreadable) {
            $reasons[] = 'Eingebettetes Cover bei '.$lieder($unreadable).' nicht lesbar.';
        }
        if ($embeddedMotifs->count() > 1) {
            $reasons[] = 'Die eingebetteten Cover zeigen '.$embeddedMotifs->count().' verschiedene Motive.';
        }
        foreach ($variants->where('kind', 'folder') as $f) {
            $own = $variants->whereIn('kind', ['embedded', 'new'])->filter(fn ($v) => array_intersect_key($v['dirs'], $f['dirs']))->pluck('motif')->unique();
            if ($own->isNotEmpty() && ! $own->contains($f['motif'])) {
                $reasons[] = 'Das Bild im Ordner („'.$f['name'].'“) zeigt ein anderes Motiv als die eingebetteten Cover – Navidrome zeigt das Bild im Ordner.';
            }
        }
        if ($variants->where('kind', 'folder')->pluck('motif')->unique()->count() > 1) {
            $reasons[] = 'Die Bilder in den Ordnern der CDs zeigen verschiedene Motive.';
        }
        if ($reasons) {
            // Motiv mit den meisten Liedern (gleich viele: das des ersten Lieds), davon die größte Fassung
            $perMotif = $variants->groupBy('motif')->map(fn ($v) => $v->whereIn('kind', ['embedded', 'new'])->sum('count') ?: $v->sum('count'));
            $top = $perMotif->max();
            $first = $variants->first(fn ($v) => $v['kind'] !== 'folder' && isset($v['dirs'][$tracks->first()->dir]))['motif'] ?? $perMotif->keys()->first();
            $motif = $perMotif->filter(fn ($c) => $c === $top)->has($first) ? $first : $perMotif->search($top);
            $best = $variants->where('motif', $motif)->sortByDesc(fn ($v) => min($v['width'], $v['height']))->first();
            $why = $perMotif->count() > 1
                ? 'Motiv mit den meisten Liedern ('.$perMotif[$motif].' von '.$n.'), davon die größte Fassung'
                : 'größte Fassung des Motivs';
            $out['different'] = [...$head, 'reasons' => $reasons, 'variants' => $variants->all(),
                'suggest' => $label($best).' in alle Lieder einbetten und als Bild im Ordner verwenden', 'why' => $why, 'best' => $best];
        }

        // zu klein: das Cover, das Navidrome zeigt – Bild im Ordner, sonst das eingebettete
        $min = $this->minSize();
        $shown = $variants->filter(fn ($v) => $v['kind'] === 'folder'
            || ! $variants->where('kind', 'folder')->contains(fn ($f) => array_intersect_key($f['dirs'], $v['dirs'])));
        $small = $shown->filter(fn ($v) => min($v['width'], $v['height']) < $min);
        if ($small->isNotEmpty()) {
            $reasons = $small->map(fn ($v) => ucfirst($label($v)).' ist kleiner als '.$min.' px'
                .($v['kind'] !== 'folder' ? ' – bei '.$lieder($v['count']).'.' : '.'))->values()->all();
            // gleiches Motiv in groß im Album vorhanden?
            $bigger = $variants->filter(fn ($v) => $small->pluck('motif')->contains($v['motif']) && min($v['width'], $v['height']) >= $min)
                ->sortByDesc(fn ($v) => min($v['width'], $v['height']))->first();
            $out['small'] = [...$head, 'reasons' => $reasons, 'variants' => $variants->all(),
                'suggest' => $bigger ? $label($bigger).' nehmen' : null,
                'why' => $bigger ? 'dasselbe Motiv liegt im Album schon groß genug vor' : 'kein Vorschlag: im Album gibt es keine Fassung ab '.$min.' px – größeres Cover suchen',
                'best' => $bigger];
        }

        return $out;
    }

    /** abweichende Bits zweier Fingerabdrücke (64 Bit hex, in zwei Hälften gerechnet) */
    public static function distance(string $a, string $b): int
    {
        $bits = 0;
        foreach ([0, 8] as $offset) {
            $bits += substr_count(decbin(hexdec(substr($a, $offset, 8)) ^ hexdec(substr($b, $offset, 8))), '1');
        }

        return $bits;
    }
}
