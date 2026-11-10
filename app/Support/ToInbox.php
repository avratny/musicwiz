<?php

namespace App\Support;

use App\Models\Album;
use App\Models\Operation;
use App\Models\Track;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Aus dem Blackhole zurück in die Inbox (Album oder einzelne Titel). Der Kategorie-Kommentar (BLACKHOLE …) wird geleert,
 * damit in der Inbox keine Kategorie vorausgewählt ist; alle anderen Tags bleiben. Eingeordnet wird danach wie jede andere
 * Inbox-Musik. Läuft als Aktion über den OperationRunner (Verlauf, rückgängig machbar).
 */
class ToInbox
{
    public function __construct(private Settings $settings) {}

    public function album(Album $album): Operation
    {
        $tracks = $album->tracks()->get();
        $dir = $this->targetDir($album);
        $actions = $this->moves($album, $tracks, $dir);
        foreach ($album->allDirs() as $from) {
            $actions[] = ['type' => 'album_files', 'from_dir' => $from, 'to_dir' => $dir];
        }

        return OperationRunner::dispatch('to_inbox', 'Aus dem Blackhole in die Inbox: '.$this->label($album), $actions);
    }

    public function track(Track $track): Operation
    {
        $album = $track->albumRecord;
        $dir = $album ? $this->targetDir($album) : $this->unique($this->settings->inbox().'/'.$this->folder(basename($track->dir)));

        return OperationRunner::dispatch('to_inbox', 'Aus dem Blackhole in die Inbox: '.trim($track->artist.' – '.$track->title, ' –'),
            $this->moves($album, collect([$track]), $dir));
    }

    /**
     * Pfade, die gerade schon in die Inbox wandern (Aktion wartet bzw. läuft) – nicht ein zweites Mal losschicken.
     *
     * @return array<string, true>
     */
    public static function busyPaths(): array
    {
        $paths = [];
        foreach (Operation::where('type', 'to_inbox')->whereIn('status', ['pending', 'running'])->get() as $op) {
            foreach ($op->payload['actions'] ?? [] as $a) {
                if (! empty($a['path'])) {
                    $paths[$a['path']] = true;
                }
            }
        }

        return $paths;
    }

    private function moves(?Album $album, Collection $tracks, string $dir): array
    {
        if ($this->settings->inbox() === '') {
            throw new RuntimeException('Kein Inbox-Ordner eingerichtet (Einstellungen).');
        }
        $busy = self::busyPaths();
        $tracks = $tracks->filter(fn (Track $t) => $t->location === 'blackhole' && ! isset($busy[$t->path]));
        if ($tracks->isEmpty()) {
            throw new RuntimeException('Wird bereits in die Inbox verschoben.');
        }

        return $tracks->map(function (Track $t) use ($album, $dir) {
            // Unterordner im Album (z. B. CD 1/CD 2) bleiben erhalten
            $base = $album ? collect($album->allDirs())->first(fn ($d) => str_starts_with($t->path, $d.'/')) : null;
            $relative = $base ? substr($t->path, strlen($base) + 1) : $t->filename;

            $target = $dir.'/'.$relative;

            return ($t->comment ?? '') !== '' || ($t->category ?? '') !== ''
                ? ['type' => 'apply', 'path' => $t->path, 'tags' => ['comment' => '', 'category' => ''], 'target' => $target]
                : ['type' => 'move', 'path' => $t->path, 'target' => $target];
        })->values()->all();
    }

    /** Inbox-Ordner fürs Album: „Interpret - Album (Jahr)“ bzw. bei Samplern der Albumordner. */
    private function targetDir(Album $album): string
    {
        $name = basename($album->dir);
        if (! $album->is_compilation && $album->folder_artist && ! str_starts_with($name, $album->folder_artist)) {
            $name = $album->folder_artist.' - '.$name;
        }

        return $this->unique($this->settings->inbox().'/'.$this->folder($name));
    }

    /** Nie in einen schon vorhandenen Inbox-Ordner mischen. */
    private function unique(string $dir): string
    {
        $candidate = $dir;
        for ($i = 2; file_exists($candidate); $i++) {
            $candidate = $dir.' ('.$i.')';
        }

        return $candidate;
    }

    private function folder(string $name): string
    {
        return trim(str_replace('/', $this->settings->get('replace_slash', '-'), $name)) ?: 'Blackhole';
    }

    private function label(Album $album): string
    {
        return trim(($album->is_compilation ? '' : ($album->folder_artist ?: $album->albumartist).' – ').$album->title, ' –');
    }
}
