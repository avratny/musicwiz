{{--
    Sterne 1–5 (wie 0.3.x): derselbe Stern noch mal = Bewertung entfernen.
    $value: Sterne (beim Album der Durchschnitt, z. B. 3,5) · $method/$arg: Livewire-Aufruf $method($arg, Sterne)
    oder $model: Feld im Formular (Flyout) · ohne beides nur Anzeige · $large: größer (Album-Kopf, Flyout)
--}}
@php
    $method ??= null;
    $model ??= null;
    $large = (bool) ($large ?? false);
    $current = (int) round((float) ($value ?? 0));
    $title = $value ? 'Bewertung: '.rtrim(rtrim(number_format((float) $value, 1, ',', ''), '0'), ',').' von 5' : 'Nicht bewertet';
@endphp
<span @class(['inline-flex shrink-0 items-center leading-none', 'text-lg' => $large, 'text-sm' => ! $large])
      @if ($model) x-data="{ v: $wire.entangle('{{ $model }}'), hover: 0 }" @mouseleave="hover = 0" title="Bewertung"
      @elseif ($method) x-data="{ hover: 0 }" @mouseleave="hover = 0" title="{{ $title }}"
      @else title="{{ $title }}" @endif>
    @for ($n = 1; $n <= 5; $n++)
        @if ($model)
            <button type="button" @click="v = (+v === {{ $n }} ? null : {{ $n }})" @mouseenter="hover = {{ $n }}" class="px-px transition"
                    :class="(hover ? hover >= {{ $n }} : +v >= {{ $n }}) ? 'text-amber-400' : 'text-zinc-600 hover:text-zinc-400'"
                    :title="+v === {{ $n }} ? 'Bewertung entfernen' : '{{ $n }} von 5'">★</button>
        @elseif ($method)
            <button type="button" wire:click.stop="{{ $method }}({{ json_encode($arg) }}, {{ $n === $current ? 0 : $n }})" @mouseenter="hover = {{ $n }}"
                    class="px-px transition" :class="(hover ? hover >= {{ $n }} : {{ $current >= $n ? 'true' : 'false' }}) ? 'text-amber-400' : 'text-zinc-600 hover:text-zinc-400'"
                    title="{{ $n === $current ? 'Bewertung entfernen' : $n.' von 5' }}">★</button>
        @else
            <span @class(['px-px', 'text-amber-400' => $current >= $n, 'text-zinc-700' => $current < $n])>★</span>
        @endif
    @endfor
</span>
