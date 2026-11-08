<?php

namespace App\Livewire;

use App\Livewire\Concerns\StartsScans;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Track;
use App\Support\ArtistDirectory;
use App\Support\Settings;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app'), Title('Lib')]
class Library extends Component
{
    use StartsScans;

    /** Bereich: library bzw. blackhole (gleiche Ansicht, siehe Blackhole) */
    protected string $area = 'library';

    #[Url]
    public string $category = '';

    #[Url]
    public string $letter = '';

    #[Url]
    public string $artist = '';

    #[Url]
    public string $q = '';

    public function setCategory(string $c): void
    {
        $this->category = $c;
        $this->letter = '';
        $this->artist = '';
    }

    public function setLetter(string $l): void
    {
        $this->letter = $l;
        $this->artist = '';
    }

    /** Zusätzliche Daten für die Ansicht (Blackhole: aufgeklapptes Album). */
    protected function extraData(): array
    {
        return [];
    }

    public function render(Settings $settings, ArtistDirectory $directory)
    {
        $categories = $settings->categoriesIn($this->area);
        $cat = collect($categories)->firstWhere('name', $this->category);
        $samplerMode = $this->letter === 'sampler' || ($cat['compilation'] ?? false);
        $base = Album::where('location', $this->area)->when($cat, fn ($q) => $q->where('category', $cat['name']));

        $letters = (clone $base)->where('is_compilation', false)->whereNotNull('letter')
            ->selectRaw('letter, count(distinct folder_artist) as n')->groupBy('letter')->pluck('n', 'letter')->all();
        ksort($letters);
        $nonLetter = $settings->get('non_letter_folder');
        if (isset($letters[$nonLetter])) {
            $letters = [$nonLetter => $letters[$nonLetter]] + array_diff_key($letters, [$nonLetter => 1]);
        }
        $samplerCount = (clone $base)->where('is_compilation', true)->count();

        $artists = collect();
        $albums = collect();
        $results = null;
        $artistModel = null;

        if (trim($this->q) !== '') {
            $term = '%'.trim($this->q).'%';
            $results = Track::where('location', $this->area)
                ->when($cat, fn ($q) => $q->whereHas('albumRecord', fn ($a) => $a->where('category', $cat['name'])))
                ->where(fn ($w) => $w->where('artist', 'like', $term)->orWhere('title', 'like', $term)->orWhere('album', 'like', $term)->orWhere('albumartist', 'like', $term))
                ->with('albumRecord')->orderBy('artist')->orderBy('album')->limit(300)->get();
        } elseif ($samplerMode) {
            $albums = (clone $base)->where('is_compilation', true)->orderByRaw('title COLLATE NOCASE')->get();
        } elseif ($this->letter !== '') {
            $artists = (clone $base)->where('is_compilation', false)->where('letter', $this->letter)
                ->selectRaw('folder_artist, count(*) as n')->groupBy('folder_artist')->orderByRaw('folder_artist COLLATE NOCASE')->get();
            if ($this->artist !== '') {
                $albums = (clone $base)->where('folder_artist', $this->artist)->orderBy('year')->orderBy('title')->get();
                $canonical = $directory->check($this->artist)['canonical'] ?? $this->artist;
                $artistModel = Artist::where('name', $canonical)->first();
            }
        }

        return view('livewire.library', [
            'area' => $this->area,
            'categories' => $categories,
            'letters' => $letters,
            'samplerCount' => $samplerCount,
            'samplerMode' => $samplerMode,
            'artists' => $artists,
            'albums' => $albums,
            'results' => $results,
            'artistModel' => $artistModel,
            'total' => (clone $base)->count(),
            'scan' => $this->scanStatus(),
        ] + $this->extraData());
    }
}
