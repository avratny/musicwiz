{{-- Sterne 1–5. Mit "method" klickbar (Livewire: method(id, sterne); derselbe Stern noch mal = Bewertung entfernen) --}}
@props(['value' => null, 'method' => null, 'id' => null, 'size' => 'text-sm'])
@php
    $current = (int) round((float) $value);
    $title = $value ? 'Bewertung: '.rtrim(rtrim(number_format((float) $value, 1, ',', ''), '0'), ',').' von 5' : 'Nicht bewertet';
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center leading-none '.$size]) }} title="{{ $title }}"
      @if ($method) x-data="{ hover: 0 }" x-on:mouseleave="hover = 0" @endif>
    @for ($n = 1; $n <= 5; $n++)
        @if ($method)
            <button type="button" wire:click.stop="{{ $method }}({{ (int) $id }}, {{ $n === $current ? 0 : $n }})" x-on:mouseenter="hover = {{ $n }}"
                    class="px-px transition" x-bind:class="(hover ? hover >= {{ $n }} : {{ $current >= $n ? 'true' : 'false' }}) ? 'text-amber-400' : 'text-zinc-600 hover:text-zinc-400'"
                    title="{{ $n === $current ? 'Bewertung entfernen' : $n.' von 5' }}">★</button>
        @else
            <span class="px-px {{ $current >= $n ? 'text-amber-400' : 'text-zinc-700' }}">★</span>
        @endif
    @endfor
</span>
