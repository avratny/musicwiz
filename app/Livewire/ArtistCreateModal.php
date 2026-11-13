<?php

namespace App\Livewire;

use App\Support\ArtistRegistry;
use InvalidArgumentException;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Modal „Interpret anlegen“ (Knopf in Interpreten): öffnen mit dem Event „open-artist-create“ (name = Vorschlag,
 * context = beliebige Angabe des Aufrufers), nach dem Anlegen kommt „artist-created“ mit id und context zurück.
 */
class ArtistCreateModal extends Component
{
    public bool $open = false;

    public string $name = '';

    public bool $confirmed = true;

    public mixed $context = null;

    public ?string $error = null;

    #[On('open-artist-create')]
    public function show(string $name = '', mixed $context = null): void
    {
        $this->name = $name;
        $this->confirmed = true;
        $this->context = $context;
        $this->error = null;
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function save(ArtistRegistry $registry): void
    {
        try {
            $artist = $registry->add($this->name, $this->confirmed);
        } catch (InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $this->open = false;
        $this->dispatch('artist-created', id: $artist->id, context: $this->context);
    }

    public function render()
    {
        return view('livewire.artist-create-modal');
    }
}
