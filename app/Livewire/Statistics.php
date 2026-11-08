<?php

namespace App\Livewire;

use App\Support\Statistics as Stats;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app'), Title('Statistik')]
class Statistics extends Component
{
    public function render(Stats $stats)
    {
        return view('livewire.statistics', ['s' => $stats->all()]);
    }
}
