@php
    use App\Support\TextRepair;
    $badge = ['mojibake' => 'badge-blue', 'invisible' => 'badge-gray', 'nfd' => 'badge-gray', 'codepage' => 'badge-amber', 'lost' => 'badge-red'];
@endphp
<div class="flex h-full flex-col overflow-hidden" @if ($running) wire:poll.2s @endif>
    <x-page-header title="Zeichen-Assistent" subtitle="Kaputte Zeichen in Tags richten – eine Entscheidung je Wert gilt für alle Titel, in denen er vorkommt. Geschrieben wird erst nach der Vorschau, rückgängig machbar im Verlauf.">
        <a href="{{ route('cleanup', ['check' => 'encoding']) }}" wire:navigate class="btn-ghost">← Aufräumen</a>
    </x-page-header>

    <div class="flex shrink-0 flex-wrap items-center gap-2 border-b border-zinc-800 px-6 py-2">
        @foreach (['all' => 'Lib und Inbox', 'library' => 'Nur Lib', 'inbox' => 'Nur Inbox'] as $k => $label)
            <button wire:click="$set('scope', '{{ $k }}')" class="{{ $scope === $k ? 'tab-active' : 'tab' }}">{{ $label }}</button>
        @endforeach
        <span class="ml-auto text-sm text-zinc-400">{{ $total }} auffällige {{ $total === 1 ? 'Wert' : 'Werte' }}</span>
        @if ($total)
            <button wire:click="chooseAll('safe')" class="btn-ghost btn-sm" title="Sichere Korrekturen vorauswählen, alles andere auf „Später“">Nur sichere</button>
            <button wire:click="chooseAll('skip')" class="btn-ghost btn-sm">Alle auf „Später“</button>
        @endif
    </div>

    @if ($done)
        <div class="mx-6 mt-3 flex shrink-0 items-start gap-3 rounded-lg border px-4 py-2 text-sm whitespace-pre-line {{ str_contains($done, '⚠') ? 'border-amber-500/30 bg-amber-500/10 text-amber-200' : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-200' }}">
            <span class="flex-1">{{ $done }}</span>
            <a href="{{ route('history') }}" wire:navigate class="shrink-0 underline">Verlauf</a>
            <button wire:click="$set('done', null)" class="shrink-0 opacity-70 hover:opacity-100">✕</button>
        </div>
    @endif
    @if ($running)
        <div class="mx-6 mt-3 flex shrink-0 items-center gap-2 rounded-lg border border-violet-500/30 bg-violet-500/10 px-4 py-2 text-sm text-violet-200">
            <span class="size-2 animate-pulse rounded-full bg-violet-400"></span> Wird geschrieben … die Liste aktualisiert sich danach.
        </div>
    @endif

    <div class="min-h-0 flex-1 overflow-y-auto px-6 py-4">
        <div class="space-y-3">
            @forelse ($items as $item)
                @php $id = $item['id']; @endphp
                <div wire:key="r{{ $id }}" class="card p-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="badge-violet">{{ TextRepair::GROUP_LABELS[$item['group']] }}</span>
                        <span class="font-mono text-sm break-all text-zinc-100">{{ TextRepair::visible($item['value']) }}</span>
                        @foreach ($item['problems'] as $p)
                            <span class="{{ $badge[$p] ?? 'badge-gray' }}">{{ TextRepair::LABELS[$p] }}</span>
                        @endforeach
                        <span class="ml-auto text-xs text-zinc-500">{{ count($item['tracks']) }} Titel
                            @if ($item['example']) · z. B. {{ $item['example'] }}@endif
                            · {{ implode(' + ', array_map(fn ($l) => $l === 'inbox' ? 'Inbox' : 'Lib', $item['locations'])) }}</span>
                    </div>
                    <div class="mt-3 space-y-1.5 text-sm">
                        @foreach ($item['options'] as $i => $o)
                            <label class="flex cursor-pointer items-center gap-2">
                                <input type="radio" wire:model.live="choice.{{ $id }}" value="o{{ $i }}" class="border-zinc-600 bg-zinc-800">
                                <span class="font-mono text-emerald-200">{{ $o['value'] }}</span>
                                <span class="text-xs text-zinc-500">{{ $o['label'] }}{{ $o['kind'] === 'clean' && $item['safe'] ? ' – eindeutig, verlustfrei' : '' }}{{ $o['kind'] === 'codepage' ? ' – bitte prüfen' : '' }}</span>
                            </label>
                        @endforeach
                        @if (! $item['options'] && in_array('lost', $item['problems'], true))
                            <p class="text-xs text-zinc-500">Die ursprünglichen Zeichen sind verloren und kein passender Name in deiner Sammlung – bitte selbst eintragen.</p>
                        @endif
                        <label class="flex items-center gap-2">
                            <input type="radio" wire:model.live="choice.{{ $id }}" value="custom" class="border-zinc-600 bg-zinc-800">
                            <span class="text-zinc-300">Eigene Eingabe:</span>
                            <input type="text" wire:model.live.debounce.400ms="custom.{{ $id }}" x-on:focus="$wire.set('choice.{{ $id }}', 'custom')"
                                   placeholder="{{ $item['options'][0]['value'] ?? $item['value'] }}" class="input-sm w-96">
                        </label>
                        <div class="flex flex-wrap gap-4 pt-1">
                            <label class="flex cursor-pointer items-center gap-2 text-zinc-400">
                                <input type="radio" wire:model.live="choice.{{ $id }}" value="skip" class="border-zinc-600 bg-zinc-800"> Später
                            </label>
                            <label class="flex cursor-pointer items-center gap-2 text-zinc-400" title="Der Wert ist richtig so – wird nicht mehr gemeldet (rückgängig unten)">
                                <input type="radio" wire:model.live="choice.{{ $id }}" value="keep" class="border-zinc-600 bg-zinc-800"> So lassen – ist richtig
                            </label>
                        </div>
                    </div>
                </div>
            @empty
                <p class="py-12 text-center text-zinc-500">Keine kaputten Zeichen gefunden. 🎉</p>
            @endforelse
            @if ($total > count($items))
                <button wire:click="more" class="btn-ghost">Weitere anzeigen ({{ $total - count($items) }})</button>
            @endif

            @if ($keptCount)
                <div class="pt-4 text-sm">
                    <button wire:click="$toggle('showKept')" class="text-zinc-500 hover:text-white">{{ $showKept ? '▾' : '▸' }} Als richtig markiert ({{ $keptCount }})</button>
                    @foreach ($kept as $k)
                        <div wire:key="k{{ $k->id }}" class="mt-1 flex items-center gap-3 pl-4">
                            <span class="font-mono text-zinc-400">{{ TextRepair::visible((string) $k->label) }}</span>
                            <button wire:click="unkeep(@js($k->item_key))" class="text-xs text-zinc-500 underline hover:text-white">wieder anzeigen</button>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    @if ($total)
        <div class="flex shrink-0 items-center gap-3 border-t border-zinc-800 px-6 py-3">
            <span class="text-sm text-zinc-400">{{ $chosen }} von {{ $total }} entschieden</span>
            <button wire:click="previewChanges" class="btn-primary ml-auto" @disabled(! $chosen || $running)>Vorschau …</button>
        </div>
    @endif

    @if ($preview)
        <div class="fixed inset-0 z-50 grid place-items-center bg-black/70 p-6 backdrop-blur-sm" x-data="{ downOutside: false }" x-on:keydown.escape.window="$wire.set('preview', null)"
             x-on:mousedown.self="downOutside = true" x-on:click.self="if (downOutside) $wire.set('preview', null); downOutside = false"
             x-on:mouseup="if ($event.target !== $el) downOutside = false">
            <div class="flex max-h-full w-full max-w-4xl flex-col overflow-hidden rounded-2xl border border-zinc-700 bg-zinc-900 shadow-2xl">
                <div class="flex items-center border-b border-zinc-800 px-6 py-4">
                    <h2 class="text-lg font-semibold">Vorschau: {{ count($preview['changes']) }} Werte, {{ count($preview['actions']) }} Dateien</h2>
                    <button wire:click="$set('preview', null)" class="ml-auto text-zinc-400 hover:text-white">✕</button>
                </div>
                <div class="min-h-0 overflow-y-auto px-6 py-4 text-sm">
                    @if ($preview['changes'])
                        <table class="w-full">
                            <thead><tr><th class="th">Feld</th><th class="th">Bisher</th><th class="th">Neu</th><th class="th text-right">Titel</th></tr></thead>
                            <tbody>
                            @foreach ($preview['changes'] as $c)
                                <tr>
                                    <td class="td text-zinc-400">{{ $c['group'] }}</td>
                                    <td class="td font-mono text-red-300/80">{{ TextRepair::visible($c['old']) }}</td>
                                    <td class="td font-mono text-emerald-200">{{ $c['new'] }}</td>
                                    <td class="td text-right text-zinc-400">{{ $c['count'] }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                    @if ($preview['keep'])
                        <p class="mt-4 text-zinc-400">Als richtig markiert (wird nicht mehr gemeldet): <span class="font-mono">{{ implode(', ', $preview['keep']) }}</span></p>
                    @endif
                    @if ($preview['unwritable'])
                        <p class="mt-4 text-amber-300">{{ $preview['unwritable'] }} Datei(en) haben ein Format, das nicht beschrieben werden kann (nur MP3 und FLAC) – die bleiben unverändert.</p>
                    @endif
                </div>
                <div class="flex items-center gap-3 border-t border-zinc-800 px-6 py-4">
                    <span class="text-xs text-zinc-500">Läuft als Aktion im Hintergrund, rückgängig machbar im Verlauf.</span>
                    <button wire:click="$set('preview', null)" class="btn-ghost ml-auto">Abbrechen</button>
                    <button wire:click="confirm" class="btn-primary" @disabled(! $preview['actions'] && ! $preview['keep'])>{{ $preview['actions'] ? 'Schreiben' : 'Übernehmen' }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
