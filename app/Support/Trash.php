<?php

namespace App\Support;

use App\Models\HistoryEntry;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Papierkorb-Ordner auf dem Share: die Ordnerstruktur unterhalb der Basis bleibt erhalten,
 * so lässt sich jede Datei an ihren ursprünglichen Ort zurücklegen.
 */
class Trash
{
    public function __construct(private Settings $settings) {}

    public function root(): string
    {
        return rtrim((string) $this->settings->get('trash'), '/');
    }

    /** Ursprünglicher Pfad einer Datei im Papierkorb. */
    public function originalPath(string $trashPath): string
    {
        return $this->settings->base().'/'.ltrim(substr($trashPath, strlen($this->root())), '/');
    }

    public function contains(string $path): bool
    {
        $real = realpath($path);
        $root = realpath($this->root());

        return $real && $root && str_starts_with($real, $root.'/');
    }

    private ?array $files = null;

    /**
     * Inhalt des Papierkorbs. Der Ordner liegt auf dem Share: nicht bei jedem Klick neu durchlaufen,
     * sondern nur nach einer Aktion (neuer Verlaufseintrag) bzw. spätestens nach 2 Minuten.
     *
     * @return list<array{path:string, rel:string, original:string, occupied:bool, size:int, date:int, reason:?string, audio:bool}>
     */
    public function files(): array
    {
        return $this->files ??= Cache::remember('trash.files.'.md5($this->root().'|'.HistoryEntry::max('id')), now()->addMinutes(2), fn () => $this->listFiles());
    }

    private function listFiles(): array
    {
        $root = $this->root();
        if (! is_dir($root)) {
            return [];
        }
        $paths = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && ! in_array($f->getFilename(), config('musicwiz.junk_files'), true)) {
                $paths[] = $f->getPathname();
            }
        }
        // Grund und Datum aus dem Verlauf
        $history = HistoryEntry::with('operation:id,description')->where('kind', 'trash')->whereIn('new_path', $paths)->latest('id')->get()->keyBy('new_path');
        $audio = config('musicwiz.audio_extensions');
        $out = [];
        foreach ($paths as $p) {
            $h = $history[$p] ?? null;
            $out[] = [
                'path' => $p,
                'rel' => ltrim(substr($p, strlen($root)), '/'),
                'original' => $this->originalPath($p),
                'occupied' => file_exists($this->originalPath($p)),
                'size' => (int) @filesize($p),
                'date' => $h ? $h->created_at->timestamp : (int) @filemtime($p),
                'reason' => $h?->error ?: $h?->operation?->description,
                'audio' => in_array(strtolower(pathinfo($p, PATHINFO_EXTENSION)), $audio, true),
            ];
        }
        usort($out, fn ($a, $b) => [$b['date'], $a['rel']] <=> [$a['date'], $b['rel']]);

        return $out;
    }

    public function count(): int
    {
        return Cache::remember('trash.count.'.md5($this->root().'|'.HistoryEntry::max('id')), now()->addMinutes(2), fn () => count($this->files()));
    }

    /** Endgültig löschen – nur innerhalb des Papierkorbs. */
    public function delete(string $path): void
    {
        if (! $this->contains($path)) {
            throw new RuntimeException('Liegt nicht im Papierkorb: '.$path);
        }
        if (! @unlink($path)) {
            throw new RuntimeException('Löschen fehlgeschlagen: '.$path);
        }
    }
}
