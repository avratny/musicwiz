<?php

namespace App\Support;

use App\Models\Album;
use App\Models\Track;
use Illuminate\Support\Facades\Cache;

/** Zahlen zur Lib (nur Bestand, nicht Inbox/Blackhole); zwischengespeichert, bis sich der Index ändert. */
class Statistics
{
    public function all(): array
    {
        return Cache::remember('statistics:v1:'.DataVersion::tracks(), now()->addHour(), fn () => $this->build());
    }

    private function build(): array
    {
        $lib = fn () => Track::where('location', 'library');
        $tracks = $lib()->count();
        $lossless = $lib()->whereIn('ext', ['flac', 'wav', 'aiff', 'alac'])->count();
        $pct = fn (int $n) => $tracks ? (int) round(100 * $n / $tracks) : 0;

        $bitrates = ['unter 192 kbit/s' => 0, '192–255 kbit/s' => 0, '256–319 kbit/s' => 0, '320 kbit/s' => 0];
        foreach ($lib()->where('ext', 'mp3')->pluck('bitrate') as $b) {
            $k = (int) round(($b ?? 0) / 1000);
            $bitrates[$k < 192 ? 'unter 192 kbit/s' : ($k < 256 ? '192–255 kbit/s' : ($k < 316 ? '256–319 kbit/s' : '320 kbit/s'))]++;
        }

        $albums = Album::where('location', 'library')->get(['id', 'year', 'genres', 'created_at', 'is_compilation', 'folder_artist']);
        $genres = [];
        $decades = [];
        foreach ($albums as $a) {
            $g = $a->genres[0] ?? null;
            if ($g) {
                $genres[$g] = ($genres[$g] ?? 0) + 1;
            }
            if ($a->year) {
                $d = intdiv((int) $a->year, 10) * 10;
                $decades[$d.'er'] = ($decades[$d.'er'] ?? 0) + 1;
            }
        }
        arsort($genres);
        ksort($decades);

        // neu in der Lib: Titel je Monat (Zeitpunkt der Erfassung im Index), letzte 12 Monate
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $months[now()->startOfMonth()->subMonths($i)->format('Y-m')] = 0;
        }
        foreach ($lib()->where('created_at', '>=', now()->startOfMonth()->subMonths(11))
            ->selectRaw("strftime('%Y-%m', created_at) as m, count(*) as n")->groupBy('m')->pluck('n', 'm') as $m => $n) {
            if (isset($months[$m])) {
                $months[$m] = (int) $n;
            }
        }

        // FLAC-Kandidaten: Alben nur aus verlustbehafteten Dateien, nach Künstler
        $lossy = FlacCandidates::albumIds();
        $candidates = Album::whereIn('id', $lossy)->where('is_compilation', false)->whereNotNull('folder_artist')
            ->selectRaw('folder_artist, count(*) as n')->groupBy('folder_artist')->orderByDesc('n')->limit(10)->pluck('n', 'folder_artist')->all();

        return [
            'tiles' => [
                'Titel' => number_format($tracks, 0, ',', '.'),
                'Alben' => number_format($albums->count(), 0, ',', '.'),
                'Künstler' => number_format($albums->pluck('folder_artist')->filter()->unique()->count(), 0, ',', '.'),
                'Spielzeit' => number_format($lib()->sum('duration') / 3600, 0, ',', '.').' h',
                'Speicher' => number_format($lib()->sum('size') / 1024 ** 3, 1, ',', '.').' GB',
                'Verlustfrei' => $pct($lossless).' %',
            ],
            'coverage' => [
                'Cover' => $pct($lib()->where('cover_embedded', true)->count()),
                'Genre' => $pct($lib()->whereNotNull('genres')->where('genres', '!=', '[]')->count()),
                'ReplayGain' => $pct($lib()->whereNotNull('replaygain')->count()),
                'Songtext' => $pct($lib()->where('has_lyrics', true)->count()),
                'Bewertet' => $pct($lib()->whereNotNull('rating')->count()),
            ],
            'formats' => $lib()->selectRaw('ext, count(*) as n')->groupBy('ext')->orderByDesc('n')->pluck('n', 'ext')->mapWithKeys(fn ($n, $e) => [strtoupper($e) => (int) $n])->all(),
            'bitrates' => $bitrates,
            'genres' => array_slice($genres, 0, 12, true),
            'decades' => $decades,
            'months' => $months,
            'candidates' => $candidates,
            'mp3_albums' => $lossy->count(),
        ];
    }
}
