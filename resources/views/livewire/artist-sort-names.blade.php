{{-- Interpreten › Sortiernamen: offene Abweichungen klären, eigene Sortiernamen pflegen (Eindeutiges trägt MusicWiz selbst ein) --}}
@php
    $fmt = fn ($n) => number_format($n, 0, ',', '.');
@endphp
<div>
<header class="sticky top-0 z-20 border-b border-zinc-800 bg-zinc-900/95 px-6 py-3 backdrop-blur">
    <div class="flex flex-wrap items-center gap-4">
        <div class="mr-2">
            <h1 class="text-lg font-semibold leading-tight">Sortiernamen</h1>
            <p class="text-xs text-zinc-500">Artikel nach hinten („Beatles, The“), Personen nie umgedreht – Artikel: {{ $articles }} (<a href="{{ route('settings', ['tab' => 'schreibweisen']) }}" wire:navigate class="underline">ändern</a>)</p>
        </div>
        <div class="relative min-w-60 flex-1">
            <svg class="pointer-events-none absolute left-2.5 top-2 size-4 text-zinc-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" wire:model.live.debounce.250ms="query" placeholder="Interpret suchen – auch um einen eigenen Sortiernamen zu setzen …" class="field pl-8">
        </div>
    </div>
</header>

<div class="max-w-5xl space-y-6 px-6 py-5">
    @if ($message)
        <div class="rounded-md bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300">{{ $message }}</div>
    @endif

    <section>
        <h2 class="mb-1 text-sm font-semibold uppercase tracking-wide text-zinc-400">Offen ({{ $fmt(count($open)) }})</h2>
        <p class="mb-3 text-xs text-zinc-500">In der Datei steht ein Sortiername, der nicht zur Regel passt und nicht eindeutig ist – Fehlendes und nur umgestellte Namen („Jürgens, Udo“) trägt MusicWiz selbst ein.</p>
        <div class="space-y-2">
            @forelse ($open as $id => $e)
                <div wire:key="open-{{ $id }}" class="flex flex-wrap items-center gap-3 rounded-lg border border-zinc-800 bg-zinc-900 px-4 py-2.5">
                    <div class="min-w-48 flex-1">
                        <div class="font-medium">{{ $e['artist']->name }}</div>
                        <div class="text-xs text-zinc-500">{{ $e['tracks'] }} {{ $e['tracks'] === 1 ? 'Lied' : 'Lieder' }} · laut Regel: {{ $e['expected'] === $e['artist']->name ? 'kein Sortiername nötig' : '„'.$e['expected'].'“' }}</div>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5">
                        @foreach ($e['values'] as $value => $n)
                            @if ($e['single'] && $value !== '')
                                <button class="btn py-0.5 text-xs" wire:click="keep({{ $id }}, @js($value))" title="Als eigenen Sortiernamen für {{ $e['artist']->name }} behalten">„{{ $value }}“ behalten <span class="text-zinc-500">· {{ $n }}</span></button>
                            @else
                                <span class="rounded-md border border-zinc-800 px-2 py-0.5 text-xs text-zinc-400">„{{ $value }}“ · {{ $n }}</span>
                            @endif
                        @endforeach
                        <button class="btn-primary py-0.5 text-xs" wire:click="takeRule({{ $id }})">Regel übernehmen</button>
                    </div>
                </div>
            @empty
                <p class="text-sm text-zinc-500">Nichts offen.</p>
            @endforelse
        </div>
    </section>

    <section>
        <h2 class="mb-1 text-sm font-semibold uppercase tracking-wide text-zinc-400">{{ $query ? 'Gefundene Interpreten' : 'Interpreten mit Sortiername' }} ({{ $fmt($total) }})</h2>
        <p class="mb-3 text-xs text-zinc-500">Eigener Sortiername gilt vor der Regel – leer lassen und speichern = wieder nach der Regel. Geänderte Sortiernamen trägt MusicWiz in die Lieder ein.</p>
        <table class="w-full text-sm">
            <tbody>
                @forelse ($named as $a)
                    @php $sort = $sorts->forName($a->name); @endphp
                    <tr wire:key="named-{{ $a->id }}" class="hover:bg-zinc-900">
                        <td class="w-1/3 border-b border-zinc-800/70 py-1.5 pr-3 font-medium">{{ $a->name }}</td>
                        <td class="border-b border-zinc-800/70 py-1.5 pr-3">
                            <form wire:submit="setOwn({{ $a->id }})" class="flex items-center gap-2">
                                <input type="text" wire:model="own.{{ $a->id }}" placeholder="{{ $sort }}" class="field py-1 text-sm">
                                <button type="submit" class="btn shrink-0 py-0.5 text-xs">Speichern</button>
                            </form>
                        </td>
                        <td class="w-36 border-b border-zinc-800/70 py-1.5 text-right text-xs">
                            @if ($a->sort_name !== null)
                                <span class="rounded bg-accent/15 px-1.5 py-0.5 text-accent">eigener</span>
                                <button class="ml-1 text-zinc-500 hover:text-zinc-200" wire:click="resetOwn({{ $a->id }})" title="Zurück zur Regel">↺</button>
                            @elseif ($sort !== $a->name)
                                <span class="text-zinc-500">Regel</span>
                            @else
                                <span class="text-zinc-600">keiner nötig</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-10 text-center text-zinc-500">{{ $query ? 'Nichts gefunden.' : 'Noch kein Interpret mit Sortiername.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
        @if ($total > $named->count())
            <div class="py-4 text-center"><button class="btn" wire:click="more">Mehr laden</button></div>
        @endif
    </section>
</div>
</div>
