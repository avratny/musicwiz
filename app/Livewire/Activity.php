<?php

namespace App\Livewire;

use App\Models\HistoryEntry;
use App\Models\Operation;
use App\Support\AutoCleanup;
use App\Support\Scanner;
use App\Support\Settings;
use Livewire\Attributes\On;
use Livewire\Component;

/** Laufende Scans und Aktionen in der Seitenleiste. */
class Activity extends Component
{
    #[On('activity-changed')]
    public function refresh(): void {}

    public function cancelScan(Scanner $scanner): void
    {
        $scanner->requestCancel();
    }

    public function render(Scanner $scanner)
    {
        $scan = $scanner->status();
        $ops = Operation::whereIn('status', ['pending', 'running'])->latest()->get()->reject->isPlanned();
        $recent = Operation::whereNotIn('status', ['pending', 'running'])->where('finished_at', '>', now()->subSeconds(8))->latest()->first();

        // Protokoll: die letzten Dateiänderungen (neueste zuerst)
        $log = HistoryEntry::with('operation:id,type')->latest('id')->limit(30)->get();

        return view('livewire.activity', [
            'log' => $log,
            'autoCount' => count(app(AutoCleanup::class)->config()),
            'base' => app(Settings::class)->base(),
            'scan' => $scan,
            'ops' => $ops,
            'recent' => $recent,
            'active' => ($scan['running'] ?? false) || $ops->isNotEmpty() || $recent,
        ]);
    }
}
