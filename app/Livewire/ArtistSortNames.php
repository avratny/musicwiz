<?php

namespace App\Livewire;

use App\Livewire\Concerns\ShowsMessages;
use App\Models\Artist;
use App\Support\SortNames;
use Livewire\Component;

/**
 * Interpreten › Sortiernamen: oben offene Abweichungen (Sortiername in der Datei passt nicht und ist nicht eindeutig) –
 * nach der Regel übernehmen oder als eigenen Sortiernamen behalten. Darunter alle Interpreten mit Sortiername (Regel oder
 * eigener) – eigenen setzen bzw. zurück zur Regel. Eindeutiges trägt MusicWiz selbst ein.
 */
class ArtistSortNames extends Component
{
    use ShowsMessages;

    private const STEP = 100;

    public string $query = '';

    public int $limit = self::STEP;

    /** eigener Sortiername je Interpret (Eingabe) */
    public array $own = [];

    public function updatedQuery(): void
    {
        $this->limit = self::STEP;
    }

    public function more(): void
    {
        $this->limit += self::STEP;
    }

    /** offene Abweichungen dieses Interpreten nach der Regel übernehmen */
    public function takeRule(int $id, SortNames $sorts): void
    {
        $artist = Artist::findOrFail($id);
        $n = $sorts->resolveArtist($artist);
        $this->done('„'.$artist->name.'“: Sortiername bei '.$n.' '.($n === 1 ? 'Lied' : 'Liedern').' übernommen.');
    }

    /** Wert aus der Datei als eigenen Sortiernamen behalten */
    public function keep(int $id, string $value, SortNames $sorts): void
    {
        $artist = Artist::findOrFail($id);
        $n = $sorts->setOverride($artist, $value);
        $this->done('„'.$artist->name.'“ sortiert jetzt als „'.$value.'“'.($n ? ' – '.$n.' '.($n === 1 ? 'Lied' : 'Lieder').' angepasst.' : '.'));
    }

    /** eigenen Sortiernamen setzen (leer = zurück zur Regel) */
    public function setOwn(int $id, SortNames $sorts): void
    {
        $artist = Artist::findOrFail($id);
        $value = trim((string) ($this->own[$id] ?? ''));
        $n = $sorts->setOverride($artist, $value);
        unset($this->own[$id]);
        $this->done('„'.$artist->name.'“ sortiert als „'.($sorts->forName($artist->name)).'“'.($n ? ' – '.$n.' '.($n === 1 ? 'Lied' : 'Lieder').' angepasst.' : '.'));
    }

    public function resetOwn(int $id, SortNames $sorts): void
    {
        $this->own[$id] = '';
        $this->setOwn($id, $sorts);
    }

    private function done(string $message): void
    {
        $this->say($message);
        $this->dispatch('artist-tools-changed');
    }

    public function render(SortNames $sorts)
    {
        $like = mb_strtolower(trim($this->query));
        $match = fn (Artist $a) => $like === '' || str_contains(mb_strtolower($a->name), $like) || str_contains(mb_strtolower((string) $a->sort_name), $like);
        $open = array_filter($sorts->openByArtist(), fn ($e) => $match($e['artist']));
        // Interpreten mit Sortiername (Regel oder eigener); bei Suche auch alle anderen, um einen eigenen zu setzen
        $named = Artist::orderBy('name')->get(['id', 'name', 'sort_name'])
            ->filter(fn (Artist $a) => $match($a) && ($like !== '' || $sorts->forName($a->name) !== $a->name))->values();

        return view('livewire.artist-sort-names', [
            'open' => $open,
            'named' => $named->take($this->limit),
            'total' => $named->count(),
            'articles' => implode(', ', array_map('ucfirst', $sorts->articles())),
            'sorts' => $sorts,
        ]);
    }
}
