<?php

namespace App\Livewire;

use App\Models\Album;
use App\Models\PurchaseMark;
use App\Support\FlacCandidates;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Alben nur als MP3 (bzw. verlustbehaftet) – zum Nachkaufen als FLAC, mit Suchlink zu Qobuz. */
#[Layout('layouts.app'), Title('FLAC-Kandidaten')]
class FlacCandidatesPage extends Component
{
    #[Url]
    public string $q = '';

    /** alle | watch (Merkliste) | hidden (ausgeblendet) | incomplete (unvollständige Alben, jedes Format) */
    #[Url]
    public string $show = 'alle';

    #[Url]
    public int $minRating = 0;

    #[Url]
    public string $genre = '';

    #[Url]
    public bool $samplers = false;

    /** rating | artist | bitrate | year */
    #[Url]
    public string $sort = 'rating';

    public int $limit = 100;

    public function updating($name): void
    {
        if ($name !== 'limit') {
            $this->limit = 100;
        }
    }

    public function loadMore(): void
    {
        $this->limit += 100;
    }

    public function mark(int $albumId, string $state): void
    {
        $album = Album::find($albumId);
        if (! $album || ! in_array($state, ['watch', 'hidden'], true)) {
            return;
        }
        PurchaseMark::updateOrCreate(['item_key' => FlacCandidates::key($album)],
            ['state' => $state, 'label' => trim(($album->folder_artist ?: $album->albumartist).' – '.$album->title, ' –')]);
    }

    public function unmark(int $albumId): void
    {
        $album = Album::find($albumId);
        if ($album) {
            PurchaseMark::where('item_key', FlacCandidates::key($album))->delete();
        }
    }

    public function render(FlacCandidates $candidates)
    {
        $all = $candidates->all();
        $incomplete = $candidates->incomplete();
        // in den MP3-Listen auch zeigen, wenn ein Album zusätzlich unvollständig ist
        $missing = $incomplete->mapWithKeys(fn ($c) => [$c['album']->id => $c['missing']]);
        $all = $all->map(fn ($c) => ['missing' => $missing[$c['album']->id] ?? null] + $c);
        $counts = ['alle' => $all->where('state', '!=', 'hidden')->count(), 'watch' => $all->where('state', 'watch')->count(),
            'hidden' => $all->where('state', 'hidden')->count(), 'incomplete' => $incomplete->where('state', '!=', 'hidden')->count()];
        if ($this->show === 'incomplete') {
            $all = $incomplete;
        }
        $genres = $all->map(fn ($c) => $c['album']->genres[0] ?? null)->filter()->unique()->sort()->values();
        // Unvollständig: Sampler gehören dazu (sonst fehlen z. B. halbe Bravo-Hits-CDs)
        $withSamplers = $this->samplers || $this->show === 'incomplete';

        $q = mb_strtolower(trim($this->q));
        $list = $all
            ->filter(fn ($c) => match ($this->show) {
                'watch' => $c['state'] === 'watch',
                'hidden' => $c['state'] === 'hidden',
                default => $c['state'] !== 'hidden', // auch „incomplete“: Ausgeblendetes will man nicht kaufen
            })
            ->filter(fn ($c) => $withSamplers || ! $c['album']->is_compilation)
            ->filter(fn ($c) => ! $this->minRating || ($c['rating'] ?? 0) >= $this->minRating)
            ->filter(fn ($c) => $this->genre === '' || ($c['album']->genres[0] ?? null) === $this->genre)
            ->filter(fn ($c) => $q === '' || str_contains(mb_strtolower($c['album']->folder_artist.' '.$c['album']->albumartist.' '.$c['album']->title), $q));
        $artist = fn ($c) => mb_strtolower((string) ($c['album']->folder_artist ?: $c['album']->albumartist));
        $list = match ($this->sort) {
            'artist' => $list->sortBy(fn ($c) => [$artist($c), (string) $c['album']->year]),
            'bitrate' => $list->sortBy(fn ($c) => [$c['bitrate'] ?? 0, $artist($c)]),
            'year' => $list->sortBy(fn ($c) => [(int) $c['album']->year ?: 9999, $artist($c)]),
            default => $list->sortBy(fn ($c) => [-($c['rating'] ?? 0), $artist($c)]),
        };

        return view('livewire.flac-candidates', [
            'items' => $list->take($this->limit)->values(),
            'total' => $list->count(),
            'counts' => $counts,
            'genres' => $genres,
            'candidates' => $candidates,
        ]);
    }
}
