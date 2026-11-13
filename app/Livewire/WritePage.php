<?php

namespace App\Livewire;

use App\Jobs\WriteChanges;
use App\Livewire\Concerns\ShowsMessages;
use App\Support\Sorter;
use App\Support\TagWriter;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Schreiben: Vorschau je Album, was passiert – geänderte Felder (TagWriter) und wohin die Dateien wandern bzw. warum
 * nicht (Sorter) – und Start im Hintergrund, alle bzw. je Album (WriteChanges). Fehler des letzten Durchlaufs stehen oben.
 */
#[Layout('layouts.app')]
class WritePage extends Component
{
    use ShowsMessages;

    private const STEP = 50;

    #[Url(as: 'q', except: '')]
    public string $query = '';

    public int $limit = self::STEP;

    public function updatedQuery(): void
    {
        $this->limit = self::STEP;
    }

    public function more(): void
    {
        $this->limit += self::STEP;
    }

    /** ein Album bzw. alle (null) schreiben – im Hintergrund */
    public function write(?string $hash): void
    {
        if (! WriteChanges::start($hash === null ? null : [$hash])) {
            $this->error = 'Läuft schon bzw. nichts Schreibbares – bitte warten, bis das Schreiben fertig ist.';
            $this->message = null;

            return;
        }
        $this->dispatch('activity-started');
    }

    #[On('write-finished')]
    public function finished(): void {}

    public function render(TagWriter $writer, Sorter $sorter)
    {
        $plans = $sorter->overview();
        $files = $plans->sum(fn ($p) => array_sum($p['counts']));

        return view('livewire.write-page', [
            ...$writer->albums($plans->keys(), $this->query, $this->limit),
            'plans' => $plans,
            'tracks' => TagWriter::pending()->count(),
            'writable' => TagWriter::writable()->count(),
            'files' => $files,
            'startable' => count(WriteChanges::albums()),
            'failed' => Cache::get(WriteChanges::FAILED, []),
        ])->title('Schreiben – MusicWiz');
    }
}
