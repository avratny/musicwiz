{{--
    Bereich kompakt (Tabellenzeile bei Einzeltiteln): nur Lib | Blackhole – Sampler ja/nein der Kategorie bleibt.
    $current: Kategorie des Lieds · $method/$arg: Livewire-Aufruf $method($arg, Kategorie)
--}}
@php
    $area = \App\Support\Categories::LOCATIONS[$current] ?? null;
    $compilation = str_ends_with((string) $current, 'COMPILATION');
@endphp
<div class="inline-flex shrink-0 overflow-hidden rounded-md border border-zinc-700 text-[11px]"
     title="{{ $current ? 'Kategorie: '.\App\Support\Categories::LABELS[$current] : 'Keine Kategorie' }} – wohin beim Schreiben einsortiert wird">
    @foreach (['library' => 'Lib', 'blackhole' => 'Blackhole'] as $location => $label)
        <button type="button" wire:click="{{ $method }}({{ json_encode($arg) }}, {{ json_encode(\App\Support\Categories::for($location, $compilation)) }})"
                @class(['whitespace-nowrap border-l border-zinc-700 px-1.5 py-0.5 first:border-l-0',
                        'bg-accent/20 font-medium text-accent' => $area === $location,
                        'text-zinc-400 hover:bg-zinc-800 hover:text-zinc-100' => $area !== $location])>{{ $label }}</button>
    @endforeach
</div>
