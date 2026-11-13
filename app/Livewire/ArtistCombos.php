<?php

namespace App\Livewire;

use App\Jobs\CheckComboNames;
use App\Livewire\Concerns\ShowsMessages;
use App\Models\ArtistSplit;
use App\Support\ArtistCredits;
use App\Support\ComboVerdict;
use App\Support\Lookup\NameCheck;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Interpreten › Kombis: „Beam & Yanou“ trennen, ganz lassen oder ganz + Mitglieder – mit Fazit aus der Namensprüfung;
 * feat. aus dem Titel holen; Entscheidungen verwalten. Die Seite liest nur Gespeichertes – gefragt wird im Hintergrund.
 */
class ArtistCombos extends Component
{
    use ShowsMessages;

    /** offene Entscheidungen, feat. im Titel, gemerkte Entscheidungen */
    public const TABS = ['offen' => 'Offen', 'titel' => 'feat. im Titel', 'entschieden' => 'Entschieden'];

    private const STEP = 20;

    #[Url(as: 'bereich', except: 'offen')]
    public string $tab = 'offen';

    /** auf später verschobene Kombis (Namen) stehen hinten */
    public array $later = [];

    public int $limit = self::STEP;

    /** Trennen: in diese Teile (fehlende werden als Interpreten angelegt) */
    public function split(string $name, array $parts, ArtistCredits $credits): void
    {
        $this->attempt(function () use ($name, $parts, $credits) {
            $n = $credits->split($name, $parts);
            $this->message = '„'.$name.'“ getrennt: '.implode(' · ', array_filter(array_map('trim', $parts))).self::changed($n);
        });
        $this->dispatch('artist-tools-changed');
    }

    /** Bleibt ganz: ist ein eigener Interpret (wird bestätigt) */
    public function keepWhole(string $name, ArtistCredits $credits): void
    {
        $credits->keepWhole($name);
        $this->say('„'.$name.'“ bleibt ganz.');
        $this->dispatch('artist-tools-changed');
    }

    /** Bleibt ganz + Mitglieder: die Gruppe bleibt Interpret, die Mitglieder kommen mit ins ARTISTS-Tag */
    public function keepWithMembers(string $name, array $members, ArtistCredits $credits): void
    {
        $this->attempt(function () use ($name, $members, $credits) {
            $n = $credits->keepWithMembers($name, $members);
            $this->message = '„'.$name.'“ bleibt ganz, Mitglieder verknüpft: '.implode(' · ', array_diff(array_filter(array_map('trim', $members)), [$name])).self::changed($n);
        });
        $this->dispatch('artist-tools-changed');
    }

    /** Entschieden: Entscheidung aufheben */
    public function undo(string $name, ArtistCredits $credits): void
    {
        $credits->undo($name);
        $this->say('Entscheidung zu „'.$name.'“ aufgehoben.');
        $this->dispatch('artist-tools-changed');
    }

    /** feat. im Titel: Vorschläge übernehmen */
    public function moveTitleGuests(array $ids, ArtistCredits $credits): void
    {
        $n = $credits->moveTitleGuests(array_map('intval', $ids));
        $this->say($n.' '.($n === 1 ? 'Lied' : 'Lieder').' angepasst: feat. aus dem Titel in den Interpreten.');
        $this->dispatch('artist-tools-changed');
    }

    /** Dienste fragen: offene Kombis, ihre Teile und Mitglieder im Hintergrund prüfen */
    public function check(): void
    {
        CheckComboNames::start();
        $this->dispatch('activity-started');
    }

    #[On('combos-finished')]
    public function refresh(): void {}

    public function postpone(string $name): void
    {
        $this->later = [...array_diff($this->later, [$name]), $name];
    }

    public function more(): void
    {
        $this->limit += self::STEP;
    }

    private static function changed(int $n): string
    {
        return $n ? ' – '.$n.' '.($n === 1 ? 'Lied' : 'Lieder').' angepasst.' : '.';
    }

    /**
     * Die angezeigten Kombis mit Gruppe (Mitglieder laut MusicBrainz), den Prüfungen je Karte (Teile, die Mitglieder
     * sind: die Prüfung genau dieses Mitglieds über seine ID) und dem Fazit.
     */
    private function cards(array $open): array
    {
        $later = array_flip($this->later);
        usort($open, fn ($a, $b) => (int) isset($later[$a['name']]) <=> (int) isset($later[$b['name']]) ?: ($later[$a['name']] ?? 0) <=> ($later[$b['name']] ?? 0));
        $shown = array_slice($open, 0, $this->limit);
        $checks = NameCheck::results(array_merge([], ...array_map(fn ($c) => [$c['name'], ...$c['parts']], $shown)));
        foreach ($shown as $i => $c) {
            $shown[$i]['group'] = NameCheck::group($c, $checks);
        }
        $members = NameCheck::memberResults(array_merge([], ...array_map(fn ($c) => array_column($c['group']['parts'] ?? [], 'mbid'), $shown)));

        return array_map(function ($c) use ($checks, $members) {
            $card = array_intersect_key($checks, array_flip([$c['name'], ...$c['parts']]));
            foreach ($c['group']['parts'] ?? [] as $part => $member) {
                if (isset($members[$member['mbid']])) {
                    $card[$part] = $members[$member['mbid']];
                }
            }

            return ['combo' => $c, 'checks' => $card, 'verdict' => ComboVerdict::for($c, $card)];
        }, $shown);
    }

    public function render(ArtistCredits $credits)
    {
        $open = $credits->open();
        $titles = $credits->titleSuggestions();

        return view('livewire.artist-combos', [
            'counts' => ['offen' => count($open), 'titel' => count($titles), 'entschieden' => ArtistSplit::count()],
            'cards' => $this->tab === 'offen' ? $this->cards($open) : [],
            'more' => count($open) > $this->limit,
            'unchecked' => $this->tab === 'offen' ? count(NameCheck::pending()) : 0,
            'titles' => array_slice($titles, 0, 300),
            'decisions' => $this->tab === 'entschieden' ? $credits->decisions() : collect(),
        ]);
    }
}
