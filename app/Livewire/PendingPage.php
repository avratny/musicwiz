<?php

namespace App\Livewire;

use App\Models\Operation;
use App\Support\OperationRunner;
use App\Support\PendingChanges;
use App\Support\Settings;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * „Änderungen schreiben“: vorgemerkte Änderungen aus Aufräumen und Künstlerverzeichnis (digitaler Zwilling) ansehen,
 * je Album oder alle in die Dateien schreiben bzw. verwerfen. Die Aktionen werden erst beim Klick berechnet.
 */
#[Layout('layouts.app'), Title('Änderungen schreiben')]
class PendingPage extends Component
{
    public int $limit = 50;

    /** laufende Schreib-Aktionen (zum Beobachten) */
    public array $watching = [];

    public ?string $done = null;

    /** @param  int|null  $albumId  nur dieses Album; null = alle */
    public function write(PendingChanges $pending, ?int $albumId = null): void
    {
        $plan = $pending->actions($albumId === null ? null : [$albumId]);
        if (! $plan['actions']) {
            return;
        }
        $op = OperationRunner::dispatch('pending', 'Vorgemerkte Änderungen schreiben ('.$plan['tracks'].' Titel)', $plan['actions'], ['on_conflict' => 'skip']);
        $this->watching[] = $op->id;
        $this->done = null;
        $this->dispatch('activity-changed');
    }

    /** @param  int|null  $albumId  nur dieses Album; null = alle */
    public function discard(PendingChanges $pending, ?int $albumId = null): void
    {
        $n = $pending->discard($albumId === null ? null : [$albumId]);
        $this->done = $n.' vorgemerkte Änderung(en) verworfen – die Dateien waren davon nicht betroffen.';
    }

    /** Alles Vorgemerkte als JSON herunterladen (zum Prüfen, bevor geschrieben wird). */
    public function export(PendingChanges $pending)
    {
        $json = json_encode($pending->export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return response()->streamDownload(fn () => print ($json), 'musicwiz-aenderungen-'.now()->format('Y-m-d-His').'.json', ['Content-Type' => 'application/json']);
    }

    public function loadMore(): void
    {
        $this->limit += 50;
    }

    #[On('activity-changed')]
    public function refresh(): void {}

    public function render(PendingChanges $pending, Settings $settings)
    {
        $finished = Operation::whereIn('id', $this->watching)->whereNotIn('status', ['pending', 'running'])->get();
        if ($finished->isNotEmpty()) {
            $this->done = $finished->map(fn ($op) => ($op->status === 'failed' ? '⚠ ' : '✓ ').$op->description.': '.$op->done.' von '.$op->total.' Schritten'
                .($op->failed ? ', '.$op->failed.' übersprungen – siehe Verlauf' : ''))->implode("\n");
            $this->watching = array_values(array_diff($this->watching, $finished->pluck('id')->all()));
        }
        $active = Operation::whereIn('status', ['pending', 'running'])->where('type', 'pending')->get()->reject->isPlanned()->values();

        return view('livewire.pending', [
            'albums' => $pending->summary($this->limit),
            'albumTotal' => $pending->albumCount(),
            'trackTotal' => $pending->count(),
            'active' => $active,
            'base' => rtrim($settings->base(), '/').'/',
        ]);
    }
}
