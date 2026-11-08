<?php

namespace App\Support;

use App\Jobs\InboxWatchJob;
use App\Jobs\ScanJob;
use Illuminate\Support\Facades\Cache;

/**
 * Inbox beobachten: ein Job prüft regelmäßig, ob sich in der Inbox etwas geändert hat (Pfad, Größe, Änderungszeit der
 * Musikdateien), und startet dann einen Inbox-Scan. Nur lesen – eingeordnet wird weiter von Hand. Der Job plant sich
 * selbst neu ein (läuft im vorhandenen Worker, kein eigener Container); ein Lebenszeichen im Cache verhindert Doppelte.
 */
class InboxWatch
{
    private const ALIVE = 'inbox-watch:alive';

    private const SIGNATURE = 'inbox-watch:signature';

    public function __construct(private Settings $settings, private Scanner $scanner) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('inbox_watch');
    }

    public function interval(): int
    {
        return max(15, (int) config('musicwiz.inbox_watch_seconds', 60));
    }

    /** Beobachter starten, falls eingeschaltet und gerade keiner läuft (wird bei Seitenaufrufen aufgerufen, kostet nichts). */
    public function ensure(): void
    {
        if ($this->enabled() && Cache::add(self::ALIVE, true, $this->interval() * 3)) {
            InboxWatchJob::dispatch();
        }
    }

    /** Ein Durchgang; true = Beobachter soll weiterlaufen. */
    public function tick(): bool
    {
        if (! $this->enabled()) {
            Cache::forget(self::ALIVE);

            return false;
        }
        Cache::put(self::ALIVE, true, $this->interval() * 3);
        $signature = $this->signature();
        if ($signature === null) {
            return true; // Inbox gerade nicht erreichbar – nächstes Mal wieder
        }
        $previous = Cache::get(self::SIGNATURE);
        if ($previous !== $signature && ! ($this->scanner->status()['running'] ?? false)) {
            Cache::forever(self::SIGNATURE, $signature);
            // beim allerersten Durchgang nur merken – sonst würde jedes Einschalten einen Scan auslösen
            if ($previous !== null) {
                $this->scanner->status(['running' => true, 'location' => 'inbox', 'phase' => 'wartet', 'done' => 0, 'total' => 0, 'error' => null, 'held' => null, 'unreadable' => null]);
                ScanJob::dispatch('inbox');
            }
        }

        return true;
    }

    /** Fingerabdruck des Inbox-Inhalts (nur Musikdateien); null, wenn die Inbox nicht lesbar ist. */
    public function signature(): ?string
    {
        $root = $this->settings->inbox();
        if (! is_dir($root)) {
            return null;
        }
        $audio = config('musicwiz.audio_extensions');
        $parts = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && in_array(strtolower($file->getExtension()), $audio, true) && ! str_starts_with($file->getFilename(), '.')) {
                $parts[] = $file->getPathname().'|'.$file->getSize().'|'.$file->getMTime();
            }
        }
        sort($parts);

        return md5(implode("\n", $parts));
    }
}
