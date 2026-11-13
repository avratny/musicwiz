<?php

namespace App\Support;

use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * ReplayGain 2.0 (Sonstiges › ReplayGain fehlt): Lautheit nach EBU R128 über ffmpeg, Bezug −18 LUFS. Jede Datei wird
 * einmal komplett dekodiert; die Messung (LUFS, Peak) bleibt am Lied gespeichert. Der Album-Wert wird aus den Liedern
 * nach Länge gewichtet zusammengesetzt – fehlt einem Album etwas, werden alle noch nicht gemessenen Lieder gemessen und
 * alle Werte des Albums neu gesetzt. Ordner ohne gemeinsames Album: jedes Lied für sich. Nur in der Datenbank
 * (System-Änderung), nur Inbox und Lib.
 */
class ReplayGain
{
    public const REFERENCE = -18.0;

    /** Tag-Felder (in der Datei REPLAYGAIN_TRACK_GAIN usw.) */
    public const FIELDS = ['rg_track_gain', 'rg_track_peak', 'rg_album_gain', 'rg_album_peak'];

    /** ReplayGain aus den rohen Tags der Datei (Vorbis, MP3 TXXX, M4A – Groß/klein egal) */
    public static function fromRawTags(array $raw): array
    {
        $upper = array_change_key_case($raw, CASE_UPPER);
        $out = [];
        foreach (self::FIELDS as $field) {
            $name = 'REPLAYGAIN_'.strtoupper(substr($field, 3));
            $out[$field] = null;
            foreach ([$name, 'TXXX:'.$name, '----:COM.APPLE.ITUNES:'.$name] as $key) {
                foreach ((array) ($upper[$key] ?? []) as $v) {
                    if (($v = trim((string) $v)) !== '') {
                        $out[$field] = mb_substr($v, 0, 32);
                        break 2;
                    }
                }
            }
        }

        return $out;
    }

    /** Lieder ohne ReplayGain (Lied- oder Album-Wert fehlt) */
    public static function missing(): Builder
    {
        return Track::whereIn('location', SplitAlbums::LOCATIONS)->whereNull('error')
            ->where(fn ($q) => $q->whereNull('rg_track_gain')->orWhereNull('rg_album_gain'));
    }

    public function count(): int
    {
        return self::missing()->distinct()->count('album_hash');
    }

    /**
     * Alben mit fehlendem ReplayGain, nach Ordner.
     *
     * @return array{total: int, tracks: int, albums: list<array>}
     */
    public function albums(string $search = '', int $limit = 50): array
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($search)).'%';
        $base = self::missing()->when(trim($search) !== '', fn ($q) => $q->where(fn ($w) => $w->where('album', 'like', $like)
            ->orWhere('albumartist', 'like', $like)->orWhere('artist', 'like', $like)->orWhere('album_dir', 'like', $like)));
        $hashes = (clone $base)->selectRaw('album_hash, MIN(album_dir) AS dir')->groupBy('album_hash')->orderBy('dir')->limit($limit)->pluck('album_hash');
        $tracks = Track::whereIn('location', SplitAlbums::LOCATIONS)->whereIn('album_hash', $hashes)
            ->get(['id', 'location', 'album_hash', 'album_dir', 'album', 'albumartist', 'artist', 'has_cover', 'rg_track_gain', 'rg_album_gain', 'loudness'])->groupBy('album_hash');
        $mode = fn (Collection $t, string $f) => $t->pluck($f)->filter()->countBy()->sortDesc()->keys()->first();

        return [
            'total' => (clone $base)->distinct()->count('album_hash'),
            'tracks' => (clone $base)->count(),
            'albums' => $hashes->map(function ($hash) use ($tracks, $mode) {
                $t = $tracks[$hash];
                $first = $t->first();

                return [
                    'hash' => $hash,
                    'location' => $first->location,
                    'dir' => ltrim(mb_substr($first->album_dir, mb_strlen(Locations::root($first->location))), '/'),
                    'album' => $mode($t, 'album'),
                    'albumartist' => $mode($t, 'albumartist') ?? $mode($t, 'artist'),
                    'cover' => $t->firstWhere('has_cover', true)?->id ?? $first->id,
                    'tracks' => $t->count(),
                    'missing' => $t->filter(fn ($x) => $x->rg_track_gain === null || $x->rg_album_gain === null)->count(),
                    'measured' => $t->whereNotNull('loudness')->count(),
                    'loose' => self::loose($t),
                ];
            })->all(),
        ];
    }

    /** Alben (Hashes), denen etwas fehlt */
    public static function pendingAlbums(): Collection
    {
        return self::missing()->selectRaw('album_hash, MIN(album_dir) AS dir')->groupBy('album_hash')->orderBy('dir')->pluck('album_hash');
    }

    /**
     * Album-Wert für ReplayGain nur bei einheitlichem Album-Tag – sonst jedes Lied für sich (Player fassen nur Lieder mit
     * gleichem Album-Tag zusammen). Ob ein Ordner als Album gilt, entscheidet Albums::loose.
     */
    public static function loose(Collection $tracks): bool
    {
        return $tracks->pluck('album')->filter(fn ($v) => $v !== null && $v !== '')->unique()->count() !== 1
            || $tracks->contains(fn ($t) => $t->album === null || $t->album === '');
    }

    /**
     * Alben: alle noch nicht gemessenen Lieder messen – über die Alben hinweg parallel (immer $workers Messungen
     * gleichzeitig, auch bei Einzeltiteln), dann je Album ReplayGain für alle Lieder setzen.
     *
     * @param  list<string>  $hashes
     * @return array{measured: int, changed: int, failed: list<string>}
     */
    public function measureAlbums(array $hashes, int $workers = 4): array
    {
        $tracks = Track::whereIn('location', SplitAlbums::LOCATIONS)->whereIn('album_hash', $hashes)->whereNull('error')->get();
        [$measured, $failed] = $this->measure($tracks->whereNull('loudness'), $workers);
        $changed = 0;
        $editor = app(TagEditor::class);
        foreach ($tracks->groupBy('album_hash') as $album) {
            $groups = self::loose($album) ? $album->map(fn ($t) => collect([$t])) : collect([$album]);
            foreach ($groups as $group) {
                if ($group->contains(fn ($t) => $t->loudness === null)) {
                    continue; // Album-Wert braucht alle Messungen
                }
                foreach (self::tags($group) as $id => $values) {
                    $changed += $editor->update($group->firstWhere('id', $id), $values, TagEditor::SYSTEM, 'ReplayGain gemessen (EBU R128, Bezug −18 LUFS)') ? 1 : 0;
                }
            }
        }

        return ['measured' => $measured, 'changed' => $changed, 'failed' => $failed];
    }

    /** Ein Album messen und setzen */
    public function album(string $hash, int $workers = 4): array
    {
        return $this->measureAlbums([$hash], $workers);
    }

    /**
     * Lieder messen (ffmpeg, $workers gleichzeitig); die Messung bleibt am Lied.
     *
     * @return array{0: int, 1: list<string>} gemessen, nicht messbar (Titel)
     */
    private function measure(Collection $tracks, int $workers): array
    {
        $measured = 0;
        $failed = [];
        $queue = $tracks->values()->all();
        $running = [];
        // fortlaufend: sobald eine Messung fertig ist, startet die nächste (nicht auf das längste Lied warten)
        while ($queue || $running) {
            while ($queue && count($running) < max(1, $workers)) {
                $t = array_shift($queue);
                $running[] = [$t, Process::timeout(900)->start(['ffmpeg', '-hide_banner', '-nostats', '-i', $t->path, '-vn', '-af', 'ebur128=peak=true', '-f', 'null', '-'])];
            }
            foreach ($running as $i => [$t, $process]) {
                if ($process->running()) {
                    continue;
                }
                unset($running[$i]);
                $result = $process->wait();
                $m = $result->successful() ? self::parse($result->errorOutput()) : null;
                if ($m === null) {
                    $failed[] = $t->title ?: $t->filename;

                    continue;
                }
                // Messung ist kein Tag: direkt am Lied, ohne Änderungsprotokoll
                DB::table('tracks')->where('id', $t->id)->update(['loudness' => $m['lufs'], 'loudness_peak' => $m['peak']]);
                $t->loudness = $m['lufs'];
                $t->loudness_peak = $m['peak'];
                $measured++;
            }
            usleep(50_000);
        }

        return [$measured, $failed];
    }

    /** Zusammenfassung von ffmpeg ebur128 („I: -9.3 LUFS“, „Peak: 0.5 dBFS“); null bei Stille bzw. nicht messbar */
    public static function parse(string $log): ?array
    {
        $at = strrpos($log, 'Summary:');
        $summary = $at === false ? $log : substr($log, $at);
        if (! preg_match('/I:\s+(-?[\d.]+|-inf)\s+LUFS/', $summary, $i) || $i[1] === '-inf' || (float) $i[1] <= -70) {
            return null;
        }
        $peakDb = preg_match('/Peak:\s+(-?[\d.]+|-inf)\s+dBFS/', $summary, $p) && $p[1] !== '-inf' ? (float) $p[1] : -120.0;

        return ['lufs' => (float) $i[1], 'peak' => 10 ** ($peakDb / 20)];
    }

    /**
     * Werte für alle Lieder einer Gruppe (Album): Album-Lautheit nach Länge gewichtet, Album-Peak = höchster.
     *
     * @param  Collection<int, Track>  $tracks  alle gemessen
     * @return array<int, array<string, string>> Lied-ID => Felder
     */
    public static function tags(Collection $tracks): array
    {
        $weight = $tracks->sum(fn ($t) => max(1.0, (float) $t->duration));
        $energy = $tracks->sum(fn ($t) => max(1.0, (float) $t->duration) * 10 ** ($t->loudness / 10));
        $albumLufs = 10 * log10($energy / $weight);
        $albumPeak = (float) $tracks->max('loudness_peak');
        $out = [];
        foreach ($tracks as $t) {
            $out[$t->id] = [
                'rg_track_gain' => self::gain($t->loudness),
                'rg_track_peak' => sprintf('%.6f', $t->loudness_peak),
                'rg_album_gain' => self::gain($albumLufs),
                'rg_album_peak' => sprintf('%.6f', $albumPeak),
            ];
        }

        return $out;
    }

    private static function gain(float $lufs): string
    {
        return sprintf('%.2f dB', self::REFERENCE - $lufs);
    }
}
