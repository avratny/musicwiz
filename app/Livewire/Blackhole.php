<?php

namespace App\Livewire;

use App\Models\Album;
use App\Models\Track;
use App\Support\ToInbox;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/**
 * Blackhole: Musik, die nicht gehört wird. Gleiche Ansicht wie die Lib, aber nichts wird bearbeitet – Album oder Titel
 * lassen sich nur zurück in die Inbox holen (kein Aufräumen, kein Abgleich, zählt nicht für Künstler und Genres).
 */
#[Layout('layouts.app'), Title('Blackhole')]
class Blackhole extends Library
{
    protected string $area = 'blackhole';

    /** aufgeklapptes Album (Titelliste statt Editor) */
    #[Url]
    public ?int $open = null;

    public function albumToInbox(int $id, ToInbox $toInbox): void
    {
        $album = Album::where('location', 'blackhole')->findOrFail($id);
        $this->started($toInbox->album($album)->description);
        $this->open = null;
    }

    public function trackToInbox(int $id, ToInbox $toInbox): void
    {
        $track = Track::where('location', 'blackhole')->findOrFail($id);
        $this->started($toInbox->track($track)->description);
    }

    private function started(string $description): void
    {
        $this->dispatch('activity-changed');
        session()->flash('status', $description.' – läuft, Details im Protokoll.');
    }

    protected function extraData(): array
    {
        $album = $this->open ? Album::where('location', 'blackhole')->find($this->open) : null;

        return ['openAlbum' => $album, 'openTracks' => $album ? $album->tracks()->get() : collect()];
    }
}
