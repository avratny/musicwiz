<?php

namespace App\Support;

use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Akustischer Fingerabdruck (Chromaprint, tools/fingerprint.py – alles lokal): ermittelt auf Knopfdruck (Sonstiges ›
 * Akustischer Fingerabdruck) für Lib und Blackhole, für neue Lieder der Inbox nach dem Einlesen. In Inbox und Lib ist er
 * eine Tag-Änderung (System) und kommt beim Schreiben ins Tag ACOUSTID_FINGERPRINT (wie bei Picard); im Blackhole
 * ändern sich keine Tags – dort steht er nur in der Datenbank. Steht er schon im Tag, wird er beim Einlesen übernommen.
 * Vergleich zweier Lieder: Anteil gleicher Bits (1 = gleich) – ab MATCH dieselbe Aufnahme, unter DIFFERENT eine andere.
 */
class Fingerprints
{
    public const LOCATIONS = ['inbox', 'library', 'blackhole'];

    public const MATCH = 0.9;

    public const DIFFERENT = 0.8;

    public const REASON = 'Fingerabdruck ermittelt';

    /** Fingerabdruck aus den rohen Tags (Picard: Vorbis ACOUSTID_FINGERPRINT, ID3 TXXX „Acoustid Fingerprint“) */
    public static function fromRawTags(array $raw): ?string
    {
        $upper = array_change_key_case($raw, CASE_UPPER);
        foreach (['ACOUSTID_FINGERPRINT', 'TXXX:ACOUSTID FINGERPRINT', '----:COM.APPLE.ITUNES:ACOUSTID FINGERPRINT'] as $key) {
            foreach ((array) ($upper[$key] ?? []) as $v) {
                if (preg_match('/^[A-Za-z0-9_\-]{20,}$/', $v = trim((string) $v))) {
                    return $v;
                }
            }
        }

        return null;
    }

    /** Lieder ohne Fingerabdruck in diesen Bereichen (lesbar, MP3/FLAC, nicht schon gescheitert) */
    public static function pending(array $locations): Builder
    {
        return Track::whereIn('location', array_intersect($locations, self::LOCATIONS))->whereNull('fingerprint')
            ->whereNull('fingerprint_failed_at')->whereNull('error')->whereIn('ext', TagWriter::FORMATS);
    }

    /** @return array<string, array{total: int, done: int, pending: int, failed: int}> je Bereich */
    public static function counts(): array
    {
        $rows = Track::whereIn('location', self::LOCATIONS)->groupBy('location')
            ->selectRaw('location, COUNT(*) AS total, SUM(fingerprint IS NOT NULL) AS done, SUM(fingerprint_failed_at IS NOT NULL) AS failed')->get()->keyBy('location');
        $out = [];
        foreach (self::LOCATIONS as $location) {
            $pending = self::pending([$location])->count();
            $r = $rows[$location] ?? null;
            $out[$location] = ['total' => (int) ($r->total ?? 0), 'done' => (int) ($r->done ?? 0), 'pending' => $pending, 'failed' => (int) ($r->failed ?? 0)];
        }

        return $out;
    }

    /**
     * Fingerabdrücke ermitteln (parallel) und speichern.
     *
     * @param  Collection<int, Track>  $tracks
     * @return array{done: int, failed: int}
     */
    public function measure(Collection $tracks, int $workers = 4): array
    {
        $byPath = $tracks->keyBy('path');
        $process = new Process(['python3', base_path('tools/fingerprint.py'), 'measure']);
        $process->setTimeout(null);
        $process->setInput(json_encode(['files' => $byPath->keys()->all(), 'workers' => $workers], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $process->run();
        $done = $failed = 0;
        $answered = [];
        foreach (preg_split('/\R/', trim($process->getOutput())) as $line) {
            $r = json_decode($line, true);
            $track = is_array($r) ? $byPath[$r['path'] ?? ''] ?? null : null;
            if (! $track) {
                continue;
            }
            $answered[$track->id] = true;
            if (! empty($r['ok'])) {
                $this->store(Track::find($track->id), $r['fingerprint']);
                $done++;
            } else {
                Track::whereKey($track->id)->update(['fingerprint_failed_at' => now()]);
                $failed++;
            }
        }
        // keine Antwort (Absturz): als gescheitert vermerken, sonst käme es endlos wieder
        $missing = $tracks->reject(fn ($t) => isset($answered[$t->id]))->pluck('id');
        if ($missing->isNotEmpty()) {
            Track::whereIn('id', $missing)->update(['fingerprint_failed_at' => now()]);
            $failed += $missing->count();
        }

        return ['done' => $done, 'failed' => $failed];
    }

    /** Fingerabdruck setzen: Inbox/Lib als Tag-Änderung (wird geschrieben), Blackhole nur in der Datenbank */
    public function store(?Track $track, string $fingerprint): void
    {
        if (! $track) {
            return;
        }
        if (Locations::ignored($track->location)) {
            $track->update(['fingerprint' => $fingerprint]);
        } else {
            app(TagEditor::class)->update($track, ['fingerprint' => $fingerprint], TagEditor::SYSTEM, self::REASON);
        }
    }

    /**
     * Ähnlichkeit für Paare von Liedern (beide mit Fingerabdruck), zwischengespeichert, solange beide gleich bleiben.
     *
     * @param  list<array{0: int, 1: int}>  $pairs
     * @return array<string, ?float> „kleinere-größere ID“ => Ähnlichkeit (null = nicht vergleichbar bzw. kein Fingerabdruck)
     */
    public function scores(array $pairs): array
    {
        $pairs = collect($pairs)->map(fn ($p) => [min($p), max($p)])->unique(fn ($p) => $p[0].'-'.$p[1])->values();
        $ids = $pairs->flatten()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }
        // nur ein Kürzel der Fingerabdrücke laden (die ganzen nur, wenn neu verglichen werden muss)
        $hashes = Track::whereIn('id', $ids)->whereNotNull('fingerprint')->selectRaw('id, SHA1(fingerprint) AS h')->pluck('h', 'id');
        $cached = DB::table('fingerprint_scores')->whereIn('track_a', $ids)->whereIn('track_b', $ids)->get()->keyBy(fn ($r) => $r->track_a.'-'.$r->track_b);
        $out = [];
        $todo = [];
        foreach ($pairs as [$a, $b]) {
            $key = $a.'-'.$b;
            if (! isset($hashes[$a], $hashes[$b])) {
                $out[$key] = null;

                continue;
            }
            $both = sha1($hashes[$a].$hashes[$b]);
            if (isset($cached[$key]) && $cached[$key]->fingerprints === $both) {
                $out[$key] = $cached[$key]->score === null ? null : (float) $cached[$key]->score;
            } else {
                $todo[$key] = [$a, $b, $both];
            }
        }
        if ($todo) {
            $fps = Track::whereIn('id', collect($todo)->flatMap(fn ($t) => [$t[0], $t[1]])->unique())->pluck('fingerprint', 'id');
            $process = new Process(['python3', base_path('tools/fingerprint.py'), 'compare']);
            $process->setTimeout(null);
            $process->setInput(json_encode(['pairs' => collect($todo)->map(fn ($t, $key) => [$key, $fps[$t[0]], $fps[$t[1]]])->values()->all()]));
            $process->run();
            foreach (preg_split('/\R/', trim($process->getOutput())) as $line) {
                $r = json_decode($line, true);
                if (! is_array($r) || ! isset($todo[$r['key'] ?? ''])) {
                    continue;
                }
                [$a, $b, $both] = $todo[$r['key']];
                $score = is_numeric($r['score']) ? (float) $r['score'] : null;
                DB::table('fingerprint_scores')->upsert([['track_a' => $a, 'track_b' => $b, 'fingerprints' => $both, 'score' => $score]], ['track_a', 'track_b'], ['fingerprints', 'score']);
                $out[$r['key']] = $score;
            }
        }

        return $out;
    }
}
