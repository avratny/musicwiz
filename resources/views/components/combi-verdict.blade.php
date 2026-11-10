@props(['combi'])
{{-- Ergebnis der Kombi-Prüfung: Act / Zusammenarbeit / unklar --}}
@php
    $v = $combi['verdict'] ?? 'unclear';
    [$label, $color] = match ($v) {
        'act' => ['ein Act', 'border-emerald-500/40 text-emerald-300'],
        'collab' => ['Zusammenarbeit', 'border-amber-500/40 text-amber-300'],
        default => ['unklar', 'border-zinc-600 text-zinc-400'],
    };
@endphp
<div {{ $attributes->merge(['class' => 'flex items-start gap-1.5 text-xs']) }} title="{{ $combi['reason'] ?? '' }}">
    <span class="shrink-0 rounded border px-1.5 py-px {{ $color }}">Laut Diensten: {{ $label }}</span>
    <span class="text-zinc-500">{{ \Illuminate\Support\Str::limit($combi['reason'] ?? '', 140) }}</span>
</div>
