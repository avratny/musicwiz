{{--
    Kategorie als Knopfleiste – gleich im Album-Kopf, je Titel und im Flyout.
    $current: gewählte Kategorie(n) (eine = aktiv, mehrere = gemischt) · $method/$arg: Livewire-Aufruf $method($arg, Kategorie)
    oder $model: Feld im Formular (Flyout, gespeichert wird mit „Speichern“) · $small: kompakt (Tabellenzeile)
--}}
@php
    $current = array_values(array_filter((array) ($current ?? [])));
    $small = (bool) ($small ?? false);
    $model ??= null;
@endphp
<div @if ($model) x-data="{ v: $wire.entangle('{{ $model }}') }" @endif
     @class(['inline-flex shrink-0 overflow-hidden rounded-md border border-zinc-700', 'text-[11px]' => $small, 'text-xs' => ! $small])
     title="Kategorie – wohin beim Schreiben einsortiert wird">
    @foreach (\App\Support\Categories::LABELS as $value => $label)
        @php $on = in_array($value, $current, true); @endphp
        <button type="button"
                @if ($model) @click="v = @js($value)" :class="v === @js($value) ? 'bg-accent/20 font-medium text-accent' : 'text-zinc-400 hover:bg-zinc-800 hover:text-zinc-100'"
                @else wire:click="{{ $method }}({{ json_encode($arg) }}, {{ json_encode($value) }})" @endif
                @class(['whitespace-nowrap border-l border-zinc-700 first:border-l-0', 'px-1.5 py-0.5' => $small, 'px-2.5 py-1' => ! $small,
                        'bg-accent/20 font-medium text-accent' => ! $model && $on && count($current) === 1,
                        'bg-zinc-700/60 text-zinc-200' => ! $model && $on && count($current) > 1,
                        'text-zinc-400 hover:bg-zinc-800 hover:text-zinc-100' => ! $model && ! $on])>{{ $label }}</button>
    @endforeach
</div>
