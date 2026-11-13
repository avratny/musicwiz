{{-- Fazit-Zeile (Ähnlich, Kombis): Farbe nach Sicherheit – sicher grün, wahrscheinlich gelb, unklar grau. $label: Empfehlung oder null --}}
@php $tone = $label === null ? 'unklar' : $confidence; @endphp
<div @class(['flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-zinc-800/60 px-4 py-2 text-sm',
             'bg-emerald-500/5' => $tone === 'sicher', 'bg-amber-500/5' => $tone === 'wahrscheinlich'])>
    <span @class(['shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold',
                  'bg-emerald-500/20 text-emerald-300' => $tone === 'sicher',
                  'bg-amber-500/20 text-amber-300' => $tone === 'wahrscheinlich',
                  'bg-zinc-800 text-zinc-400' => $tone === 'unklar'])>
        Fazit: {{ $label ?? 'Unklar – selbst entscheiden' }}@if ($label !== null) <span class="font-normal opacity-75">· {{ $confidence }}</span>@endif
    </span>
    <span class="text-xs text-zinc-400">{{ implode(' · ', $reasons) }}</span>
</div>
