<?php

namespace App\Livewire\Concerns;

use App\Models\Album;
use App\Models\Track;
use App\Support\Ratings;
use Livewire\Attributes\On;

/** Bewerten per Klick auf die Sterne (schreibt in die Dateien, als Aktion im Verlauf). */
trait RatesMusic
{
    public function rateTrack(int $id, int $stars): void
    {
        if (! $this->canRate()) {
            return;
        }
        $this->afterRating(app(Ratings::class)->track(Track::findOrFail($id), $stars));
    }

    public function rateAlbum(int $id, int $stars): void
    {
        if (! $this->canRate()) {
            return;
        }
        $this->afterRating(app(Ratings::class)->album(Album::findOrFail($id), $stars));
    }

    /** Im Player bewertet: Sterne auf der Seite nachziehen */
    #[On('rating-changed')]
    public function ratingChanged(): void
    {
        $this->afterRating(null);
    }

    protected function canRate(): bool
    {
        return true;
    }

    protected function afterRating(?object $operation): void
    {
        if ($operation) {
            $this->dispatch('activity-changed');
        }
    }
}
