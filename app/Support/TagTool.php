<?php

namespace App\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Brücke zum externen Tag-Tool (Python/mutagen), aufgerufen über Laravels Process.
 */
class TagTool
{
    public function __construct(private Settings $settings) {}

    /**
     * @param  list<string>  $paths
     * @return array<string, array{ok:bool, tags?:array, error?:string}>
     */
    public function read(array $paths): array
    {
        if ($paths === []) {
            return [];
        }
        $paths = array_values($paths);
        // mehrere Prozesse gleichzeitig: beim Lesen über das Netzwerk wartet ein einzelner Prozess fast nur auf Antworten
        $workers = max(1, min((int) config('musicwiz.scan_workers', 4), intdiv(count($paths) + 9, 10)));
        $outputs = $workers === 1
            ? [$this->run('read', ['files' => $paths])]
            : $this->runParallel('read', array_map(fn ($chunk) => ['files' => $chunk], array_chunk($paths, (int) ceil(count($paths) / $workers))));
        $results = [];
        foreach ($outputs as $out) {
            foreach ($out['results'] ?? [] as $r) {
                $results[$r['path']] = $r;
            }
        }

        return $results;
    }

    /**
     * @param  list<array{path:string, tags:array, cover?:?string, remove_cover?:bool}>  $items
     * @return array<string, array{ok:bool, error?:string}>
     */
    public function write(array $items): array
    {
        if ($items === []) {
            return [];
        }
        $out = $this->run('write', ['files' => array_values($items), 'id3_version' => (int) $this->settings->get('id3_version', 4)]);
        $results = [];
        foreach ($out['results'] ?? [] as $r) {
            $results[$r['path']] = $r;
        }

        return $results;
    }

    /** Zwischendatei beim Schreiben (liegt nur nach einem Abbruch herum und ist dann gefahrlos löschbar). */
    public const WRITE_SUFFIX = '.musicwiz-write';

    /** Eingebettetes Cover in eine Datei schreiben ($frontOnly: nur ein echtes Frontcover, kein Ersatzbild). */
    public function extractCover(string $audioPath, string $target, bool $frontOnly = false): bool
    {
        $result = Process::timeout(60)->run(['python3', $this->script(), 'cover', $audioPath, $target, ...($frontOnly ? ['--front'] : [])]);
        $data = json_decode($result->output(), true);

        return $result->successful() && ($data['ok'] ?? false);
    }

    public static function canWrite(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['mp3', 'flac'], true);
    }

    private function run(string $cmd, array $payload): array
    {
        $result = Process::timeout(600)
            ->input(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->run(['python3', $this->script(), $cmd]);

        return $this->decode($result);
    }

    /** @param  list<array>  $payloads  @return list<array> */
    private function runParallel(string $cmd, array $payloads): array
    {
        $results = Process::pool(function (Pool $pool) use ($cmd, $payloads) {
            foreach ($payloads as $i => $payload) {
                $pool->as((string) $i)->timeout(600)
                    ->input(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                    ->command(['python3', $this->script(), $cmd]);
            }
        })->start()->wait();

        return array_map(fn ($i) => $this->decode($results[(string) $i]), array_keys($payloads));
    }

    private function decode(ProcessResult $result): array
    {
        if (! $result->successful()) {
            throw new RuntimeException('Tag-Tool fehlgeschlagen: '.trim($result->errorOutput() ?: $result->output()));
        }
        $data = json_decode($result->output(), true);
        if (! is_array($data)) {
            throw new RuntimeException('Tag-Tool lieferte keine gültige Antwort');
        }

        return $data;
    }

    private function script(): string
    {
        return config('musicwiz.tagtool');
    }
}
