<?php

namespace App\Support;

use App\Models\CoverImage;
use App\Models\TagChange;
use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Offene Tag-Änderungen in die Dateien schreiben (MP3 und FLAC, tools/tagwriter.py): je Lied die geänderten Felder mit
 * dem jetzigen Wert aus der Datenbank, sicher über eine Kopie. Danach wird die Datei nachgelesen; nur Felder, die jetzt
 * genau so in der Datei stehen, gelten als geschrieben (written_at) – der Rest bleibt offen und wird gemeldet.
 * Änderungen, die während des Schreibens dazukommen, bleiben offen.
 */
class TagWriter
{
    public const FORMATS = ['mp3', 'flac'];

    /** Nummer und Gesamtzahl gehören zusammen (MP3: ein Frame „3/10“) – immer beide schreiben */
    private const PAIRS = ['track' => 'track_total', 'track_total' => 'track', 'disc' => 'disc_total', 'disc_total' => 'disc'];

    /** Lieder mit offenen Änderungen (Inbox und Lib) */
    public static function pending(): Builder
    {
        return Track::whereIn('location', SplitAlbums::LOCATIONS)->whereIn('id', TagChange::select('track_id'));
    }

    /** davon schreibbar: MP3/FLAC und lesbar */
    public static function writable(?Builder $query = null): Builder
    {
        return ($query ?? self::pending())->whereIn('ext', self::FORMATS)->whereNull('error');
    }

    /**
     * Lieder schreiben.
     *
     * @param  list<int>  $ids
     * @return array{written: int, failed: array<string, string>} failed: Pfad => Grund
     */
    public function write(array $ids): array
    {
        $tracks = self::writable()->whereIn('id', $ids)->get()->keyBy('id');
        $changes = TagChange::whereIn('track_id', $tracks->keys())->get(['id', 'track_id', 'field'])->groupBy('track_id');
        $files = [];
        $expected = [];
        foreach ($tracks as $id => $track) {
            $fields = $changes[$id]->pluck('field')->unique()->values()->all();
            foreach ($fields as $f) {
                if (isset(self::PAIRS[$f])) {
                    $fields[] = self::PAIRS[$f];
                }
            }
            $tags = [];
            $cover = null;
            foreach (array_unique($fields) as $f) {
                if ($f === 'cover') {
                    $cover = $track->cover && ($image = CoverImage::find($track->cover)) ? CoverStore::path($image) : null;

                    continue;
                }
                $tags[$f] = $track->getAttribute($f);
            }
            $files[] = ['path' => $track->path, 'tags' => $tags, 'cover' => $cover];
            $expected[$track->path] = ['track' => $track, 'tags' => $tags, 'cover' => in_array('cover', $fields, true)];
        }
        if (! $files) {
            return ['written' => 0, 'failed' => []];
        }

        $process = new Process(['python3', base_path('tools/tagwriter.py'), 'write']);
        $process->setTimeout(null);
        $process->setInput(json_encode(['files' => $files], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $process->run();
        $failed = [];
        $read = [];
        foreach (preg_split('/\R/', trim($process->getOutput())) as $line) {
            $r = json_decode($line, true);
            if (! is_array($r) || ! isset($expected[$r['path'] ?? ''])) {
                continue;
            }
            if (empty($r['ok']) || empty($r['read']['ok'])) {
                $failed[$r['path']] = (string) ($r['error'] ?? $r['read']['error'] ?? 'nicht lesbar');

                continue;
            }
            $read[$r['path']] = $r['read'];
        }
        foreach (array_diff_key($expected, $read, $failed) as $path => $_) {
            $failed[$path] = 'keine Antwort vom Tag-Schreiber'.($process->getErrorOutput() ? ': '.mb_substr(trim($process->getErrorOutput()), -300) : '');
        }

        // nachgelesen: nur was jetzt so in der Datei steht, gilt als geschrieben
        $scanner = app(Scanner::class);
        $written = 0;
        $byLocation = [];
        foreach ($read as $path => $r) {
            ['track' => $track, 'tags' => $tags, 'cover' => $cover] = $expected[$path];
            $row = $scanner->row($track->location, $r, now());
            $done = array_keys(array_filter($tags, fn ($v, $f) => self::same($f, $v, $row[$f] ?? null), ARRAY_FILTER_USE_BOTH));
            if ($cover && $row['has_cover']) {
                $done[] = 'cover';
            }
            $missed = array_diff([...array_keys($tags), ...($cover ? ['cover'] : [])], $done);
            if ($missed) {
                $failed[$path] = 'steht nach dem Schreiben anders in der Datei: '.implode(', ', array_map(fn ($f) => Track::EDITABLE[$f] ?? $f, $missed));
            }
            TagChange::whereIn('id', $changes[$track->id]->whereIn('field', $done)->pluck('id'))->update(['written_at' => now()]);
            $written += $missed ? 0 : 1;
            $byLocation[$track->location][] = $r;
        }
        DB::transaction(function () use ($scanner, $byLocation) {
            foreach ($byLocation as $location => $results) {
                $scanner->refresh($location, $results);
            }
        });

        return ['written' => $written, 'failed' => $failed];
    }

    /**
     * Was beim Schreiben in die Datei kommt, je Feld: bisher (Datei) → neu (Datenbank), mit markiertem Unterschied.
     *
     * @param  Collection<int, TagChange>  $changes  je Feld die älteste offene Änderung
     */
    private static function trackChanges(Track $track, Collection $changes): array
    {
        $text = fn ($v) => $v === null || $v === false ? '' : (is_bool($v) ? 'ja' : (string) $v);
        $fields = [];
        foreach ($changes as $c) {
            if ($c->field === 'cover') {
                $fields[] = ['label' => 'Cover', 'old' => null, 'new' => $track->cover ? 'neues Bild' : 'entfernen', 'diff' => null];

                continue;
            }
            if ($c->field === 'fingerprint') {
                $fields[] = ['label' => Track::EDITABLE['fingerprint'], 'old' => null, 'new' => $track->fingerprint ? 'ermittelt' : 'entfernen', 'diff' => null];

                continue;
            }
            if ($c->field === 'lyrics') {
                $fields[] = ['label' => Track::EDITABLE['lyrics'], 'old' => Lyrics::summary($c->old_value), 'new' => Lyrics::summary($track->lyrics) ?: 'entfernen', 'diff' => null];

                continue;
            }
            [$old, $new] = [$text($c->old_value), $text($track->getAttribute($c->field))];
            $fields[] = ['label' => Track::EDITABLE[$c->field] ?? $c->field, 'old' => $old, 'new' => $new,
                'diff' => $old !== '' && $new !== '' ? TextDiff::bounds($old, $new) : null];
        }

        return ['title' => $track->title ?: $track->filename, 'track' => (int) $track->track, 'disc' => (int) $track->disc, 'fields' => $fields];
    }

    /** Wert in der Datenbank = gelesener Wert aus der Datei? */
    private static function same(string $field, mixed $db, mixed $file): bool
    {
        $text = fn ($v) => $v === null || $v === '' || $v === false ? '' : (is_bool($v) ? '1' : (string) $v);

        return $text($db) === $text($file);
    }

    /**
     * Alben für die Seite „Schreiben“ (offene Tag-Änderungen bzw. Datei-Aktionen), nach Ordner sortiert.
     *
     * @param  Collection<int, string>  $hashes  Alben mit Datei-Aktionen (Sorter) – dazu alle mit offenen Änderungen
     * @return array{total: int, albums: Collection<int, array>}
     */
    public function albums(Collection $hashes, string $search = '', int $limit = 50): array
    {
        $hashes = self::pending()->distinct()->pluck('album_hash')->merge($hashes)->unique()->values();
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($search)).'%';
        $query = fn () => Track::whereIn('album_hash', $hashes)->when(trim($search) !== '', fn ($q) => $q->where(fn ($w) => $w->where('album', 'like', $like)
            ->orWhere('albumartist', 'like', $like)->orWhere('artist', 'like', $like)->orWhere('album_dir', 'like', $like)));
        $page = $query()->selectRaw('album_hash, MIN(album_dir) AS dir')->groupBy('album_hash')->orderBy('dir')->limit($limit)->pluck('album_hash');
        $tracks = Track::whereIn('album_hash', $page)->get(['id', 'location', 'album_hash', 'album_dir', 'album', 'albumartist', 'artist', 'ext', 'error', 'has_cover'])->groupBy('album_hash');
        // offene Änderungen, älteste zuerst: deren alter Wert steht jetzt noch in der Datei
        $open = TagChange::whereIn('track_id', $tracks->flatten()->pluck('id'))->orderBy('id')->get(['track_id', 'field', 'old_value'])->groupBy('track_id');
        $fields = $open->map(fn ($list) => $list->unique('field')->values());
        $full = Track::whereIn('id', $fields->keys())->get()->keyBy('id');
        $mode = fn (Collection $t, string $f) => $t->pluck($f)->filter()->countBy()->sortDesc()->keys()->first();

        return [
            'total' => $query()->distinct()->count('album_hash'),
            'albums' => $page->map(function ($hash) use ($tracks, $fields, $mode, $full) {
                $t = $tracks[$hash];
                $first = $t->first();
                $changed = $t->filter(fn ($x) => isset($fields[$x->id]));

                return [
                    'hash' => $hash,
                    'location' => $first->location,
                    'dir' => ltrim(mb_substr($first->album_dir, mb_strlen(Locations::root($first->location))), '/'),
                    'album' => $mode($t, 'album'),
                    'albumartist' => $mode($t, 'albumartist') ?? $mode($t, 'artist'),
                    'cover' => $t->firstWhere('has_cover', true)?->id ?? $first->id,
                    'tracks' => $changed->count(),
                    'unwritable' => $changed->filter(fn ($x) => ! in_array($x->ext, self::FORMATS, true) || $x->error !== null)->count(),
                    'fields' => $changed->flatMap(fn ($x) => $fields[$x->id]->pluck('field'))->countBy()->sortDesc()->keys()->map(fn ($f) => Track::EDITABLE[$f] ?? $f)->all(),
                    'changes' => $changed->map(fn ($x) => self::trackChanges($full[$x->id], $fields[$x->id]))->sortBy(fn ($c) => [$c['disc'], $c['track'], $c['title']])->values()->all(),
                ];
            }),
        ];
    }
}
