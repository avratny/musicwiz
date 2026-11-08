<?php

namespace App\Livewire;

use App\Models\Operation;
use App\Support\OperationRunner;
use App\Support\Settings;
use App\Support\Trash;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app'), Title('Papierkorb')]
class TrashPage extends Component
{
    #[Url]
    public string $q = '';

    public array $selected = [];

    public string $onConflict = 'skip';

    public ?string $done = null;

    public array $watching = [];

    public function selectAll(Trash $trash): void
    {
        $this->selected = array_column($this->filtered($trash), 'rel');
    }

    public function restore(Trash $trash): void
    {
        $actions = [];
        foreach ($this->chosen($trash) as $f) {
            $actions[] = ['type' => 'move', 'path' => $f['path'], 'target' => $f['original']];
        }
        $this->start($actions, 'Papierkorb: '.count($actions).' Datei(en) wiederhergestellt', ['on_conflict' => $this->onConflict]);
    }

    public function deleteForever(Trash $trash): void
    {
        $actions = array_map(fn ($f) => ['type' => 'delete_trash', 'path' => $f['path']], $this->chosen($trash));
        $this->start($actions, 'Papierkorb: '.count($actions).' Datei(en) endgültig gelöscht');
    }

    private function start(array $actions, string $label, array $options = []): void
    {
        if (! $actions) {
            return;
        }
        $op = OperationRunner::dispatch('trash', $label, $actions, $options);
        $this->watching[] = $op->id;
        $this->selected = [];
        $this->dispatch('activity-changed');
    }

    private function chosen(Trash $trash): array
    {
        return array_values(array_filter($trash->files(), fn ($f) => in_array($f['rel'], $this->selected, true)));
    }

    private function filtered(Trash $trash): array
    {
        $files = $trash->files();
        if (trim($this->q) !== '') {
            $files = array_values(array_filter($files, fn ($f) => stripos($f['rel'], trim($this->q)) !== false));
        }

        return $files;
    }

    #[On('activity-changed')]
    public function refreshList(): void {}

    public function render(Trash $trash, Settings $settings)
    {
        $finished = Operation::whereIn('id', $this->watching)->whereNotIn('status', ['pending', 'running'])->get();
        if ($finished->isNotEmpty()) {
            $this->done = $finished->map(fn ($op) => ($op->status === 'failed' ? '⚠ ' : '✓ ').$op->description
                .($op->failed ? ' – '.$op->failed.' übersprungen (Ziel belegt?)' : ''))->implode("\n");
            $this->watching = array_values(array_diff($this->watching, $finished->pluck('id')->all()));
        }
        $files = $this->filtered($trash);
        $this->selected = array_values(array_intersect($this->selected, array_column($trash->files(), 'rel')));

        return view('livewire.trash', [
            'files' => $files,
            'totalSize' => array_sum(array_column($files, 'size')),
            'root' => $trash->root(),
            'base' => $settings->base(),
            'running' => (bool) $this->watching,
        ]);
    }
}
