<?php

namespace App\Livewire;

use App\Models\CleanupException;
use App\Models\Operation;
use App\Models\Track;
use App\Support\OperationRunner;
use App\Support\TextRepair;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Zeichen-Assistent: kaputte Zeichen in Tags richten. Eine Entscheidung je auffälligem Wert gilt für alle Titel,
 * in denen er vorkommt. Geschrieben wird erst nach der Vorschau – als Aktion über den Verlauf (rückgängig machbar).
 * Lib und Inbox; das Blackhole wird nicht bearbeitet.
 */
#[Layout('layouts.app'), Title('Zeichen-Assistent')]
class TextRepairAssistant extends Component
{
    /** all | library | inbox */
    #[Url]
    public string $scope = 'all';

    /** Entscheidung je Wert (id): skip | keep | custom | o0, o1, … (Vorschlag) */
    public array $choice = [];

    /** eigene Eingabe je Wert (id) */
    public array $custom = [];

    public int $limit = 50;

    public ?array $preview = null;

    public array $watching = [];

    public ?string $done = null;

    public bool $showKept = false;

    private ?array $items = null;

    public function updatedScope(): void
    {
        $this->limit = 50;
        $this->preview = null;
    }

    public function more(): void
    {
        $this->limit += 50;
    }

    /** Alle Werte auf „Später“ bzw. alle sicheren auf ihre Korrektur */
    public function chooseAll(string $mode): void
    {
        foreach ($this->items() as $item) {
            $this->choice[$item['id']] = $mode === 'safe' && $item['safe'] ? 'o0' : 'skip';
        }
    }

    public function previewChanges(): void
    {
        $map = [];
        $keep = [];
        $changes = [];
        foreach ($this->items() as $item) {
            $new = $this->chosenValue($item);
            if ($new === 'keep') {
                $keep[$item['key']] = $item['value'];
            } elseif ($new !== null) {
                $map[$item['key']] = $new;
                $changes[] = ['group' => TextRepair::GROUP_LABELS[$item['group']], 'old' => $item['value'], 'new' => $new, 'count' => count($item['tracks'])];
            }
        }
        $actions = [];
        $unwritable = 0;
        if ($map) {
            $ids = array_unique(array_merge(...array_map(fn ($item) => isset($map[$item['key']]) ? $item['tracks'] : [], $this->items())));
            foreach (Track::whereIn('id', $ids)->get() as $t) {
                $tags = TextRepair::replaced($t->tags(), fn ($field, $value) => $map[TextRepair::key($field, $value)] ?? null);
                if (! $tags) {
                    continue;
                }
                if (! $t->writable) {
                    $unwritable++;

                    continue;
                }
                $actions[] = ['type' => 'apply', 'path' => $t->path, 'tags' => $tags];
            }
        }
        // Aktionen und „so lassen“ nur im Server-Zwischenspeicher – im Seitenzustand würden sie bei jedem Klick
        // mitgeschickt (Livewire-Grenze 1 MB); angezeigt werden die ersten 300 Änderungen
        $token = bin2hex(random_bytes(8));
        Cache::put(self::PREVIEW_KEY.$token, ['keep' => $keep, 'actions' => $actions, 'changes' => count($changes)], now()->addHours(2));
        $this->preview = ['token' => $token, 'changes' => array_slice($changes, 0, 300), 'changeCount' => count($changes),
            'keep' => array_slice($keep, 0, 50), 'keepCount' => count($keep), 'actionCount' => count($actions), 'unwritable' => $unwritable];
    }

    private const PREVIEW_KEY = 'text-repair.preview.';

    public function confirm(): void
    {
        $stored = $this->preview ? Cache::pull(self::PREVIEW_KEY.$this->preview['token']) : null;
        if (! $stored) {
            $this->preview = null;

            return;
        }
        foreach ($stored['keep'] as $key => $value) {
            CleanupException::firstOrCreate(['check' => 'encoding', 'item_key' => $key], ['label' => $value]);
        }
        if ($stored['actions']) {
            $n = $stored['changes'];
            $op = OperationRunner::dispatch('repair', 'Zeichen reparieren: '.$n.' '.($n === 1 ? 'Wert' : 'Werte'), $stored['actions']);
            $this->watching[] = $op->id;
            $this->dispatch('activity-changed');
        }
        $this->done = null;
        $this->preview = null;
        $this->choice = [];
        $this->custom = [];
        $this->items = null;
    }

    public function unkeep(string $key): void
    {
        CleanupException::where('check', 'encoding')->where('item_key', $key)->delete();
        $this->items = null;
    }

    #[On('activity-changed')]
    public function refreshList(): void {}

    /** null = nichts ändern; 'keep' = so lassen; sonst der neue Wert */
    private function chosenValue(array $item): ?string
    {
        $choice = $this->choice[$item['id']] ?? $this->defaultChoice($item);
        if ($choice === 'keep') {
            return 'keep';
        }
        if ($choice === 'custom') {
            $v = TextRepair::stripInvisible((string) ($this->custom[$item['id']] ?? ''));

            return $v !== '' && $v !== $item['value'] ? $v : null;
        }
        if (str_starts_with($choice, 'o')) {
            return $item['options'][(int) substr($choice, 1)]['value'] ?? null;
        }

        return null;
    }

    /** Vorauswahl: sichere Korrektur bzw. ein einziger plausibler Codepage-Vorschlag; sonst „Später“ */
    private function defaultChoice(array $item): string
    {
        if ($item['safe']) {
            return 'o0';
        }
        $codepage = array_keys(array_filter($item['options'], fn ($o) => $o['kind'] === 'codepage'));

        return count($codepage) === 1 && in_array('codepage', $item['problems'], true) ? 'o'.$codepage[0] : 'skip';
    }

    private function locations(): array
    {
        return match ($this->scope) {
            'library' => ['library'],
            'inbox' => ['inbox'],
            default => ['library', 'inbox'],
        };
    }

    /** @return list<array> auffällige Werte, häufigste zuerst */
    private function items(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }
        $locations = $this->locations();
        $version = md5(implode('|', [implode(',', $locations), Track::count(), Track::max('updated_at'), CleanupException::where('check', 'encoding')->count(), CleanupException::max('id'), filemtime((new \ReflectionClass(TextRepair::class))->getFileName())]));

        return $this->items = Cache::remember('text-repair.'.$version, now()->addMinutes(30), function () use ($locations) {
            $ignored = CleanupException::where('check', 'encoding')->pluck('label', 'item_key')->all();
            $items = [];
            foreach (Track::whereIn('location', $locations)->get() as $t) {
                foreach (TextRepair::issues($t->tags(), $ignored) as $issue) {
                    $key = TextRepair::key($issue['field'], $issue['value']);
                    $items[$key] ??= ['key' => $key, 'id' => md5($key), 'group' => TextRepair::FIELDS[$issue['field']], 'value' => $issue['value'],
                        'problems' => $issue['analysis']['problems'], 'safe' => $issue['analysis']['safe'] !== null, 'analysis' => $issue['analysis'],
                        'tracks' => [], 'example' => trim($t->artist.' – '.$t->album, ' –'), 'locations' => []];
                    $items[$key]['tracks'][] = $t->id;
                    $items[$key]['locations'][$t->location] = true;
                }
            }
            $known = [];
            foreach ($items as &$item) {
                $a = $item['analysis'];
                $options = [];
                if ($a['cleaned'] !== $item['value']) {
                    $options[] = ['value' => $a['cleaned'], 'label' => $item['safe'] ? 'Korrektur' : 'bereinigt', 'kind' => 'clean'];
                }
                foreach ($a['suggestions'] as $s) {
                    $options[] = ['value' => $s['value'], 'label' => $s['label'], 'kind' => 'codepage'];
                }
                if (in_array('lost', $item['problems'], true)) {
                    $known[$item['group']] ??= $this->knownValues($item['group'], $locations);
                    foreach (TextRepair::similarKnown($a['cleaned'], $known[$item['group']]) as $k) {
                        $options[] = ['value' => $k, 'label' => 'so in deiner Sammlung', 'kind' => 'known'];
                    }
                }
                $seen = [];
                $item['options'] = array_values(array_filter($options, function ($o) use (&$seen, $item) {
                    if ($o['value'] === $item['value'] || isset($seen[$o['value']])) {
                        return false;
                    }

                    return $seen[$o['value']] = true;
                }));
                $item['tracks'] = array_values(array_unique($item['tracks']));
                $item['locations'] = array_keys($item['locations']);
                unset($item['analysis']);
            }
            unset($item);
            usort($items, fn ($x, $y) => [count($y['tracks']), $x['value']] <=> [count($x['tracks']), $y['value']]);

            return array_values($items);
        });
    }

    /** Vorhandene Werte einer Feldgruppe (für „so in deiner Sammlung“ bei verlorenen Zeichen) */
    private function knownValues(string $group, array $locations): array
    {
        $query = Track::whereIn('location', $locations);
        $values = match ($group) {
            'artist' => collect([...(clone $query)->distinct()->pluck('artist'), ...(clone $query)->distinct()->pluck('albumartist')]),
            'album' => (clone $query)->distinct()->pluck('album'),
            default => (clone $query)->distinct()->pluck('title'),
        };

        return $values->filter()->unique()->values()->all();
    }

    public function render()
    {
        $finished = Operation::whereIn('id', $this->watching)->whereNotIn('status', ['pending', 'running'])->get();
        if ($finished->isNotEmpty()) {
            $this->done = $finished->map(fn ($op) => ($op->status === 'failed' ? '⚠ ' : '✓ ').$op->description.': '.$op->done.' von '.$op->total.' Dateien'
                .($op->failed ? ', '.$op->failed.' fehlgeschlagen' : ''))->implode("\n");
            $this->watching = array_values(array_diff($this->watching, $finished->pluck('id')->all()));
            $this->items = null;
        }
        $items = $this->items();
        // nur sichtbare Werte vorbelegen – der Seitenzustand bleibt klein (für alle übrigen gilt die Vorauswahl)
        foreach (array_slice($items, 0, $this->limit) as $item) {
            $this->choice[$item['id']] ??= $this->defaultChoice($item);
            // „Eigene Eingabe“ mit dem Text vorbelegen (erster Vorschlag, sonst der bisherige Wert) – nur noch anpassen
            $this->custom[$item['id']] ??= $item['options'][0]['value'] ?? $item['value'];
        }
        $chosen = count(array_filter($items, fn ($item) => $this->chosenValue($item) !== null));

        return view('livewire.text-repair', [
            'items' => array_slice($items, 0, $this->limit),
            'total' => count($items),
            'chosen' => $chosen,
            'kept' => $this->showKept ? CleanupException::where('check', 'encoding')->orderBy('label')->get() : collect(),
            'keptCount' => CleanupException::where('check', 'encoding')->count(),
            'running' => (bool) $this->watching,
        ]);
    }
}
