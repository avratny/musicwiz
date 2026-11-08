<?php

namespace App\Livewire;

use App\Livewire\Concerns\NewArtistWizard;
use App\Models\Track;
use App\Support\ArtistDirectory;
use App\Support\ArtistGroupAnalyzer;
use App\Support\ArtistNames;
use App\Support\Genres;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * „Neuen Künstler erfassen“ als Dialog im Album-Editor – nur für den einen angeklickten Namen. Hörproben und die
 * Liedprüfung bei MusicBrainz kommen aus dem Album (auch in der Inbox). Gespeichert wird ins Künstlerverzeichnis;
 * die Dateien bleiben unverändert.
 */
class NewArtistModal extends Component
{
    use NewArtistWizard;

    /** ohne Adress-Parameter (anders als auf der Künstler-Seite) */
    public ?string $wizName = null;

    public bool $open = false;

    public ?int $albumId = null;

    public function mount(): void
    {
        $this->wizSingle = true;
    }

    #[On('open-new-artist')]
    public function openFor(string $name, int $album): void
    {
        $this->albumId = $album;
        $this->wizReset($name);
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->wizReset(null);
    }

    protected function wizFinished(): void
    {
        $this->close();
        $this->dispatch('artist-directory-changed');
    }

    /** Lieder dieses Albums, in denen der Name vorkommt (als Interpret oder Album-Interpret) */
    protected function wizSampleTracks(): array
    {
        $key = ArtistNames::compareKey((string) $this->wizName);
        $out = [];
        foreach (Track::where('album_id', $this->albumId)->orderBy('disc')->orderByRaw('CAST(track AS INTEGER)')->get() as $t) {
            $names = array_merge($t->artists ?: ArtistNames::split($t->artist), $t->albumartists ?: ArtistNames::split($t->albumartist));
            if (collect($names)->contains(fn ($n) => ArtistNames::compareKey($n) === $key)) {
                $out[] = ['id' => $t->id, 'album_id' => $t->album_id, 'seconds' => (float) $t->duration, 'title' => (string) $t->title, 'artist' => (string) $t->artist, 'album' => (string) $t->album];
            }
        }

        return $out;
    }

    public function render(ArtistDirectory $directory, Genres $genres)
    {
        return view('livewire.new-artist-modal', $this->open && $this->wizName !== null
            ? $this->wizData($directory, app(ArtistGroupAnalyzer::class)) + ['genreOptions' => $genres->options()]
            : []);
    }
}
