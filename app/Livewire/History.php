<?php

namespace App\Livewire;

use App\Models\Operation;
use App\Support\OperationRunner;
use App\Support\Settings;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app'), Title('Verlauf')]
class History extends Component
{
    use WithPagination;

    public ?int $open = null;

    public ?int $confirmUndo = null;

    public function toggle(int $id): void
    {
        $this->open = $this->open === $id ? null : $id;
    }

    public function undo(int $id): void
    {
        $op = Operation::findOrFail($id);
        if ($op->canUndo()) {
            OperationRunner::dispatchUndo($op);
            $this->dispatch('activity-changed');
        }
        $this->confirmUndo = null;
    }

    public function render(Settings $settings)
    {
        $ops = Operation::withCount('entries')->latest('id')->paginate(30);
        $active = $ops->getCollection()->contains(fn ($o) => $o->isActive());

        return view('livewire.history', [
            'ops' => $ops,
            'active' => $active,
            'entries' => $this->open ? Operation::find($this->open)?->entries()->orderBy('id')->limit(500)->get() : collect(),
            'base' => $settings->base(),
        ]);
    }
}
