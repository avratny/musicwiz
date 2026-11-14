<?php

namespace App\Livewire;

use App\Jobs\CheckRecordings;
use App\Jobs\ConfirmClearArtists;
use App\Jobs\LookupArtists;
use App\Livewire\Concerns\ShowsMessages;
use App\Models\Artist;
use App\Support\ClearArtists;
use App\Support\MusicBrainzData;
use App\Support\SpellingAi;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;

/**
 * Interpreten › Eindeutig / Wahrscheinlich / Schreibweise (Regeln: ClearArtists) – gesammelt oder einzeln übernehmen:
 * (umbenennen,) bestätigen, IDs und Genres aus dem Treffer, Bild, wenn noch keins da ist. Bei „Wahrscheinlich“ lässt
 * sich zusätzlich über die eigenen Lieder bei MusicBrainz prüfen, welcher Gleichnamige gemeint ist.
 */
class ArtistClear extends Component
{
    use ShowsMessages;

    private const STEP = 100;

    /** eindeutig, wahrscheinlich, schreibweise */
    public string $level = 'eindeutig';

    /** abgewählt: werden bei „Alle übernehmen“ ausgelassen */
    public array $skip = [];

    public int $limit = self::STEP;

    /** gerade einzeln übernommen: bleiben mit Haken an ihrer Stelle stehen (ID => Zusammenfassung) */
    public array $done = [];

    /** Reihenfolge der angezeigten Zeilen – damit Übernommene nicht aus der Liste springen */
    public array $order = [];

    public function toggle(int $id): void
    {
        $this->skip = in_array($id, $this->skip, true) ? array_values(array_diff($this->skip, [$id])) : [...$this->skip, $id];
    }

    /** Alle (außer abgewählten) im Hintergrund übernehmen */
    public function confirmAll(ClearArtists $clear): void
    {
        $ids = array_values(array_diff($clear->ids($this->level), $this->skip));
        ConfirmClearArtists::start($ids, $this->level);
        $this->say(count($ids).' '.(count($ids) === 1 ? 'Interpret wird' : 'Interpreten werden').' im Hintergrund übernommen – Fortschritt unten.');
        $this->dispatch('activity-started');
    }

    /** Einen sofort übernehmen – die Zeile bleibt mit Haken und Zusammenfassung stehen */
    public function confirm(int $id, ClearArtists $clear): void
    {
        $this->take($id, $clear, null);
    }

    /** Schreibweise: mit dieser Schreibweise übernehmen („so lassen“ = der bisherige Name, oder Claudes Vorschlag) */
    public function confirmAs(int $id, string $spelling, ClearArtists $clear): void
    {
        $this->take($id, $clear, $spelling);
    }

    /** Schreibweise: $spelling, sonst Claudes Antwort, sonst MusicBrainz */
    private function take(int $id, ClearArtists $clear, ?string $spelling): void
    {
        $artist = Artist::findOrFail($id);
        $old = $artist->name;
        $c = $clear->check($artist);
        if (! $c || ! $clear->confirm($artist, $this->level, $spelling)) {
            $this->error = '„'.$artist->name.'“ steht nicht mehr in dieser Liste – nichts übernommen.';
            $this->message = null;

            return;
        }
        $artist->refresh();
        $parts = $artist->name !== $old ? ['umbenannt', 'bestätigt'] : ['bestätigt'];
        if ($c['genres']) {
            $parts[] = count($c['genres']) === 1 ? '1 Genre' : count($c['genres']).' Genres';
        }
        $parts[] = $c['image'] ? ($artist->imagePath() ? 'Bild übernommen' : 'Bild nicht erreichbar') : ($artist->imagePath() ? 'Bild war schon da' : 'kein Bild gefunden');
        $this->done[$id] = ['name' => $artist->name, 'summary' => implode(' · ', $parts)];
        $this->say('„'.$artist->name.'“ übernommen: '.implode(' · ', $parts).'.');
        $this->dispatch('artist-tools-changed');
    }

    /** Schreibweise › „Claude fragen“: welche Schreibweise stimmt? Antwort steht danach in der Zeile */
    public function askClaude(int $id, ClearArtists $clear, SpellingAi $ai): void
    {
        $artist = Artist::findOrFail($id);
        $c = $clear->check($artist);
        if (! $c || ! $c['rename']) {
            $this->error = '„'.$artist->name.'“ steht nicht mehr in dieser Liste.';
            $this->message = null;

            return;
        }
        try {
            $answer = $ai->check($artist, $c['rename'], $c['mb'], $c['group']);
            $this->say('Claude: „'.$answer->spelling.'“'.($answer->source === 'ours' ? ' – so lassen' : '').'.');
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
            $this->message = null;
        }
    }

    /** Wahrscheinlich › „Lieder prüfen“: im Hintergrund bei MusicBrainz nach den eigenen Titeln suchen */
    public function checkRecordings(): void
    {
        if (CheckRecordings::start()) {
            $this->say('Lieder werden im Hintergrund bei MusicBrainz geprüft – Fortschritt unten.');
            $this->dispatch('activity-started');
        }
    }

    #[On('lieder-finished')]
    public function recordingsChecked(): void
    {
        $this->order = [];
        $this->dispatch('artist-tools-changed');
    }

    #[On('eindeutig-finished')]
    public function finished(): void
    {
        $this->skip = $this->done = $this->order = [];
        $this->dispatch('artist-tools-changed');
    }

    public function more(): void
    {
        $this->limit += self::STEP;
        $this->order = [];
    }

    public function render(ClearArtists $clear, MusicBrainzData $musicbrainz)
    {
        // die Liste ist zwischengespeichert, die angezeigten Zeilen werden frisch geprüft
        $ids = $clear->ids($this->level);
        $byId = $clear->rows(array_slice($ids, 0, $this->limit), $this->level);
        // feste Reihenfolge: bisherige Zeilen bleiben stehen (Übernommene mit Haken), neue kommen hinten dazu
        $this->order = [...array_values(array_filter($this->order, fn ($id) => isset($byId[$id]) || isset($this->done[$id]))),
            ...array_values(array_diff(array_keys($byId), $this->order))];

        return view('livewire.artist-clear', [
            'rows' => array_map(fn ($id) => $byId[$id] ?? ['done' => $this->done[$id], 'id' => $id], $this->order),
            'total' => count($ids),
            'chosen' => count(array_diff($ids, $this->skip)),
            'more' => count($ids) > $this->limit,
            'available' => $musicbrainz->available(),
            'pendingLookup' => LookupArtists::pending()->count(),
            'claudeAvailable' => $this->level === 'schreibweise' && app(SpellingAi::class)->available(),
            'evidenceOpen' => $this->level === 'wahrscheinlich' ? count($clear->evidenceOpen()) : 0,
        ]);
    }
}
