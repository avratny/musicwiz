<?php

namespace App\Livewire;

use App\Models\Album;
use App\Models\Track;
use App\Support\ToInbox;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use RuntimeException;

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
        $album = Album::where('location', 'blackhole')->find($id);
        $this->run(fn () => $album ? $toInbox->album($album) : null);
        $this->open = null;
    }

    public function trackToInbox(int $id, ToInbox $toInbox): void
    {
        $track = Track::where('location', 'blackhole')->find($id);
        $this->run(fn () => $track ? $toInbox->track($track) : null);
    }

    /** Doppelklick bzw. schon unterwegs: Meldung statt zweiter Aktion. */
    private function run(callable $start): void
    {
        try {
            $op = $start();
        } catch (RuntimeException $e) {
            session()->flash('status', '⚠ '.$e->getMessage());

            return;
        }
        if ($op) {
            $this->started($op->description);
        }
    }

    private function started(string $description): void
    {
        $this->dispatch('activity-changed');
        session()->flash('status', $description.' – läuft, Details im Protokoll.');
    }

    protected function extraData(): array
    {
        $album = $this->open ? Album::where('location', 'blackhole')->find($this->open) : null;

        // gerade unterwegs in die Inbox: Einträge als "wird verschoben …" zeigen, bis die Aktion fertig ist (Seite fragt nach)
        $busy = ToInbox::busyPaths();
        $busyAlbums = $busy ? Track::whereIn('path', array_keys($busy))->whereNotNull('album_id')->distinct()->pluck('album_id')->flip()->all() : [];

        return ['openAlbum' => $album, 'openTracks' => $album ? $album->tracks()->get() : collect(), 'busy' => $busy, 'busyAlbums' => $busyAlbums];
    }
}
